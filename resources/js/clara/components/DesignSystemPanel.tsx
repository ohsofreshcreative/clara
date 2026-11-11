import { useEffect, useState } from '@wordpress/element';
import type { ChangeEvent } from 'react';
import {
  errorMessage,
  fieldErrors,
  getFigmaStatus,
  getLatestDesignSystem,
  importDesignSystem,
  ingestFigma,
  listDesignSystemVersions,
  removeScreen,
  restoreDesignSystem,
  saveDesignSystem,
  uploadScreen,
  type DesignSystem,
  type DesignSystemSource,
  type DesignSystemVersion,
  type Tokens,
} from '../api';
import { Button, Notice } from './Field';

type Group = keyof Tokens;
type Cell = string | number | null;
type Row = Record<string, Cell>;

const columns: Record<Group, { key: string; label: string; type: 'text' | 'number' | 'color' }[]> = {
  colors: [{ key: 'name', label: 'Nazwa', type: 'text' }, { key: 'value', label: 'Kolor (#hex)', type: 'color' }],
  typography: [
    { key: 'name', label: 'Nazwa', type: 'text' },
    { key: 'fontFamily', label: 'Font', type: 'text' },
    { key: 'fontSize', label: 'Rozmiar px', type: 'number' },
    { key: 'fontWeight', label: 'Grubość', type: 'number' },
    { key: 'lineHeight', label: 'Interlinia px', type: 'number' },
  ],
  spacing: [{ key: 'name', label: 'Nazwa', type: 'text' }, { key: 'value', label: 'Wartość px', type: 'number' }],
  radii: [{ key: 'name', label: 'Nazwa', type: 'text' }, { key: 'value', label: 'Wartość px', type: 'number' }],
  shadows: [{ key: 'name', label: 'Nazwa', type: 'text' }, { key: 'value', label: 'box-shadow', type: 'text' }],
};

const groupLabels: Record<Group, string> = { colors: 'Kolory', typography: 'Typografia', spacing: 'Odstępy', radii: 'Zaokrąglenia', shadows: 'Cienie' };

const emptyRow = (group: Group): Row => Object.fromEntries(columns[group].map((c) => [c.key, c.key === 'fontWeight' ? 400 : '']));

export const sourceLabels: Record<DesignSystemSource['type'], string> = {
  manual: 'Ręcznie',
  json_import: 'Import JSON',
  figma_rest: 'Figma (style REST)',
  reference_screens: 'Screeny referencyjne',
  restore: 'Przywrócenie',
};

const emptyTokens = (): Tokens => ({ colors: [], typography: [], spacing: [], radii: [], shadows: [] });

function toPayload(draft: Record<Group, Row[]>): Tokens {
  const out = emptyTokens() as unknown as Record<Group, Row[]>;

  (Object.keys(columns) as Group[]).forEach((group) => {
    out[group] = draft[group].map((row) =>
      Object.fromEntries(columns[group].map((c) => [c.key, c.type === 'number' ? (row[c.key] === '' || row[c.key] === null ? null : Number(row[c.key])) : String(row[c.key] ?? '')])),
    );
  });

  return out as unknown as Tokens;
}

