import { useEffect, useRef, useState } from '@wordpress/element';
import { errorMessage, listProjects, refineInspiration, renderInspiration, setProjectDirection, startInspirations, type ImageItem, type InspirationResult, type Project } from '../api';
import Composer from '../components/Composer';
import DirectionToggle from '../components/DirectionToggle';
import GeneratedInspirations from '../components/GeneratedInspirations';
import { Button, Notice } from '../components/Field';
import QuickStarts, { type QuickStart } from '../components/QuickStarts';
import SearchResults from '../components/SearchResults';

/** Zakładka „Generowanie” projektu: inspiracje zawsze w kontekście wybranego projektu. */
export default function Generate({ projectId: fixedProject }: { projectId: number }) {
  const [prompt, setPrompt] = useState('');
  const [query, setQuery] = useState('');
  const [projects, setProjects] = useState<Project[]>([]);
  const [projectId, setProjectId] = useState(fixedProject);
  const [keepDirection, setKeepDirection] = useState(true);
  // Kolejne rundy „Generuj jeszcze” dokładane pod spodem; ostatnia wyznacza projekt, sekcję i prompt.
  const [runs, setRuns] = useState<{ result: InspirationResult; prompt: string; startedAt: number }[]>([]);
  const [starting, setStarting] = useState(false);
  const [designError, setDesignError] = useState<string | null>(null);
  const composerRef = useRef<HTMLDivElement>(null);
  const resultsRef = useRef<HTMLDivElement>(null);
  const moreRef = useRef<HTMLDivElement>(null);

  const loadProjects = () => listProjects().then(setProjects).catch(() => setProjects([]));

  useEffect(() => {
    loadProjects();
  }, []);

  const project = projects.find((p) => p.id === projectId);
  const direction = project?.brief?.direction ?? null;
  const last = runs.at(-1);
  const busy = starting || runs.some((run) => run.result.batch.items.some((i) => i.status === 'queued' || i.status === 'running'));

  const updateItem = (item: ImageItem) =>
    setRuns((current) => current.map((run) => ({ ...run, result: { ...run.result, batch: { ...run.result.batch, items: run.result.batch.items.map((i) => (i.id === item.id ? { ...i, ...item } : i)) } } })));

  const run = async (value: string, more: boolean) => {
    setQuery('');
    setStarting(true);
    setDesignError(null);
    if (!more) setRuns([]);
    window.requestAnimationFrame(() => (more ? moreRef : resultsRef).current?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    try {
      const result = await startInspirations(value, {
        projectId: (more ? last?.result.project.id : projectId) || undefined,
        keepDirection: Boolean(direction) && keepDirection,
        sectionId: more ? last?.result.section.id : undefined,
        round: more && last ? last.result.round + 1 : 0,
      });
      setRuns((current) => [...(more ? current : []), { result, prompt: value, startedAt: Date.now() }]);
      // Kolejne generowania trafiają do tego samego projektu (i mogą trzymać się jego kierunku).
      setProjectId(result.project.id);
      loadProjects();
      // Każdy wariant osobnym żądaniem — kafelki wypełniają się, gdy obraz jest gotowy.
      result.batch.items.forEach((item) => {
        renderInspiration(item.id)
          .then(updateItem)
          .catch((e) => updateItem({ ...item, status: 'failed', image: null, error: { code: 'clara_render', message: errorMessage(e) } }));
      });
    } catch (e) {
      setDesignError(errorMessage(e));
    } finally {
      setStarting(false);
    }
  };

  const onGenerate = (value: string) => run(value, false);

  const replaceItem = (id: number, next: ImageItem) =>
    setRuns((current) => current.map((r) => ({ ...r, result: { ...r.result, batch: { ...r.result.batch, items: r.result.batch.items.map((i) => (i.id === id ? next : i)) } } })));

  // „Dopracuj”: szkic zostaje widoczny pod nakładką, aż przyjdzie wersja w wyższej jakości.
  const onRefine = async (item: ImageItem) => {
    setDesignError(null);
    let current = item.id;
    try {
      const fresh = { ...(await refineInspiration(item.id)), preview: item.image?.url };
      replaceItem(current, fresh);
      current = fresh.id;
      if (fresh.status !== 'queued') return;
      const done = await renderInspiration(fresh.id);
      if (done.status === 'succeeded') {
        replaceItem(current, done);
      } else {
        replaceItem(current, item);
        setDesignError(`Nie udało się dopracować wariantu ${item.directionKey}: ${done.error?.message ?? 'spróbuj ponownie.'}`);
      }
    } catch (e) {
      replaceItem(current, item);
      setDesignError(errorMessage(e));
    }
  };

  const onChooseDirection = async (item: ImageItem) => {
    const owner = runs.find((r) => r.result.batch.items.some((i) => i.id === item.id))?.result.project.id;
    if (!owner) return;
    try {
      const { direction: chosen } = await setProjectDirection(owner, item.id);
      setProjects((list) => list.map((p) => (p.id === owner ? { ...p, brief: { ...p.brief, direction: chosen } } : p)));
      setKeepDirection(true);
    } catch (e) {
      setDesignError(errorMessage(e));
    }
  };

  const onSearch = (value: string) => {
    setRuns([]);
    setDesignError(null);
    setQuery(value);
  };

  const onPick = (start: QuickStart) => {
    setPrompt(start.prompt);
    composerRef.current?.querySelector('textarea')?.focus();
  };

  return (
    <div className="relative isolate overflow-hidden">
      <div className="mx-auto flex max-w-[1240px] flex-col gap-8 px-4 pt-10 pb-12 sm:px-8 lg:gap-12 lg:px-12">
          <div className="max-w-xl">
            <h1 className="break-words text-3xl font-semibold tracking-tight sm:text-4xl">
              {project?.name ?? 'Projekt'} <span className="text-muted">· Generowanie</span>
            </h1>
            <p className="mt-3 text-muted">Opisz sekcję po polsku — inspiracje powstają w stylu tego projektu (kolory, typografia i screeny referencyjne z zakładki Styl).</p>
          </div>

        <div className="flex flex-col gap-4 lg:-mt-2" ref={composerRef}>
          <Composer prompt={prompt} onPromptChange={setPrompt} onGenerate={onGenerate} onSearch={onSearch} busy={busy} />
          {direction && <DirectionToggle direction={direction} checked={keepDirection} onChange={setKeepDirection} />}
        </div>

        <div ref={resultsRef} className={`scroll-mt-6 ${runs.length || starting || designError || query ? '' : 'hidden'}`}>
          <div className="flex flex-col gap-10">
            {runs.map((r, index) => (
              <GeneratedInspirations
                key={r.result.batch.batchId}
                result={r.result}
                startedAt={r.startedAt}
                more={index > 0}
                directionId={r.result.project.id === projectId ? direction?.generationId ?? null : null}
                onChooseDirection={onChooseDirection}
                onRefine={onRefine}
              />
            ))}
          </div>
          <div ref={moreRef} className="scroll-mt-6">
            {starting && (
              <p role="status" className="glass mt-6 flex items-center gap-3 rounded-2xl border px-5 py-4 text-accent-300" data-testid="generated-starting">
                <span aria-hidden="true" className="size-5 rounded-full border-2 border-accent-300/30 border-t-accent-300 motion-safe:animate-spin" />
                Przygotowuję 4 warianty…
              </p>
            )}
            {designError && <div className="mt-6"><Notice type="error">{designError}</Notice></div>}
            {last && !starting && (
              <div className="mt-8 flex flex-col items-center gap-2 text-center">
                <Button onClick={() => run(last.prompt, true)} disabled={busy} data-testid="generate-more">
                  {busy ? 'Generuję…' : 'Generuj jeszcze 4 propozycje'}
                </Button>
                <p className="text-sm text-muted">
                  {Boolean(direction) && keepDirection ? 'Kolejne odmiany w kierunku projektu' : 'Inne kompozycje dla tego samego opisu'}
                </p>
              </div>
            )}
          </div>
          {query && <SearchResults query={query} projects={projects} projectId={projectId} onProjectChange={setProjectId} onProjectCreated={loadProjects} />}
        </div>
        <QuickStarts onPick={onPick} />
      </div>
    </div>
  );
}
