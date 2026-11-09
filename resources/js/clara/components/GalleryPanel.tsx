import { useEffect, useRef, useState } from '@wordpress/element';
import type { FormEvent } from 'react';
import {
  IMAGES_UPDATED, errorMessage, fieldErrors, getGallery, getLineage, listInspirations, remixImage, selectVariant, setProjectDirection,
  type GalleryItem, type Inspiration, type Lineage, type Section,
} from '../api';
import { Button, Notice, TextAreaField } from './Field';
import LayoutPanel from './LayoutPanel';

const POLL_MS = 2000;
const MAX_REFERENCES = 5;
const pending = (i: GalleryItem) => i.status === 'queued' || i.status === 'running';
const label = (i: GalleryItem) => `${i.directionKey} · v${i.version}${i.remixOf ? ' (remiks)' : ''}`;

function Thumb({ item, className = '' }: { item: GalleryItem; className?: string }) {
  return (
    <div className={`relative aspect-video overflow-hidden rounded-xl border border-line bg-ink-950 ${className}`}>
      {item.image ? (
        <img src={item.image.url} alt={`Wariant ${label(item)}: ${item.title}${item.mock ? ' (mock)' : ''}`} className="size-full object-cover" loading="lazy" />
      ) : (
        <span className="absolute inset-0 grid place-items-center p-3 text-center text-sm text-muted">
          {item.status === 'failed' ? item.error?.message ?? 'Błąd generowania.' : item.statusLabel}
        </span>
      )}
    </div>
  );
}

function Sources({ item }: { item: GalleryItem }) {
  if (!item.sources.length) return null;
  return (
    <ul className="flex flex-col gap-1 text-xs text-muted">
      {item.sources.map((s) => (
        <li key={s.inspirationId} className="truncate">
          Referencja: <a href={s.sourceUrl} target="_blank" rel="noopener noreferrer" className="text-accent-300 hover:text-white">{s.title || s.sourceUrl}</a>
          {s.license ? ` · ${s.license}` : ' · licencja nieznana'}
          {s.available === false && ' · usunięta z kolekcji'}
        </li>
      ))}
    </ul>
  );
}

/**
 * Galeria wariantów sekcji: wybór, porównanie dwóch wariantów, Remix (nowa wersja — rodzic
 * pozostaje bez zmian) z referencjami z kolekcji projektu i historia wersji.
 */
