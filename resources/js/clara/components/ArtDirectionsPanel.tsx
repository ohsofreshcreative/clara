import { useEffect, useState } from '@wordpress/element';
import { errorMessage, fieldErrors, generateArtDirections, getLlmStatus, listArtDirections, type ArtDirection, type ArtDirectionRun, type Layout, type Section } from '../api';
import { Button, Notice, TextAreaField } from './Field';
import ImagesPanel from './ImagesPanel';

const img = 'absolute rounded bg-accent-600/50';
const txt = 'absolute rounded bg-white/70';

/** Schemat kompozycji — pomoc wizualna; pełny opis jest w tekście karty. */
const wireframes: Record<Layout, string[][]> = {
  split: [[txt, 'left-[6%] top-[30%] h-[12%] w-[38%]'], [txt, 'left-[6%] top-[48%] h-[6%] w-[30%]'], [txt, 'left-[6%] top-[62%] h-[10%] w-[16%]'], [img, 'right-0 top-0 h-full w-[48%]']],
  'full-bleed': [[img, 'inset-0'], ['absolute rounded bg-ink-950/60', 'left-[25%] top-[25%] h-1/2 w-1/2'], [txt, 'left-[32%] top-[36%] h-[10%] w-[36%]'], [txt, 'left-[40%] top-[56%] h-[8%] w-[20%]']],
  'centered-type': [[txt, 'left-[15%] top-[12%] h-[20%] w-[70%]'], [txt, 'left-[30%] top-[38%] h-[6%] w-[40%]'], [img, 'left-[40%] top-[56%] h-[34%] w-[20%]']],
  'asymmetric-collage': [[img, 'left-[45%] top-[6%] h-[55%] w-[30%]'], [img, 'right-[4%] top-[30%] h-[60%] w-[18%]'], [img, 'left-[30%] top-[65%] h-[30%] w-[22%]'], [txt, 'left-[5%] top-[20%] h-[12%] w-[34%]'], [txt, 'left-[5%] top-[38%] h-[6%] w-[24%]']],
  'editorial-grid': [[img, 'left-[4%] top-[8%] h-[84%] w-[28%]'], [txt, 'left-[36%] top-[8%] h-[14%] w-[60%]'], [img, 'left-[36%] top-[30%] h-[62%] w-[28%]'], [txt, 'left-[68%] top-[30%] h-[30%] w-[28%]']],
  'framed-card': [[img, 'inset-0'], ['absolute rounded bg-ink-900', 'left-[8%] top-[18%] h-[64%] w-[40%]'], [txt, 'left-[12%] top-[28%] h-[12%] w-[30%]'], [txt, 'left-[12%] top-[48%] h-[6%] w-[24%]']],
};

function DirectionCard({ d, layoutLabel }: { d: ArtDirection; layoutLabel: string }) {
  return (
    <article className="flex min-w-0 flex-col gap-3 rounded-2xl border border-line bg-ink-850 p-4" aria-labelledby={`ad-${d.id}`}>
      <div className="relative aspect-video overflow-hidden rounded-xl border border-line bg-ink-950" aria-hidden="true">
        {wireframes[d.composition.layout].map(([base, pos], i) => <span key={i} className={`${base} ${pos}`} />)}
      </div>
      <h4 id={`ad-${d.id}`} className="font-semibold"><span className="text-accent-300">{d.key}.</span> {d.title}</h4>
      <p className="text-sm text-muted">Kompozycja: {layoutLabel} · fokus: {d.composition.focalPoint} · tekst: {d.composition.textAlign}</p>
      <p className="text-sm">{d.concept}</p>
      <p className="text-sm text-muted">{d.composition.description}</p>
      <div className="rounded-xl border border-line p-3">
        <p className="font-semibold">{d.copy.headline}</p>
        {d.copy.subheadline && <p className="text-sm text-muted">{d.copy.subheadline}</p>}
        <p className="mt-1 text-sm text-accent-300">CTA: {d.copy.cta}</p>
      </div>
      <ul className="flex flex-wrap gap-2" aria-label="Paleta">
        {d.palette.map((c) => (
          <li key={c.name} className="flex items-center gap-1.5 text-xs text-muted">
            <span aria-hidden="true" className="size-4 rounded border border-line" style={{ background: c.value }} />
            {c.name} {c.role && `(${c.role})`}
          </li>
        ))}
      </ul>
      <p className="text-sm"><span className="text-muted">Typografia:</span> {d.typography}</p>
      <p className="text-sm"><span className="text-muted">Obraz:</span> {d.imagery}</p>
      <div>
        <p className="text-sm text-muted">Wymagane treści</p>
        <ul className="text-sm" aria-label={`Wymagane treści — kierunek ${d.key}`}>
          {d.requiredContent.map((r) => <li key={r.item}>✓ {r.item} <span className="text-subtle">— {r.placement}</span></li>)}
        </ul>
      </div>
      {d.hardConstraints.length > 0 && (
        <div>
          <p className="text-sm text-muted">Hard constraints</p>
          <ul className="text-sm">{d.hardConstraints.map((h) => <li key={h.constraint}>✓ {h.constraint} <span className="text-subtle">— {h.how}</span></li>)}</ul>
        </div>
      )}
      {d.softConstraints.length > 0 && (
        <div>
          <p className="text-sm text-muted">Soft constraints</p>
          <ul className="text-sm">{d.softConstraints.map((s) => <li key={s.constraint}>{s.applied ? '✓' : '–'} {s.constraint}{s.note && <span className="text-subtle"> — {s.note}</span>}</li>)}</ul>
        </div>
      )}
    </article>
  );
}

