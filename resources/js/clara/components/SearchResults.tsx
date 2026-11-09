import { useEffect, useRef, useState } from '@wordpress/element';
import { createProject, errorMessage, saveInspiration, searchInspirations, type Project, type SearchItem, type SearchResult } from '../api';
import { Button, inputClass, Notice } from './Field';
import InspirationCard from './InspirationCard';

/** Wyniki Inspiration Finder z zapisem do kolekcji wybranego projektu. */
export default function SearchResults({ query, projects, projectId, onProjectChange, onProjectCreated }: { query: string; projects: Project[]; projectId: number; onProjectChange: (id: number) => void; onProjectCreated?: () => void }) {
  const [result, setResult] = useState<SearchResult | null>(null);
  const [items, setItems] = useState<SearchItem[]>([]);
  const [page, setPage] = useState(1);
  const [commercial, setCommercial] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState<Record<string, string>>({});
  // Automatyczny wybór projektu przy zapisie nie powinien kasować etykiety „Zapisano”.
  const keepSaved = useRef(false);

  const load = async (nextPage: number) => {
    setLoading(true);
    setError(null);
    try {
      const res = await searchInspirations(query, nextPage, commercial, projectId || undefined);
      setResult(res);
      setItems(nextPage === 1 ? res.items : [...items, ...res.items]);
      setPage(nextPage);
    } catch (e) {
      setError(errorMessage(e));
      if (nextPage === 1) setItems([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!keepSaved.current) setSaved({});
    keepSaved.current = false;
    load(1);
  }, [query, commercial, projectId]);

  /** Bez wybranego projektu zapisujemy do ostatniego projektu, a gdy go brak — do nowego „Moje inspiracje”. */
  const targetProject = async () => {
    if (projectId) return projectId;
    const id = projects[0]?.id ?? (await createProject({ name: 'Moje inspiracje' })).id;
    if (!projects.length) onProjectCreated?.();
    keepSaved.current = true;
    onProjectChange(id);
    return id;
  };

  const save = async (item: SearchItem) => {
    try {
      const target = await targetProject();
      const { duplicate } = await saveInspiration(target, { token: item.token });
      setSaved((current) => ({ ...current, [item.token]: duplicate ? 'Już w kolekcji' : 'Zapisano' }));
    } catch (e) {
      setError(errorMessage(e));
    }
  };

  return (
    <section aria-labelledby="clara-search-title" className="flex flex-col gap-4" data-testid="search-results">
      <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div className="min-w-0">
          <h2 id="clara-search-title" className="text-xl font-semibold sm:text-2xl">Wyniki: „{query}”</h2>
          {result && (
            <p className="text-sm text-muted" role="status">
              {result.total > 0 ? `${result.total} zdjęć` : 'Brak wyników'} · źródło: {result.providerLabel}
              {result.searchedQuery && result.searchedQuery !== result.query && ` · szukano: „${result.searchedQuery}”`}
            </p>
          )}
        </div>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <label className="flex items-center gap-2 text-sm text-muted">
            <input type="checkbox" checked={commercial} onChange={(e) => setCommercial(e.target.checked)} className="size-4 accent-accent-500" />
            Tylko z licencją dopuszczającą użycie komercyjne
          </label>
          <div>
            <label htmlFor="clara-save-project" className="text-sm text-muted">Zapisuj do projektu</label>
            <select id="clara-save-project" value={projectId} onChange={(e) => onProjectChange(Number(e.target.value))} className={inputClass}>
              <option value={0}>{projects.length ? `Ostatni: ${projects[0].name}` : 'Nowy: Moje inspiracje'}</option>
              {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </div>
        </div>
      </div>

      {error && <Notice type="error">{error}</Notice>}
      {loading && items.length === 0 && <p className="text-muted">Wyszukiwanie…</p>}
      {!loading && result && result.total === 0 && !error && (
        <p className="rounded-2xl border border-dashed border-line p-6 text-center text-muted" data-testid="search-empty">
          Brak wyników dla „{result.query}”. Spróbuj krótszego lub bardziej ogólnego zapytania.
        </p>
      )}

      {items.length > 0 && (
        <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {items.map((item) => (
            <li key={item.token} className="flex">
              <InspirationCard {...item} licenseSource={result?.providerLabel ?? null}>
                {saved[item.token] || item.saved ? (
                  <span className="text-sm text-accent-300" role="status">{saved[item.token] ?? 'Już w kolekcji'}</span>
                ) : (
                  <Button variant="ghost" onClick={() => save(item)}>Zapisz</Button>
                )}
              </InspirationCard>
            </li>
          ))}
        </ul>
      )}

      {result && items.length < result.total && page < 10 && (
        <div><Button variant="ghost" disabled={loading} onClick={() => load(page + 1)}>{loading ? 'Wczytywanie…' : 'Więcej wyników'}</Button></div>
      )}
    </section>
  );
}