export default function GalleryPanel({ section }: { section: Section }) {
  const [items, setItems] = useState<GalleryItem[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [compare, setCompare] = useState<number[]>([]);
  const [remixFor, setRemixFor] = useState<GalleryItem | null>(null);
  const [lineage, setLineage] = useState<Lineage | null>(null);
  const [collection, setCollection] = useState<Inspiration[]>([]);
  const [instruction, setInstruction] = useState('');
  const [references, setReferences] = useState<number[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<{ type: 'error' | 'success'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const timer = useRef<number | undefined>(undefined);

  const refresh = () => getGallery(section.id)
    .then((g) => { setItems(g.items); setSelectedId(g.selectedId); })
    .catch((e: unknown) => setMsg({ type: 'error', text: errorMessage(e) }));

  useEffect(() => {
    refresh();
    const onUpdate = (e: Event) => { if ((e as CustomEvent).detail?.sectionId === section.id) refresh(); };
    window.addEventListener(IMAGES_UPDATED, onUpdate);
    return () => window.removeEventListener(IMAGES_UPDATED, onUpdate);
  }, [section.id]);

  useEffect(() => {
    window.clearTimeout(timer.current);
    if (items.some(pending)) timer.current = window.setTimeout(refresh, POLL_MS);
    return () => window.clearTimeout(timer.current);
  }, [items]);

  const byId = new Map(items.map((i) => [i.id, i]));
  const compared = compare.map((id) => byId.get(id)).filter((i): i is GalleryItem => Boolean(i));

  const choose = async (id: number | null) => {
    setMsg(null);
    try {
      const res = await selectVariant(section.id, id);
      setSelectedId(res.selectedId);
    } catch (e) {
      setMsg({ type: 'error', text: errorMessage(e) });
    }
  };

  const toggleCompare = (id: number) => {
    setCompare(compare.includes(id) ? compare.filter((c) => c !== id) : [...compare.slice(-1), id]);
  };

  const openRemix = (item: GalleryItem) => {
    setRemixFor(item);
    setInstruction('');
    setReferences([]);
    setErrors({});
    listInspirations(section.project_id).then(setCollection).catch(() => setCollection([]));
  };

  const submitRemix = async (event: FormEvent) => {
    event.preventDefault();
    if (!remixFor) return;
    setBusy(true);
    setErrors({});
    setMsg(null);
    try {
      await remixImage(remixFor.id, instruction, references);
      setMsg({ type: 'success', text: `Remiks wariantu ${label(remixFor)} zlecony — powstanie nowa wersja, oryginał pozostaje bez zmian.` });
      setRemixFor(null);
      refresh();
    } catch (e) {
      setErrors(fieldErrors(e));
      setMsg({ type: 'error', text: errorMessage(e) });
    } finally {
      setBusy(false);
    }
  };

  const showHistory = async (item: GalleryItem) => {
    try {
      setLineage(await getLineage(item.id));
    } catch (e) {
      setMsg({ type: 'error', text: errorMessage(e) });
    }
  };

  const toggleReference = (id: number) => {
    setReferences(references.includes(id) ? references.filter((r) => r !== id) : [...references, id]);
  };

  return (
    <section className="flex flex-col gap-4 border-t border-line p-5" aria-labelledby={`gallery-${section.id}`} data-testid="gallery-panel">
      <div className="flex flex-col gap-1">
        <h3 id={`gallery-${section.id}`} className="font-semibold">Galeria wariantów</h3>
        <p className="text-sm text-muted">
          {items.length ? `${items.length} wariantów · wybierz jeden, porównaj dwa lub zrób remiks (tworzy nową wersję).` : 'Brak obrazów — wygeneruj 4 wizualizacje z kierunków artystycznych powyżej.'}
        </p>
      </div>

      {msg && <Notice type={msg.type}>{msg.text}</Notice>}

      {items.length > 0 && (
        <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {items.map((item) => {
            const isSelected = item.id === selectedId;
            return (
              <li key={item.id} data-testid="gallery-item" data-id={item.id} data-status={item.status} data-selected={isSelected}
                className={`flex min-w-0 flex-col gap-2 rounded-xl border p-2 ${isSelected ? 'border-accent-500 bg-accent-600/10' : 'border-transparent'}`}>
                <Thumb item={item} />
                <p className="flex flex-wrap items-center gap-2 text-sm">
                  <span className="font-semibold">{label(item)}</span>
                  {isSelected && <span className="rounded-full bg-accent-600/30 px-2 py-0.5 text-xs text-accent-300">Wybrany</span>}
                  {item.status !== 'succeeded' && <span className="text-xs text-muted">{item.statusLabel}</span>}
                </p>
                <p className="line-clamp-2 text-xs text-muted">{item.remixPrompt ? `Remiks: ${item.remixPrompt}` : item.title}</p>
                <div className="flex flex-wrap items-center gap-2">
                  {item.status === 'succeeded' && (
                    <Button variant="ghost" aria-pressed={isSelected} onClick={() => choose(isSelected ? null : item.id)}>{isSelected ? 'Odznacz' : 'Wybierz'}</Button>
                  )}
                  {item.status === 'succeeded' && <Button variant="ghost" onClick={() => openRemix(item)}>Remix</Button>}
                  {item.status === 'succeeded' && !item.mock && <Button variant="ghost" onClick={() => setProjectDirection(section.project_id, item.id).then(() => setMsg({ type: 'success', text: `Wariant ${item.directionKey} jest teraz kierunkiem projektu.` })).catch((e) => setMsg({ type: 'error', text: errorMessage(e) }))}>Obierz jako kierunek</Button>}
                  <Button variant="ghost" onClick={() => showHistory(item)}>Historia</Button>
                  <label className="flex items-center gap-2 text-sm text-muted">
                    <input type="checkbox" checked={compare.includes(item.id)} onChange={() => toggleCompare(item.id)} className="size-4 accent-accent-500" />
                    Porównaj
                  </label>
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {selectedId && byId.get(selectedId)?.status === 'succeeded' && <LayoutPanel item={byId.get(selectedId)!} />}

      {remixFor && (
        <form onSubmit={submitRemix} noValidate className="flex flex-col gap-3 rounded-xl border border-line p-4" data-testid="remix-form">
          <h4 className="font-semibold">Remix wariantu {label(remixFor)}</h4>
          <div className="grid gap-4 md:grid-cols-[1fr_2fr]">
            <Thumb item={remixFor} />
            <div className="flex flex-col gap-3">
              <TextAreaField id={`remix-${section.id}`} label="Co zmienić?" value={instruction} onChange={setInstruction} rows={3} maxLength={1000} error={errors.prompt} />
              <fieldset className="flex flex-col gap-2">
                <legend className="text-sm text-muted">Referencje z kolekcji (maks. {MAX_REFERENCES}, tylko nastrój — bez kopiowania)</legend>
                {collection.length === 0 && <p className="text-sm text-subtle">Kolekcja projektu jest pusta.</p>}
                {collection.map((c) => (
                  <label key={c.id} className="flex min-w-0 items-center gap-2 text-sm">
                    <input type="checkbox" checked={references.includes(c.id)} disabled={!references.includes(c.id) && references.length >= MAX_REFERENCES}
                      onChange={() => toggleReference(c.id)} className="size-4 accent-accent-500" />
                    <span className="truncate">{c.attribution.title || c.source_url}</span>
                  </label>
                ))}
                {errors.references && <p className="text-sm text-red-300">{errors.references}</p>}
              </fieldset>
            </div>
          </div>
          <div className="flex flex-wrap gap-3">
            <Button type="submit" disabled={busy}>{busy ? 'Zlecanie…' : 'Utwórz nową wersję'}</Button>
            <Button variant="ghost" onClick={() => setRemixFor(null)}>Anuluj</Button>
          </div>
        </form>
      )}

      {compared.length === 2 && (
        <div className="flex flex-col gap-3 rounded-xl border border-line p-4" data-testid="compare-view">
          <div className="flex items-center justify-between gap-3">
            <h4 className="font-semibold">Porównanie</h4>
            <Button variant="ghost" onClick={() => setCompare([])}>Zamknij</Button>
          </div>
          <div className="grid gap-4 md:grid-cols-2">
            {compared.map((item) => (
              <figure key={item.id} className="flex min-w-0 flex-col gap-2">
                <Thumb item={item} />
                <figcaption className="flex flex-col gap-1 text-sm">
                  <span className="font-semibold">{label(item)} — {item.title}</span>
                  <span className="text-muted">{item.remixPrompt ? `Instrukcja remiksu: ${item.remixPrompt}` : 'Oryginał z kierunku artystycznego'}</span>
                  <span className="text-xs text-subtle">{item.provider} · {item.model}{item.mock ? ' · mock' : ''} · {new Date(item.created_at).toLocaleString('pl-PL')}</span>
                  <Sources item={item} />
                </figcaption>
              </figure>
            ))}
          </div>
        </div>
      )}

      {lineage && (
        <div className="flex flex-col gap-3 rounded-xl border border-line p-4" data-testid="lineage">
          <div className="flex items-center justify-between gap-3">
            <h4 className="font-semibold">Historia wersji{lineage.direction ? ` — kierunek ${lineage.direction.key}: ${lineage.direction.title}` : ''}</h4>
            <Button variant="ghost" onClick={() => setLineage(null)}>Zamknij</Button>
          </div>
          <ol className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {lineage.versions.map((v) => {
              const onPath = lineage.path.includes(v.id);
              return (
                <li key={v.id} aria-current={v.id === lineage.id ? 'true' : undefined} className={`flex min-w-0 flex-col gap-2 rounded-xl border p-2 ${v.id === lineage.id ? 'border-accent-500' : onPath ? 'border-line' : 'border-transparent opacity-80'}`}>
                  <Thumb item={v} />
                  <p className="text-sm font-semibold">v{v.version}{v.remixOf ? ` ← z v${byId.get(v.remixOf)?.version ?? '?'}` : ' · oryginał'}</p>
                  <p className="line-clamp-3 text-xs text-muted">{v.remixPrompt ?? 'Wygenerowany z kierunku artystycznego.'}</p>
                  <Sources item={v} />
                </li>
              );
            })}
          </ol>
        </div>
      )}
    </section>
  );
}