export default function DesignSystemPanel({ projectId, hasFigmaUrl }: { projectId: number; hasFigmaUrl: boolean }) {
  const [current, setCurrent] = useState<DesignSystem | null>(null);
  const [draft, setDraft] = useState<Record<Group, Row[]>>(emptyTokens() as unknown as Record<Group, Row[]>);
  const [versions, setVersions] = useState<DesignSystemVersion[]>([]);
  const [figmaReady, setFigmaReady] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<{ type: 'error' | 'success'; text: string; details?: string[] } | null>(null);
  const [busy, setBusy] = useState(false);

  const load = (ds: DesignSystem | null) => {
    setCurrent(ds);
    setDraft((ds?.tokens ?? emptyTokens()) as unknown as Record<Group, Row[]>);
    listDesignSystemVersions(projectId).then(setVersions).catch(() => setVersions([]));
  };

  useEffect(() => {
    getLatestDesignSystem(projectId).then(load).catch((e: unknown) => setMsg({ type: 'error', text: errorMessage(e) }));
    getFigmaStatus().then((s) => setFigmaReady(s.configured)).catch(() => setFigmaReady(false));
  }, [projectId]);

  const run = async (action: () => Promise<DesignSystem>, success: (ds: DesignSystem) => string) => {
    setBusy(true);
    setErrors({});
    setMsg(null);

    try {
      const ds = await action();
      load(ds);
      setMsg({ type: 'success', text: success(ds), details: ds.source.warnings });
    } catch (e) {
      const fields = fieldErrors(e);
      setErrors(fields);
      setMsg({ type: 'error', text: errorMessage(e), details: Object.entries(fields).map(([k, v]) => `${k}: ${v}`) });
    } finally {
      setBusy(false);
    }
  };

  const update = (group: Group, index: number, key: string, value: Cell) =>
    setDraft({ ...draft, [group]: draft[group].map((row, i) => (i === index ? { ...row, [key]: value } : row)) });

  const onImport = async (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    if (file.size > 1024 * 1024) {
      setMsg({ type: 'error', text: 'Plik JSON jest za duży (limit 1 MB).' });
      return;
    }

    const content = await file.text();
    run(() => importDesignSystem(projectId, content, file.name), (ds) => `Zaimportowano „${file.name}” jako v${ds.version}.`);
  };

  const onScreen = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (file) run(() => uploadScreen(projectId, file), (ds) => `Dodano screen — v${ds.version}.`);
  };

  return (
    <section aria-labelledby="clara-ds-title" className="flex flex-col gap-6 glass rounded-3xl border p-5 sm:p-8">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-baseline sm:justify-between">
        <h2 id="clara-ds-title" className="text-xl font-semibold">Design system</h2>
        <p className="text-sm text-muted" data-testid="ds-current">
          {current ? `Wersja v${current.version} · ${sourceLabels[current.source.type]} · ${new Date(current.created_at).toLocaleString('pl-PL')}` : 'Brak wersji — dodaj tokeny ręcznie, zaimportuj JSON lub pobierz style z Figmy.'}
        </p>
      </div>

      <div className="flex flex-col gap-3 rounded-2xl border border-line p-4">
        <h3 className="font-semibold">Źródła</h3>
        <div className="flex flex-wrap items-center gap-3">
          <Button variant="ghost" disabled={busy || !figmaReady || !hasFigmaUrl} onClick={() => run(() => ingestFigma(projectId), (ds) => `Pobrano style z Figmy — v${ds.version}.`)}>
            Pobierz style z Figmy
          </Button>
          <label htmlFor="ds-import" className="inline-flex cursor-pointer items-center rounded-xl border border-line px-5 py-2.5 text-muted hover:border-accent-500 hover:text-white has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-accent-300">
            Importuj JSON
            <input id="ds-import" type="file" accept=".json,application/json" onChange={onImport} disabled={busy} className="sr-only" />
          </label>
        </div>
        <p className="text-sm text-subtle">
          {!figmaReady
            ? 'Figma REST nie jest skonfigurowana na serwerze (brak tokenu). '
            : !hasFigmaUrl
              ? 'Uzupełnij link do pliku Figma w danych projektu. '
              : 'Figma REST pobiera style kolorów, tekstu i cieni. '}
          Zmienne Figmy przez REST wymagają planu Enterprise — wyeksportuj je jako JSON (W3C Design Tokens) i zaimportuj.
        </p>
      </div>

      {msg && (
        <div className="flex flex-col gap-2">
          <Notice type={msg.type}>{msg.text}</Notice>
          {msg.details && msg.details.length > 0 && (
            <ul className={`list-inside list-disc text-sm ${msg.type === 'error' ? 'text-red-200' : 'text-muted'}`} aria-label={msg.type === 'error' ? 'Szczegóły błędów' : 'Ostrzeżenia'}>
              {msg.details.slice(0, 20).map((d) => <li key={d}>{d}</li>)}
            </ul>
          )}
        </div>
      )}

      {(Object.keys(columns) as Group[]).map((group) => (
        <fieldset key={group} className="flex flex-col gap-2">
          <legend className="mb-2 font-semibold">{groupLabels[group]} <span className="text-sm text-subtle">({draft[group].length})</span></legend>
          {draft[group].map((row, i) => (
            <div key={i} className="flex flex-wrap items-start gap-2 rounded-xl border border-line p-2">
              {columns[group].map((col) => {
                const error = errors[`${group}[${i}].${col.key}`];
                const label = `${groupLabels[group]} ${i + 1}: ${col.label}`;
                return (
                  <div key={col.key} className={`flex min-w-0 flex-col ${col.key === 'name' || col.type === 'color' || group === 'shadows' ? 'flex-[2_1_10rem]' : 'flex-[1_1_6rem]'}`}>
                    <div className="flex items-center gap-2">
                      {col.type === 'color' && (
                        <span aria-hidden="true" className="size-8 shrink-0 rounded-lg border border-line" style={{ background: String(row[col.key] || 'transparent') }} />
                      )}
                      <input
                        aria-label={label}
                        type={col.type === 'number' ? 'number' : 'text'}
                        value={row[col.key] ?? ''}
                        placeholder={col.label}
                        onChange={(e) => update(group, i, col.key, e.target.value)}
                        aria-invalid={Boolean(error)}
                        className="w-full min-w-0 rounded-lg border border-line bg-ink-850 px-3 py-2 text-white placeholder:text-subtle aria-[invalid=true]:border-red-400"
                      />
                    </div>
                    {error && <p className="mt-1 text-xs text-red-300">{error}</p>}
                  </div>
                );
              })}
              <Button variant="ghost" className="!px-3" aria-label={`Usuń: ${groupLabels[group]} ${i + 1}`} onClick={() => setDraft({ ...draft, [group]: draft[group].filter((_, j) => j !== i) })}>
                ✕
              </Button>
            </div>
          ))}
          <div>
            <Button variant="ghost" onClick={() => setDraft({ ...draft, [group]: [...draft[group], emptyRow(group)] })}>+ Dodaj: {groupLabels[group].toLowerCase()}</Button>
          </div>
        </fieldset>
      ))}

      <div>
        <Button disabled={busy} onClick={() => run(() => saveDesignSystem(projectId, toPayload(draft)), (ds) => `Zapisano tokeny jako v${ds.version}.`)}>
          Zapisz jako nową wersję
        </Button>
      </div>

      <div className="flex flex-col gap-3">
        <h3 className="font-semibold">Screeny referencyjne</h3>
        <p className="text-sm text-muted">Screeny i frame’y z Figmy trafiają do AI jako wzór stylu przy generowaniu inspiracji (do 3 na raz).</p>
        {current && current.assets.length > 0 && (
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {current.assets.map((asset) => (
              <li key={asset.id} className="flex flex-col gap-2">
                <a href={asset.url} target="_blank" rel="noopener noreferrer" className="block overflow-hidden rounded-xl border border-line">
                  <img src={asset.url} alt={`Screen referencyjny: ${asset.name}`} className="aspect-video w-full object-cover" />
                </a>
                {asset.role === 'figma_frame' && <span className="text-xs text-muted">Frame z Figmy · {String((asset as { frameName?: string }).frameName ?? asset.name)}</span>}
                <Button variant="ghost" onClick={() => run(() => removeScreen(projectId, asset.id), (ds) => `Usunięto screen — v${ds.version}.`)}>Usuń</Button>
              </li>
            ))}
          </ul>
        )}
        <label htmlFor="ds-screen" className="text-sm text-muted">Dodaj screen (JPG, PNG, WebP do 10 MB)</label>
        <input id="ds-screen" type="file" accept=".jpg,.jpeg,.png,.webp" onChange={onScreen} disabled={busy} className="max-w-full text-sm text-muted file:mr-4 file:rounded-xl file:border-0 file:bg-accent-600 file:px-4 file:py-2 file:text-white" />
      </div>

      {versions.length > 0 && (
        <div className="flex flex-col gap-3">
          <h3 className="font-semibold">Historia wersji</h3>
          <ol className="flex flex-col gap-2">
            {versions.map((v) => (
              <li key={v.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line px-4 py-3">
                <span className="min-w-0">
                  <span className="font-semibold">v{v.version}</span>{' '}
                  <span className="text-muted">· {sourceLabels[v.source.type]}{v.source.restoredFrom ? ` z v${v.source.restoredFrom}` : ''}{v.source.filename ? ` · ${v.source.filename}` : ''}</span>
                  <span className="block text-sm text-subtle">
                    {new Date(v.created_at).toLocaleString('pl-PL')} · {v.counts.colors} kolorów, {v.counts.typography} stylów tekstu
                    {v.source.warnings?.length ? ` · ostrzeżeń: ${v.source.warnings.length}` : ''}
                  </span>
                </span>
                {current?.version !== v.version && (
                  <Button variant="ghost" disabled={busy} onClick={() => run(() => restoreDesignSystem(v.id), (ds) => `Przywrócono v${v.version} jako v${ds.version}.`)}>
                    Przywróć
                  </Button>
                )}
              </li>
            ))}
          </ol>
        </div>
      )}
    </section>
  );
}
