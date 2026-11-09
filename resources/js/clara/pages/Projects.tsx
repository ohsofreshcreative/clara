import { useEffect, useState } from '@wordpress/element';
import type { FormEvent } from 'react';
import { createProject, errorMessage, fieldErrors, listProjects, type Project } from '../api';
import { Button, Notice, TextField } from '../components/Field';
import Icon from '../components/Icon';
import { href, navigate } from '../router';

export default function Projects() {
  const [projects, setProjects] = useState<Project[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [form, setForm] = useState({ name: '', client_name: '', website_url: '', figma_url: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    listProjects().then(setProjects).catch((e: unknown) => setLoadError(errorMessage(e)));
  }, []);

  const set = (key: keyof typeof form) => (value: string) => setForm({ ...form, [key]: value });

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    setFormError(null);

    try {
      const project = await createProject(form);
      navigate(`projects/${project.id}`);
    } catch (e) {
      setErrors(fieldErrors(e));
      setFormError(errorMessage(e));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="mx-auto flex max-w-[1240px] flex-col gap-10 px-4 py-10 sm:px-8 lg:px-12">
      <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Projekty</h1>

      <section aria-labelledby="clara-projects-list">
        <h2 id="clara-projects-list" className="text-xl font-semibold">Twoje projekty</h2>

        {loadError && <div className="mt-4"><Notice type="error">{loadError}</Notice></div>}
        {!projects && !loadError && <p className="mt-4 text-muted">Wczytywanie…</p>}
        {projects?.length === 0 && <p className="mt-4 text-muted">Nie masz jeszcze projektów. Utwórz pierwszy poniżej.</p>}

        {projects && projects.length > 0 && (
          <ul className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {projects.map((project) => (
              <li key={project.id}>
                <a
                  href={href(`projects/${project.id}`)}
                  onClick={(e) => { e.preventDefault(); navigate(`projects/${project.id}`); }}
                  className="flex h-full flex-col gap-1 glass rounded-2xl border p-5 transition hover:border-accent-400 hover:shadow-glow"
                >
                  <span className="flex items-start justify-between gap-3">
                    <span className="font-semibold">{project.name}</span>
                    {project.brief.demo && <span className="shrink-0 rounded-full bg-accent-600/20 px-2.5 py-0.5 text-xs text-accent-300">demo</span>}
                  </span>
                  <span className="text-sm text-muted">{project.client_name || 'Bez klienta'}</span>
                  <span className="mt-auto flex items-center gap-2 pt-3 text-sm text-subtle">
                    Otwórz <Icon name="arrowRight" className="size-4" />
                  </span>
                </a>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section aria-labelledby="clara-project-new" className="glass rounded-3xl border p-5 sm:p-8">
        <h2 id="clara-project-new" className="text-xl font-semibold">Nowy projekt</h2>
        <form onSubmit={onSubmit} noValidate className="mt-5 grid gap-4 md:grid-cols-2">
          <TextField id="project-name" label="Nazwa projektu *" value={form.name} onChange={set('name')} required error={errors.name} />
          <TextField id="project-client" label="Klient" value={form.client_name} onChange={set('client_name')} error={errors.client_name} />
          <TextField id="project-website" label="Strona klienta" type="url" value={form.website_url} onChange={set('website_url')} placeholder="https://" error={errors.website_url} maxLength={2048} />
          <TextField id="project-figma" label="Plik Figma / design system" type="url" value={form.figma_url} onChange={set('figma_url')} placeholder="https://www.figma.com/design/…" error={errors.figma_url} maxLength={2048} hint="Link zapisujemy — Clara nie pobiera danych z adresu." />
          <div className="flex flex-col gap-3 md:col-span-2 md:flex-row md:items-center">
            <Button type="submit" disabled={saving}>{saving ? 'Zapisywanie…' : 'Utwórz projekt'}</Button>
            {formError && <Notice type="error">{formError}</Notice>}
          </div>
        </form>
      </section>
    </div>
  );
}
