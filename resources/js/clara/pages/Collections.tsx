import { useEffect, useState } from '@wordpress/element';
import type { FormEvent } from 'react';
import { deleteInspiration, errorMessage, fieldErrors, listInspirations, listProjects, saveInspiration, type Inspiration, type Project } from '../api';
import { Button, inputClass, Notice, TextField } from '../components/Field';
import InspirationCard from '../components/InspirationCard';

const providerLabel = (provider: string) => ({ openverse: 'Openverse', mock: 'danych testowych', manual: 'użytkownika' })[provider] ?? provider;

export default function Collections() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [projectId, setProjectId] = useState(0);
  const [items, setItems] = useState<Inspiration[] | null>(null);
  const [url, setUrl] = useState('');
  const [title, setTitle] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<{ type: 'error' | 'success'; text: string } | null>(null);

  useEffect(() => {
    listProjects()
      .then((list) => { setProjects(list); setProjectId(list[0]?.id ?? 0); })
      .catch((e: unknown) => setMsg({ type: 'error', text: errorMessage(e) }));
  }, []);

  useEffect(() => {
    if (!projectId) { setItems([]); return; }
    setItems(null);
    listInspirations(projectId).then(setItems).catch((e: unknown) => setMsg({ type: 'error', text: errorMessage(e) }));
  }, [projectId]);

  const add = async (event: FormEvent) => {
    event.preventDefault();
    setErrors({});
    setMsg(null);
    try {
      const { inspiration, duplicate } = await saveInspiration(projectId, { url, title });
      if (!duplicate) setItems([inspiration, ...(items ?? [])]);
      setMsg({ type: 'success', text: duplicate ? 'Ten link jest już w kolekcji.' : 'Dodano link do kolekcji.' });
      setUrl('');
      setTitle('');
    } catch (e) {
      setErrors(fieldErrors(e));
      setMsg({ type: 'error', text: errorMessage(e) });
    }
  };

  const remove = async (item: Inspiration) => {
    if (!window.confirm('Usunąć inspirację z kolekcji?')) return;
    try {
      await deleteInspiration(item.id);
      setItems((items ?? []).filter((i) => i.id !== item.id));
    } catch (e) {
      setMsg({ type: 'error', text: errorMessage(e) });
    }
  };

  return (
    <div className="mx-auto flex max-w-[1240px] flex-col gap-6 px-4 py-10 sm:px-8 lg:px-12">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Kolekcje</h1>
          <p className="mt-2 text-muted">Inspiracje zapisane w projektach — z linkiem do źródła i atrybucją.</p>
        </div>
        <div className="sm:w-72">
          <label htmlFor="clara-collection-project" className="text-sm text-muted">Projekt</label>
          <select id="clara-collection-project" value={projectId} onChange={(e) => setProjectId(Number(e.target.value))} className={inputClass}>
            {!projects.length && <option value={0}>Brak projektów</option>}
            {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </div>
      </div>

      {projectId > 0 && (
        <form onSubmit={add} noValidate className="grid gap-3 glass rounded-3xl border p-5 md:grid-cols-[2fr_1fr_auto] md:items-end">
          <TextField id="c-url" label="Dodaj link do inspiracji" type="url" value={url} onChange={setUrl} placeholder="https://" maxLength={2048} error={errors.url} />
          <TextField id="c-title" label="Tytuł (opcjonalnie)" value={title} onChange={setTitle} maxLength={300} error={errors.title} />
          <Button type="submit">Dodaj</Button>
          <p className="text-xs text-subtle md:col-span-3">Clara zapisuje tylko link — nie pobiera strony. Licencja ręcznie dodanych linków jest nieznana.</p>
        </form>
      )}

      {msg && <Notice type={msg.type}>{msg.text}</Notice>}

      {items === null && <p className="text-muted">Wczytywanie…</p>}
      {items !== null && items.length === 0 && projectId > 0 && (
        <p className="rounded-2xl border border-dashed border-line p-6 text-center text-muted" data-testid="collection-empty">
          Kolekcja jest pusta. Wyszukaj inspiracje na dashboardzie (tryb „Wyszukaj”) i zapisz je do projektu.
        </p>
      )}
      {items && items.length > 0 && (
        <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Zapisane inspiracje">
          {items.map((item) => (
            <li key={item.id} className="flex">
              <InspirationCard
                title={item.attribution.title}
                sourceUrl={item.source_url}
                thumbnailUrl={item.thumbnail_url}
                creator={item.attribution.creator}
                creatorUrl={item.attribution.creatorUrl}
                sourceName={item.attribution.sourceName}
                indexedAt={item.metadata.indexedAt}
                license={item.rights.license}
                licenseSource={item.rights.declaredBy ? providerLabel(item.rights.declaredBy) : null}
              >
                <Button variant="ghost" onClick={() => remove(item)} aria-label={`Usuń z kolekcji: ${item.attribution.title || item.source_url}`}>Usuń</Button>
              </InspirationCard>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