export default function ArtDirectionsPanel({ section }: { section: Section }) {
  const [runs, setRuns] = useState<ArtDirectionRun[]>([]);
  const [selected, setSelected] = useState(0);
  const [prompt, setPrompt] = useState('');
  const [llm, setLlm] = useState<{ configured: boolean; provider: string | null; model: string | null; layouts: Record<Layout, string> } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [promptError, setPromptError] = useState<string | undefined>();

  useEffect(() => {
    listArtDirections(section.id).then(setRuns).catch((e: unknown) => setError(errorMessage(e)));
    getLlmStatus().then(setLlm).catch(() => setLlm(null));
  }, [section.id]);

  const generate = async () => {
    setBusy(true);
    setError(null);
    setPromptError(undefined);

    try {
      const run = await generateArtDirections(section.id, prompt);
      setRuns([run, ...runs]);
      setSelected(0);
    } catch (e) {
      setPromptError(fieldErrors(e).prompt);
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  const run = runs[selected];
  const prefix = `ad-${section.id}`;

  return (
    <section aria-labelledby={`${prefix}-title`} className="flex flex-col gap-4 border-t border-line p-5">
      <div>
        <h3 id={`${prefix}-title`} className="text-lg font-semibold">Kierunki artystyczne</h3>
        <p className="text-sm text-muted">
          {llm?.configured
            ? `Interpretacja briefu przez AI (${llm.provider} · ${llm.model}); przy błędzie lub niezgodnej odpowiedzi — szablon deterministyczny.`
            : 'Dostawca AI nie jest skonfigurowany — kierunki powstaną z szablonu deterministycznego.'}
        </p>
      </div>
      <TextAreaField id={`${prefix}-prompt`} label="Dodatkowe wskazówki (opcjonalnie)" value={prompt} onChange={setPrompt} rows={2} maxLength={2000} error={promptError} />
      <div className="flex flex-wrap items-center gap-3">
        <Button onClick={generate} disabled={busy || section.status === 'archived'}>{busy ? 'Generowanie…' : 'Wygeneruj 4 kierunki'}</Button>
        {section.status === 'archived' && <span className="text-sm text-muted">Sekcja zarchiwizowana.</span>}
        {runs.length > 1 && (
          <label className="flex items-center gap-2 text-sm text-muted">
            Wersja
            <select value={selected} onChange={(e) => setSelected(Number(e.target.value))} className="rounded-lg border border-line bg-ink-850 px-3 py-2 text-white">
              {runs.map((r, i) => <option key={r.runId} value={i}>{new Date(r.created_at).toLocaleString('pl-PL')} · {r.source === 'llm' ? 'AI' : 'szablon'}</option>)}
            </select>
          </label>
        )}
      </div>
      {error && <Notice type="error">{error}</Notice>}

      {run && (
        <div className="flex flex-col gap-4" data-testid="art-run">
          <div className="flex flex-col gap-1 rounded-xl border border-line p-4">
            <p className="text-sm">
              <span className={`mr-2 rounded-full px-2 py-0.5 text-xs ${run.source === 'llm' ? 'bg-accent-600 text-white' : 'border border-line text-muted'}`}>
                {run.source === 'llm' ? `AI · ${run.model}` : 'Szablon deterministyczny'}
              </span>
              {run.context.designSystem ? `Design system v${run.context.designSystem.version}` : 'Bez design systemu'}
              {run.cost !== null && ` · koszt ≈ $${run.cost.toFixed(4)}`}
            </p>
            {run.fallbackReason && (
              <p className="text-sm text-muted" data-testid="fallback-reason">
                Powód użycia szablonu: {run.fallbackReason.message}
                {run.fallbackReason.errors && Object.keys(run.fallbackReason.errors).length > 0 && ` (${Object.entries(run.fallbackReason.errors).slice(0, 3).map(([k, v]) => `${k}: ${v}`).join('; ')})`}
              </p>
            )}
            <p className="text-sm"><span className="text-muted">Interpretacja:</span> {run.interpretation.summary}</p>
          </div>
          <ImagesPanel sectionId={section.id} runId={run.runId} disabled={section.status === 'archived'} />
          <div className="grid gap-4 lg:grid-cols-2">
            {run.directions.map((d) => <DirectionCard key={d.id} d={d} layoutLabel={llm?.layouts[d.composition.layout] ?? d.composition.layout} />)}
          </div>
        </div>
      )}
    </section>
  );
}
