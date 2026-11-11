import { useEffect, useState } from '@wordpress/element';
import type { ChangeEvent, FormEvent } from 'react';
import {
  ATTACHMENT_TYPES,
  clearProjectDirection,
  createSection,
  deleteAttachment,
  deleteProject,
  errorMessage,
  fieldErrors,
  getProject,
  listSectionTypes,
  updateProject,
  uploadAttachment,
  type Project,
  type Section,
} from '../api';
import { Button, cleanList, inputClass, ListField, Notice, TextAreaField, TextField } from '../components/Field';
import DesignSystemPanel from '../components/DesignSystemPanel';
import SectionEditor from '../components/SectionEditor';
import { href, navigate } from '../router';
import { projectDeleted } from '../activeProject';

const MAX_BYTES = 10 * 1024 * 1024;
const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

type Msg = { type: 'error' | 'success'; text: string } | null;

function toForm(p: Project) {
  return {
    name: p.name,
    client_name: p.client_name,
    industry: p.industry,
    website_url: p.website_url,
    figma_url: p.figma_url,
    summary: p.brief.summary ?? '',
    audience: p.brief.audience ?? '',
    goals: p.brief.goals ?? [],
    tone: p.brief.tone ?? [],
    mustHave: p.brief.mustHave ?? [],
    avoid: p.brief.avoid ?? [],
  };
}

