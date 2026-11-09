import { useEffect, useState } from '@wordpress/element';
import type { ImageItem, InspirationResult } from '../api';
import { href, navigate } from '../router';
import { Button } from './Field';
import Icon from './Icon';

const STEPS = ['Analizuję opis sekcji…', 'Szkicuję kompozycję…', 'Dobieram paletę i typografię…', 'Ustawiam światło i zdjęcia…', 'Dopracowuję detale…', 'Prawie gotowe…'];

const pending = (item: ImageItem) => item.status === 'queued' || item.status === 'running';

function useElapsed(active: boolean, since: number) {
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    if (!active) return;
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [active]);
  return Math.max(0, Math.floor((now - since) / 1000));
}

const clock = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

function LoadingTile({ item, elapsed, index }: { item: ImageItem; elapsed: number; index: number }) {
  const step = STEPS[Math.min(STEPS.length - 1, Math.floor((elapsed + index * 3) / 9))];

  return (
    <div className="relative aspect-[3/2] overflow-hidden rounded-xl border border-white/10 bg-ink-950/60" data-testid="generated-loading">
      <div className="shimmer absolute inset-0" />
      <div aria-hidden="true" className="absolute inset-0 bg-[radial-gradient(circle_at_30%_40%,color-mix(in_srgb,var(--color-accent-500)_12%,transparent),transparent_60%)]" />
      <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center">
        <span aria-hidden="true" className="size-7 rounded-full border-2 border-accent-300/15 border-t-accent-300/70 motion-safe:animate-spin [animation-duration:1.6s]" />
        <p className="font-medium">Wariant {item.directionKey} · {item.title}</p>
        <p className="text-sm text-muted">{step}</p>
      </div>
    </div>
  );
}

interface Props {
  result: InspirationResult;
  startedAt: number;
  more?: boolean;
  directionId: number | null;
  onChooseDirection: (item: ImageItem) => Promise<void>;
  onRefine: (item: ImageItem) => Promise<void>;
}

