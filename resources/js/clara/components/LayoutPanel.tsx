import { useEffect, useState } from '@wordpress/element';
import { analyzeLayout, errorMessage, fieldErrors, getLayout, listLayouts, saveLayout, validateLayout, type GalleryItem, type LayoutDocument } from '../api';
import { Button, Notice } from './Field';
import FigmaExport from './FigmaExport';
import LayoutPreview from './LayoutPreview';

const analyzerLabel = (doc: LayoutDocument) => ({
  heuristic: 'Rekonstrukcja heurystyczna (bez modelu wizyjnego)',
  'anthropic-vision': `Model wizyjny · ${doc.validation.model ?? ''}`,
  manual: 'Ręczna poprawka',
})[doc.validation.analyzer] ?? doc.validation.analyzer;

/**
 * Layout JSON wybranego wariantu: budowa (analiza obrazu), wersje, porównanie obrazu z podglądem
 * HTML/CSS (obok siebie lub nakładka), lista warstw z pewnością i bitmapami, edycja JSON z walidacją.
 */
export default function LayoutPanel({ item }: { item: GalleryItem }) {
  const [versions, setVersions] = useState<LayoutDocument[]>([]);
  const [doc, setDoc] = useState<LayoutDocument | null>(null);
  const [mode, setMode] = useState<'side' | 'overlay'>('side');
  const [opacity, setOpacity] = useState(0.5);
  const [json, setJson] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<{ type: 'error' | 'success'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const open = async (id: number) => {
    const full = await getLayout(id);
    setDoc(full);
    setJson(JSON.stringify(full.layout, null, 2));
    setErrors({});
  };

  useEffect(() => {
    setDoc(null);
    setMsg(null);
    listLayouts(item.id)
      .then((list) => { setVersions(list); if (list[0]) return open(list[0].id); })
      .catch((e: unknown) => setMsg({ type: 'error', text: errorMessage(e) }));
  }, [item.id]);

  const run = async (action: () => Promise<LayoutDocument>, success: string) => {
    setBusy(true);
    setMsg(null);
    setErrors({});
    try {
      const created = await action();
      setVersions([{ ...created, layout: undefined }, ...versions]);
      setDoc(created);
      setJson(JSON.stringify(created.layout, null, 2));
      setMsg({ type: 'success', text: success });
    } catch (e) {
      setErrors(fieldErrors(e));
      setMsg({ type: 'error', text: errorMessage(e) });
    } finally {
      setBusy(false);
    }
  };

  const parsed = () => {
    try {
      return JSON.parse(json) as unknown;
    } catch {
      setErrors({ layout: 'Niepoprawny JSON — sprawdź składnię.' });
      setMsg({ type: 'error', text: 'Niepoprawny JSON.' });
      return undefined;
    }
  };

  const check = async () => {
    const layout = parsed();
    if (layout === undefined) return;
    const res = await validateLayout(layout);
    setErrors(res.errors);
    setMsg(res.valid ? { type: 'success', text: 'Layout JSON jest poprawny.' } : { type: 'error', text: `Walidator odrzucił layout (${Object.keys(res.errors).length} błędów).` });
  };

  const save = () => {
    const layout = parsed();
    if (layout !== undefined) run(() => saveLayout(item.id, layout, doc?.id), 'Zapisano poprawkę jako nową wersję — poprzednie wersje zostają.');
  };

  const download = () => {
    const url = URL.createObjectURL(new Blob([json], { type: 'application/json' }));
    const a = Object.assign(document.createElement('a'), { href: url, download: `clara-layout-${item.id}-v${doc?.id ?? 0}.json` });
    a.click();
    URL.revokeObjectURL(url);
  };

  const layout = doc?.layout;
  const s = doc?.validation.summary;

  return (
    <section className="flex flex-col gap-4 rounded-xl border border-line p-4" aria-labelledby={`layout-${item.id}`} data-testid="layout-panel">
      <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div className="min-w-0">
          <h4 id={`layout-${item.id}`} className="font-semibold">Layout JSON — wariant {item.directionKey} · v{item.version}</h4>
          <p className="text-sm text-muted">Rekonstrukcja best-effort (nie pixel-perfect): teksty jako tekst, zdjęcia i złożone efekty jako bitmapy.</p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          {versions.length > 0 && (
            <select aria-label="Wersja layoutu" value={doc?.id ?? ''} onChange={(e) => open(Number(e.target.value))} className="rounded-xl border border-line bg-ink-950 px-3 py-2 text-sm">
              {versions.map((v, i) => <option key={v.id} value={v.id}>Wersja {versions.length - i} · {v.validation.origin === 'manual' ? 'poprawka' : 'analiza'} · {new Date(v.created_at).toLocaleString('pl-PL')}</option>)}
            </select>
          )}
          <Button disabled={busy} onClick={() => run(() => analyzeLayout(item.id), 'Zbudowano nową wersję Layout JSON.')}>{busy ? 'Analiza…' : versions.length ? 'Zbuduj ponownie' : 'Zbuduj Layout JSON'}</Button>
        </div>
      </div>

      {msg && <Notice type={msg.type}>{msg.text}</Notice>}

      {doc && layout && (
        <>
          <div className="flex flex-col gap-1 text-sm">
            <p data-testid="layout-analyzer">{analyzerLabel(doc)}{doc.validation.cost ? ` · koszt $${doc.validation.cost.toFixed(4)}` : ''}</p>
            {doc.validation.fallbackReason && <p className="text-amber-200" data-testid="layout-fallback">Model wizyjny niedostępny lub wynik odrzucony: {doc.validation.fallbackReason.message} Użyto rekonstrukcji heurystycznej.</p>}
            {s && (
              <p className="text-muted" data-testid="layout-summary">
                {s.nodes} warstw · {s.textNodes} tekstowych · {s.editableNodes} edytowalnych · {s.rasterizedNodes} bitmap · średnia pewność {s.avgConfidence !== null ? `${Math.round(s.avgConfidence * 100)}%` : '—'}
                {s.lowConfidence > 0 && ` · ${s.lowConfidence} o niskiej pewności`}
              </p>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-4" role="group" aria-label="Tryb porównania">
            <label className="flex items-center gap-2 text-sm"><input type="radio" name={`cmp-${item.id}`} checked={mode === 'side'} onChange={() => setMode('side')} className="accent-accent-500" /> Obok siebie</label>
            <label className="flex items-center gap-2 text-sm"><input type="radio" name={`cmp-${item.id}`} checked={mode === 'overlay'} onChange={() => setMode('overlay')} className="accent-accent-500" /> Nakładka</label>
            {mode === 'overlay' && (
              <label className="flex items-center gap-2 text-sm text-muted">
                Przezroczystość podglądu
                <input type="range" min={0} max={1} step={0.05} value={opacity} onChange={(e) => setOpacity(Number(e.target.value))} className="accent-accent-500" />
              </label>
            )}
          </div>

          {mode === 'side' ? (
            <div className="grid gap-4 md:grid-cols-2" data-testid="layout-compare">
              <figure className="flex min-w-0 flex-col gap-2">
                <img src={item.image?.url} alt={`Obraz źródłowy wariantu ${item.directionKey}`} className="w-full rounded-xl border border-line" />
                <figcaption className="text-xs text-muted">Obraz źródłowy (AI{item.mock ? ', mock' : ''})</figcaption>
              </figure>
              <figure className="flex min-w-0 flex-col gap-2">
                <div className="overflow-hidden rounded-xl border border-line"><LayoutPreview layout={layout} assets={doc.assets} /></div>
                <figcaption className="text-xs text-muted">Podgląd HTML/CSS z Layout JSON</figcaption>
              </figure>
            </div>
          ) : (
            <div className="relative overflow-hidden rounded-xl border border-line" data-testid="layout-overlay">
              <img src={item.image?.url} alt={`Obraz źródłowy wariantu ${item.directionKey}`} className="block w-full" style={{ aspectRatio: `${layout.canvas.width} / ${layout.canvas.height}` }} />
              <div className="absolute inset-0" style={{ opacity }}><LayoutPreview layout={layout} assets={doc.assets} /></div>
            </div>
          )}

          {layout.warnings.length > 0 && (
            <ul className="list-disc pl-5 text-sm text-muted" aria-label="Ostrzeżenia">
              {layout.warnings.map((w) => <li key={w}>{w}</li>)}
            </ul>
          )}

          <details className="rounded-xl border border-line">
            <summary className="cursor-pointer px-4 py-3 text-sm">Warstwy ({layout.nodes.length})</summary>
            <div className="overflow-x-auto px-4 pb-4">
              <table className="w-full text-left text-sm" data-testid="layout-nodes">
                <thead className="text-muted"><tr><th className="py-1 pr-3">Warstwa</th><th className="pr-3">Typ</th><th className="pr-3">Pewność</th><th>Bitmapa</th></tr></thead>
                <tbody>
                  {layout.nodes.map((n) => (
                    <tr key={n.id} className="border-t border-line">
                      <td className="py-1 pr-3">{n.name ?? n.id}{n.content ? `: „${n.content.slice(0, 40)}”` : ''}</td>
                      <td className="pr-3">{n.type}</td>
                      <td className={`pr-3 ${n.confidence < 0.5 ? 'text-amber-200' : ''}`}>{Math.round(n.confidence * 100)}%</td>
                      <td>{n.rasterized ? layout.rasterizedRegions.find((r) => r.nodeId === n.id)?.reason ?? 'tak' : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </details>

          <details className="rounded-xl border border-line">
            <summary className="cursor-pointer px-4 py-3 text-sm">Edytuj Layout JSON</summary>
            <div className="flex flex-col gap-3 px-4 pb-4">
              <label htmlFor={`layout-json-${item.id}`} className="sr-only">Layout JSON</label>
              <textarea id={`layout-json-${item.id}`} value={json} onChange={(e) => setJson(e.target.value)} rows={14} spellCheck={false}
                className="w-full rounded-xl border border-line bg-ink-950 p-3 font-mono text-xs" />
              {Object.keys(errors).length > 0 && (
                <ul className="max-h-48 overflow-auto rounded-xl border border-red-500/40 p-3 text-sm text-red-200" data-testid="layout-errors">
                  {Object.entries(errors).map(([path, text]) => <li key={path}><code>{path}</code>: {text}</li>)}
                </ul>
              )}
              <div className="flex flex-wrap gap-3">
                <Button variant="ghost" onClick={check}>Sprawdź</Button>
                <Button disabled={busy} onClick={save}>Zapisz jako nową wersję</Button>
                <Button variant="ghost" onClick={download}>Pobierz JSON</Button>
              </div>
            </div>
          </details>
          <FigmaExport layoutId={doc.id} />
        </>
      )}
    </section>
  );
}
