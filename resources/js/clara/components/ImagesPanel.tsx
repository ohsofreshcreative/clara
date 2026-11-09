import { useEffect, useRef, useState } from '@wordpress/element';
import { IMAGES_UPDATED, cancelImageBatch, errorMessage, generateImages, getImageStatus, listImageBatches, type ImageBatch, type ImageStatusInfo } from '../api';
import { Button, Notice } from './Field';

const POLL_MS = 2000;
const active = (b?: ImageBatch) => b?.status === 'queued' || b?.status === 'running';

const badge: Record<string, string> = {
  queued: 'border border-line text-muted',
  running: 'bg-accent-600/30 text-accent-300',
  succeeded: 'bg-emerald-500/20 text-emerald-200',
  failed: 'bg-red-500/20 text-red-200',
  cancelled: 'border border-line text-subtle',
};

/** Obrazy dla zestawu kierunków artystycznych: 1 żądanie → do 4 obrazów w kolejce, z odpytywaniem statusu. */
export default function ImagesPanel({ sectionId, runId, disabled }: { sectionId: number; runId: string; disabled: boolean }) {
  const [batches, setBatches] = useState<ImageBatch[]>([]);
  const [info, setInfo] = useState<ImageStatusInfo | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const timer = useRef<number | undefined>(undefined);

  const current = batches.find((b) => b.artRunId === runId);

  const refresh = () => listImageBatches(sectionId).then(setBatches).catch((e: unknown) => setError(errorMessage(e)));

  useEffect(() => {
    refresh();
    getImageStatus().then(setInfo).catch(() => setInfo(null));
  }, [sectionId]);

  useEffect(() => {
    // Galeria wariantów odświeża się po zmianie stanu partii.
    window.dispatchEvent(new CustomEvent(IMAGES_UPDATED, { detail: { sectionId } }));
    window.clearTimeout(timer.current);
    if (active(current)) {
      timer.current = window.setTimeout(refresh, POLL_MS);
    }
    return () => window.clearTimeout(timer.current);
  }, [batches]);

  const run = async (action: () => Promise<ImageBatch>) => {
    setBusy(true);
    setError(null);
    try {
      const batch = await action();
      setBatches([batch, ...batches.filter((b) => b.batchId !== batch.batchId)]);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col gap-3 rounded-xl border border-line p-4" data-testid="images-panel">
      <div className="flex flex-col gap-1">
        <h4 className="font-semibold">Wizualizacje Hero</h4>
        <p className="text-sm text-muted">
          {info?.mock
            ? 'Generator AI nie jest skonfigurowany — powstaną obrazy poglądowe (mock) odwzorowujące kompozycję i paletę, nie wynik AI.'
            : info
              ? `Dostawca: ${info.provider} · ${info.model}${info.unitCost !== null ? ` · ok. $${info.unitCost.toFixed(2)} za obraz` : ''} · limit partii $${info.maxBatchCost.toFixed(2)}.`
              : 'Generowanie obrazów dla 4 kierunków.'}
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Button disabled={busy || disabled || active(current)} onClick={() => run(() => generateImages(sectionId, runId))}>
          {active(current) ? 'Generowanie…' : current ? 'Wygeneruj ponownie 4 obrazy' : 'Wygeneruj 4 obrazy'}
        </Button>
        {current && current.status === 'queued' && (
          <Button variant="ghost" disabled={busy} onClick={() => run(() => cancelImageBatch(sectionId, current.batchId))}>Anuluj oczekujące</Button>
        )}
        {current && (
          <p className="text-sm text-muted" role="status" aria-live="polite">
            Partia: {current.status === 'partial' ? 'częściowo gotowa' : current.items.find((i) => i.status === current.status)?.statusLabel ?? current.status}
            {current.cost !== null && ` · koszt $${current.cost.toFixed(4)}`}
            {current.mock && ' · mock'}
          </p>
        )}
      </div>
      {error && <Notice type="error">{error}</Notice>}

      {current && (
        <ul className="grid gap-3 sm:grid-cols-2">
          {current.items.map((item) => (
            <li key={item.id} className="flex min-w-0 flex-col gap-2" data-testid="image-item" data-status={item.status}>
              <div className="relative aspect-video overflow-hidden rounded-xl border border-line bg-ink-950">
                {item.image ? (
                  <a href={item.image.url} target="_blank" rel="noopener noreferrer">
                    <img src={item.image.url} alt={`Wizualizacja kierunku ${item.directionKey}: ${item.title}${current.mock ? ' (mock)' : ''}`} className="size-full object-cover" loading="lazy" />
                  </a>
                ) : (
                  <span className="absolute inset-0 grid place-items-center p-4 text-center text-sm text-muted">
                    {item.status === 'failed' ? item.error?.message ?? 'Błąd generowania.' : item.statusLabel}
                  </span>
                )}
              </div>
              <p className="flex flex-wrap items-center gap-2 text-sm">
                <span className={`rounded-full px-2 py-0.5 text-xs ${badge[item.status]}`}>{item.statusLabel}</span>
                <span className="min-w-0 truncate">{item.directionKey}. {item.title}</span>
                {item.cost !== null && <span className="text-subtle">${item.cost.toFixed(4)}{item.costSource === 'estimate' ? ' (szac.)' : ''}</span>}
              </p>
              {item.status === 'queued' && item.lastError && <p className="text-xs text-subtle">Ponowienie po błędzie: {item.lastError.message}</p>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