/** 4 inspiracje sekcji ładowane niezależnie; każdą można obrać jako kierunek projektu. */
export default function GeneratedInspirations({ result, startedAt, more = false, directionId, onChooseDirection, onRefine }: Props) {
  const [expanded, setExpanded] = useState<number | null>(null);
  const [choosing, setChoosing] = useState<number | null>(null);
  const items = result.batch.items;
  const done = items.filter((i) => !pending(i)).length;
  const active = done < items.length;
  const elapsed = useElapsed(active, startedAt);
  const projectPath = `projects/${result.project.id}`;

  const [refining, setRefining] = useState<number | null>(null);
  const refine = async (item: ImageItem) => {
    setRefining(item.id);
    try {
      await onRefine(item);
    } finally {
      setRefining(null);
    }
  };

  const choose = async (item: ImageItem) => {
    setChoosing(item.id);
    try {
      await onChooseDirection(item);
    } finally {
      setChoosing(null);
    }
  };

  return (
    <section aria-labelledby={`clara-generated-${result.batch.batchId}`} aria-busy={active} className="flex flex-col gap-6" data-testid="generated-results">
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div className="min-w-0">
            <h2 id={`clara-generated-${result.batch.batchId}`} className="text-xl font-semibold sm:text-2xl">
              {more ? `Kolejne 4 propozycje (runda ${result.round + 1})` : result.keepDirection ? '4 odmiany w kierunku projektu' : '4 inspiracje sekcji Hero'}
            </h2>
            <p className="text-sm text-muted">
              Projekt{' '}
              <a href={href(projectPath)} onClick={(e) => { e.preventDefault(); navigate(projectPath); }} className="text-accent-300 underline-offset-4 hover:underline">{result.project.name}</a>
              {' '}· {result.model} · remix, Layout JSON i eksport do Figmy znajdziesz w projekcie.
            </p>
            <p className="text-sm text-muted">Szybkie szkice — przy wybranym wariancie kliknij „Dopracuj”, aby dostać wersję w wyższej jakości.</p>
          </div>
          <p className="text-sm tabular-nums text-accent-300" role="status" data-testid="generated-progress">
            {active ? `Generowanie ${done}/${items.length} · ${clock(elapsed)}` : `Gotowe ${items.filter((i) => i.status === 'succeeded').length}/${items.length}`}
          </p>
        </div>
        <div className="h-1.5 overflow-hidden rounded-full bg-white/5" aria-hidden="true">
          <div className="h-full rounded-full bg-accent-400 shadow-glow transition-[width] duration-700" style={{ width: `${Math.max(active ? 6 : 0, (done / items.length) * 100)}%` }} />
        </div>
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        {items.map((item, index) => {
          const big = expanded === item.id;
          const isDirection = directionId === item.id;
          return (
            <article
              key={item.id}
              className={`glass flex min-w-0 flex-col gap-3 rounded-2xl border p-3 sm:p-4 ${big ? 'xl:col-span-2' : ''} ${isDirection ? 'shadow-glow' : ''}`}
              aria-labelledby={`inspiration-${item.id}`}
              data-testid="generated-item"
            >
              {pending(item) && item.preview ? (
                <div className="relative overflow-hidden rounded-xl border border-white/10" data-testid="refine-loading">
                  <img src={item.preview} alt="" className="block h-auto w-full opacity-60 blur-[2px]" />
                  <div className="shimmer absolute inset-0" />
                  <p className="absolute inset-0 flex items-center justify-center gap-3 bg-ink-950/40 text-sm" role="status">
                    <span aria-hidden="true" className="size-5 rounded-full border-2 border-accent-300/15 border-t-accent-300/70 motion-safe:animate-spin [animation-duration:1.6s]" />
                    Dopracowuję wariant {item.directionKey} w wyższej jakości…
                  </p>
                </div>
              ) : pending(item) ? (
                <LoadingTile item={item} elapsed={elapsed} index={index} />
              ) : item.image ? (
                <img
                  src={item.image.url}
                  alt={`Inspiracja sekcji — wariant ${item.directionKey} (${item.title})`}
                  width={item.image.width ?? undefined}
                  height={item.image.height ?? undefined}
                  className="animate-float-in block h-auto w-full rounded-xl border border-white/10"
                />
              ) : (
                <p className="flex aspect-[3/2] items-center justify-center rounded-xl border border-dashed border-line p-6 text-center text-sm text-muted" role="alert">
                  {item.error?.message ?? 'Nie udało się wygenerować obrazu.'}
                </p>
              )}
              <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 id={`inspiration-${item.id}`} className="font-semibold">
                  Wariant {item.directionKey} · {item.title}
                  {item.refineOf ? <span className="ml-2 text-sm font-normal text-accent-300">· dopracowany</span> : item.quality === 'low' ? <span className="ml-2 text-sm font-normal text-muted">· szkic</span> : null}
                </h3>
                {item.image && (
                  <div className="flex flex-wrap gap-2">
                    {item.quality === 'low' && (
                      <Button variant="ghost" onClick={() => refine(item)} disabled={refining !== null} data-testid="refine">
                        {refining === item.id ? 'Zlecam…' : 'Dopracuj'}
                      </Button>
                    )}
                    {isDirection ? (
                      <span className="flex items-center gap-2 rounded-xl border border-accent-400/60 px-4 py-2 text-accent-300">
                        <Icon name="sparkles" className="size-4" />Kierunek projektu
                      </span>
                    ) : (
                      <Button onClick={() => choose(item)} disabled={choosing !== null}>{choosing === item.id ? 'Zapisuję…' : 'Obierz ten kierunek'}</Button>
                    )}
                    <Button variant="ghost" onClick={() => setExpanded(big ? null : item.id)} aria-expanded={big}>{big ? 'Zmniejsz' : 'Powiększ'}</Button>
                    <a href={item.image.url} download className="rounded-xl border border-line px-4 py-2 hover:border-accent-400">Pobierz</a>
                  </div>
                )}
              </div>
            </article>
          );
        })}
      </div>
    </section>
  );
}