export default function ProjectDetail({ id, tab = '' }: { id: number; tab?: '' | 'style' }) {
  const [project, setProject] = useState<Project | null>(null);
  const [sections, setSections] = useState<Section[]>([]);
  const [types, setTypes] = useState<{ value: string; label: string }[]>([]);
  const [form, setForm] = useState<ReturnType<typeof toForm> | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [loadError, setLoadError] = useState<string | null>(null);
  const [saveMsg, setSaveMsg] = useState<Msg>(null);
  const [fileMsg, setFileMsg] = useState<Msg>(null);
  const [sectionMsg, setSectionMsg] = useState<Msg>(null);
  const [newType, setNewType] = useState('hero');
  const [uploading, setUploading] = useState(false);

  useEffect(() => {
    getProject(id)
      .then((p) => { setProject(p); setSections(p.sections ?? []); setForm(toForm(p)); })
      .catch((e: unknown) => setLoadError(errorMessage(e)));
    listSectionTypes().then(setTypes).catch(() => setTypes([]));
  }, [id]);

  if (loadError) {
    return (
      <div className="mx-auto max-w-[1240px] px-4 py-10 sm:px-8 lg:px-12">
        <h1 className="text-3xl font-semibold">Projekt</h1>
        <div className="mt-4"><Notice type="error">{loadError}</Notice></div>
      </div>
    );
  }

  if (!project || !form) {
    return <p className="px-4 py-10 text-muted sm:px-8 lg:px-12">Wczytywanie…</p>;
  }

  const set = <K extends keyof typeof form>(key: K) => (value: (typeof form)[K]) => setForm({ ...form, [key]: value });

  const save = async (event: FormEvent) => {
    event.preventDefault();
    setErrors({});
    setSaveMsg(null);

    try {
      const updated = await updateProject(project.id, {
        name: form.name,
        client_name: form.client_name,
        industry: form.industry,
        website_url: form.website_url,
        figma_url: form.figma_url,
        brief: {
          summary: form.summary,
          audience: form.audience,
          goals: cleanList(form.goals),
          tone: cleanList(form.tone),
          mustHave: cleanList(form.mustHave),
          avoid: cleanList(form.avoid),
        },
      });
      setProject(updated);
      setSaveMsg({ type: 'success', text: 'Zapisano projekt i brief.' });
    } catch (e) {
      setErrors(fieldErrors(e));
      setSaveMsg({ type: 'error', text: errorMessage(e) });
    }
  };

  const remove = async () => {
    if (!window.confirm(`Usunąć projekt „${project.name}” wraz z sekcjami i załącznikami?`)) return;

    try {
      await deleteProject(project.id);
      projectDeleted(project.id);
      navigate('projects');
    } catch (e) {
      setSaveMsg({ type: 'error', text: errorMessage(e) });
    }
  };

  const addSection = async () => {
    setSectionMsg(null);

    try {
      const section = await createSection(project.id, { type: newType });
      setSections([...sections, section]);
    } catch (e) {
      setSectionMsg({ type: 'error', text: errorMessage(e) });
    }
  };

  const onFile = async (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    // Wstępna kontrola w przeglądarce; ostateczna (po zawartości pliku) odbywa się na serwerze.
    if (!ALLOWED.includes(file.type) || file.size > MAX_BYTES) {
      setFileMsg({ type: 'error', text: 'Dozwolone: JPG, PNG, WebP, PDF do 10 MB.' });
      return;
    }

    setUploading(true);
    setFileMsg(null);

    try {
      const attachment = await uploadAttachment(project.id, file);
      const attachments = [...(project.brief.attachments ?? []), attachment];
      setProject({ ...project, brief: { ...project.brief, attachments } });
      setFileMsg({ type: 'success', text: `Dodano „${attachment.name}”.` });
    } catch (e) {
      setFileMsg({ type: 'error', text: errorMessage(e) });
    } finally {
      setUploading(false);
    }
  };

  const removeFile = async (attachmentId: number) => {
    try {
      await deleteAttachment(project.id, attachmentId);
      const attachments = (project.brief.attachments ?? []).filter((a) => a.id !== attachmentId);
      setProject({ ...project, brief: { ...project.brief, attachments } });
    } catch (e) {
      setFileMsg({ type: 'error', text: errorMessage(e) });
    }
  };

  return (
    <div className="mx-auto flex max-w-[1240px] flex-col gap-8 px-4 py-10 sm:px-8 lg:px-12">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="min-w-0">
          <a href={href('projects')} onClick={(e) => { e.preventDefault(); navigate('projects'); }} className="text-sm text-accent-300 hover:text-white">
            ← Projekty
          </a>
          <h1 className="mt-2 break-words text-3xl font-semibold tracking-tight sm:text-4xl">
            {project.name}
            {tab === 'style' && <span className="text-muted"> · Styl</span>}
          </h1>
          {project.brief.demo && <p className="mt-2 text-sm text-accent-300">Projekt demonstracyjny — treści briefu są przykładowe.</p>}
        </div>
        {tab === '' && <Button variant="danger" onClick={remove}>Usuń projekt</Button>}
      </div>

      {tab === 'style' && (
        <DesignSystemPanel
          projectId={project.id}
          hasFigmaUrl={Boolean(project.figma_url)}
          colorsUrl={project.brief.colorsUrl ?? ''}
          onColorsUrl={(colorsUrl) => setProject({ ...project, brief: { ...project.brief, colorsUrl } })}
        />
      )}

      {tab === '' && (<>

      {project.brief.direction && (
        <section className="glass flex flex-col gap-4 rounded-3xl border border-accent-400/40 p-5 shadow-glow sm:flex-row sm:items-center sm:p-6" aria-labelledby="clara-direction-title" data-testid="project-direction">
          {project.brief.direction.imageUrl && <img src={project.brief.direction.imageUrl} alt="Obrany kierunek projektu" className="w-full rounded-xl border border-white/10 sm:w-64" />}
          <div className="flex min-w-0 flex-1 flex-col gap-2">
            <h2 id="clara-direction-title" className="text-xl font-semibold">Kierunek projektu</h2>
            <p className="text-muted">{project.brief.direction.title} — z opcją „Trzymaj się kierunku projektu” na dashboardzie kolejne inspiracje powstają w tym stylu.</p>
            <div>
              <Button variant="ghost" onClick={async () => {
                try {
                  await clearProjectDirection(project.id);
                  const { direction, ...brief } = project.brief;
                  setProject({ ...project, brief });
                } catch (e) {
                  window.alert(errorMessage(e));
                }
              }}>Usuń kierunek</Button>
            </div>
          </div>
        </section>
      )}

      <form onSubmit={save} noValidate className="grid gap-4 glass rounded-3xl border p-5 sm:p-8 md:grid-cols-2" aria-labelledby="clara-brief-title">
        <h2 id="clara-brief-title" className="text-xl font-semibold md:col-span-2">Projekt i brief</h2>
        <TextField id="p-name" label="Nazwa projektu *" value={form.name} onChange={set('name')} required error={errors.name} />
        <TextField id="p-client" label="Klient" value={form.client_name} onChange={set('client_name')} error={errors.client_name} />
        <TextField id="p-industry" label="Branża" value={form.industry} onChange={set('industry')} error={errors.industry} />
        <TextField id="p-website" label="Strona klienta" type="url" value={form.website_url} onChange={set('website_url')} placeholder="https://" error={errors.website_url} maxLength={2048} />
        <div className="md:col-span-2">
          <TextField id="p-figma" label="Plik Figma / design system" type="url" value={form.figma_url} onChange={set('figma_url')} placeholder="https://www.figma.com/design/…" error={errors.figma_url} maxLength={2048} hint="Link zapisujemy — Clara nie pobiera danych z adresu." />
        </div>
        <TextAreaField id="p-summary" label="Opis projektu" value={form.summary} onChange={set('summary')} rows={4} error={errors['brief.summary']} />
        <TextAreaField id="p-audience" label="Grupa docelowa" value={form.audience} onChange={set('audience')} rows={4} maxLength={1000} error={errors['brief.audience']} />
        <ListField id="p-goals" label="Cele" value={form.goals} onChange={set('goals')} error={errors['brief.goals']} />
        <ListField id="p-tone" label="Ton i charakter" value={form.tone} onChange={set('tone')} error={errors['brief.tone']} />
        <ListField id="p-must" label="Musi się znaleźć" value={form.mustHave} onChange={set('mustHave')} error={errors['brief.mustHave']} />
        <ListField id="p-avoid" label="Unikać" value={form.avoid} onChange={set('avoid')} error={errors['brief.avoid']} />
        <div className="flex flex-col gap-3 md:col-span-2 md:flex-row md:items-center">
          <Button type="submit">Zapisz projekt</Button>
          {saveMsg && <Notice type={saveMsg.type}>{saveMsg.text}</Notice>}
        </div>
      </form>

      <p className="glass flex flex-col gap-3 rounded-2xl border px-5 py-4 text-muted sm:flex-row sm:items-center sm:justify-between">
        Kolory, typografia i screeny referencyjne projektu są w zakładce Styl.
        <a href={href(`projects/${project.id}/style`)} onClick={(e) => { e.preventDefault(); navigate(`projects/${project.id}/style`); }} className="shrink-0 text-accent-300 hover:text-white">Przejdź do stylu →</a>
      </p>

      <section aria-labelledby="clara-files-title" className="glass rounded-3xl border p-5 sm:p-8">
        <h2 id="clara-files-title" className="text-xl font-semibold">Załączniki briefu</h2>
        <p className="mt-1 text-sm text-muted">JPG, PNG, WebP lub PDF, do 10 MB, maksymalnie 10 plików.</p>
        {(project.brief.attachments ?? []).length > 0 && (
          <ul className="mt-4 flex flex-col gap-2">
            {(project.brief.attachments ?? []).map((file) => (
              <li key={file.id} className="flex items-center justify-between gap-3 rounded-xl border border-line px-4 py-2.5">
                <a href={file.url} target="_blank" rel="noopener noreferrer" className="min-w-0 truncate text-accent-300 hover:text-white">{file.name}</a>
                <Button variant="ghost" onClick={() => removeFile(file.id)} aria-label={`Usuń ${file.name}`}>Usuń</Button>
              </li>
            ))}
          </ul>
        )}
        <div className="mt-4 flex flex-col gap-3">
          <label htmlFor="p-file" className="text-sm text-muted">Dodaj plik</label>
          <input id="p-file" type="file" accept={ATTACHMENT_TYPES} onChange={onFile} disabled={uploading} className="max-w-full text-sm text-muted file:mr-4 file:rounded-xl file:border-0 file:bg-accent-600 file:px-4 file:py-2 file:text-white" />
          {uploading && <p className="text-sm text-muted">Wysyłanie…</p>}
          {fileMsg && <Notice type={fileMsg.type}>{fileMsg.text}</Notice>}
        </div>
      </section>

      <section aria-labelledby="clara-sections-title" className="flex flex-col gap-4">
        <h2 id="clara-sections-title" className="text-xl font-semibold">Sekcje</h2>
        {sections.length === 0 && <p className="text-muted">Brak sekcji. Dodaj pierwszą, np. Hero.</p>}
        {sections.map((section) => (
          <SectionEditor
            key={section.id}
            section={section}
            onChange={(s) => setSections(sections.map((x) => (x.id === s.id ? s : x)))}
            onDelete={(sid) => setSections(sections.filter((x) => x.id !== sid))}
          />
        ))}
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <div>
            <label htmlFor="p-new-section" className="text-sm text-muted">Typ nowej sekcji</label>
            <select id="p-new-section" value={newType} onChange={(e) => setNewType(e.target.value)} className={inputClass}>
              {types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
          </div>
          <Button onClick={addSection}>Dodaj sekcję</Button>
        </div>
        {sectionMsg && <Notice type={sectionMsg.type}>{sectionMsg.text}</Notice>}
      </section>
      </>)}
    </div>
  );
}
