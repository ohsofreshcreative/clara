import { useEffect, useState } from '@wordpress/element';
import type { MouseEvent } from 'react';
import heroFigure from '../../../images/clara/hero-figure.jpg';
import { errorMessage, getDashboard, type DashboardData, type DashboardProject } from '../api';
import { setActiveProject } from '../activeProject';
import { Notice } from '../components/Field';
import Icon from '../components/Icon';
import { href, navigate } from '../router';

function go(path: string, projectId?: number) {
  return (event: MouseEvent<HTMLAnchorElement>) => {
    event.preventDefault();
    if (projectId) setActiveProject(projectId);
    navigate(path);
  };
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="glass rounded-2xl border px-5 py-4">
      <dt className="text-sm text-muted">{label}</dt>
      <dd className="mt-1 text-3xl font-semibold tracking-tight">{value}</dd>
    </div>
  );
}

function ProjectCard({ project }: { project: DashboardProject }) {
  const base = `projects/${project.id}`;

  return (
    <li className="glass group flex flex-col overflow-hidden rounded-3xl border transition-colors hover:border-accent-400/40" data-testid="dashboard-project">
      <a href={href(base)} onClick={go(base, project.id)} className="relative block aspect-[16/9] overflow-hidden bg-gradient-to-br from-accent-500/20 via-ink-900 to-ink-950" tabIndex={-1} aria-hidden="true">
        {project.thumbnail ? (
          <img src={project.thumbnail} alt="" loading="lazy" className="size-full object-cover transition-transform duration-500 group-hover:scale-[1.03]" />
        ) : (
          <span className="grid size-full place-items-center text-5xl font-semibold text-white/15">{project.name.trim().charAt(0).toUpperCase()}</span>
        )}
      </a>
      <div className="flex flex-1 flex-col gap-3 p-5">
        <div className="min-w-0">
          <h3 className="truncate text-lg font-semibold">
            <a href={href(base)} onClick={go(base, project.id)} className="hover:text-accent-300">{project.name}</a>
          </h3>
          <p className="truncate text-sm text-muted">
            {project.client_name || 'Bez klienta'} · {project.inspirations} {project.inspirations === 1 ? 'inspiracja' : 'inspiracji'}
          </p>
        </div>
        <div className="flex min-h-6 items-center gap-2 text-sm text-muted">
          {project.colors.length > 0 ? (
            <span className="flex -space-x-1.5" aria-label={`Kolory: ${project.colors.join(', ')}`}>
              {project.colors.map((c) => <span key={c} className="size-5 rounded-full border border-white/20" style={{ background: c }} />)}
            </span>
          ) : null}
          <span className="truncate">{project.fonts.length ? project.fonts.join(' · ') : project.colors.length ? '' : 'Styl nieustawiony'}</span>
        </div>
        <div className="mt-auto flex flex-wrap gap-2">
          <a href={href(`${base}/generate`)} onClick={go(`${base}/generate`, project.id)} className="inline-flex items-center gap-2 rounded-xl bg-accent-600 px-4 py-2 text-sm text-white hover:bg-accent-500">
            <Icon name="sparkles" className="size-4" />
            Generuj
          </a>
          <a href={href(`${base}/style`)} onClick={go(`${base}/style`, project.id)} className="inline-flex items-center gap-2 rounded-xl border border-white/10 px-4 py-2 text-sm text-muted hover:border-accent-400/50 hover:text-white">
            <Icon name="layers" className="size-4" />
            Styl
          </a>
        </div>
      </div>
    </li>
  );
}

export default function Dashboard() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getDashboard().then(setData).catch((e: unknown) => setError(errorMessage(e)));
  }, []);

  return (
    <div className="relative isolate overflow-hidden">
      {/* Akcent tożsamości: dekoracyjny, za treścią, wygaszony maską. */}
      <div aria-hidden="true" className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-64 sm:h-80 lg:left-auto lg:right-[3%] lg:aspect-[706/312] lg:h-auto lg:w-[52%] lg:max-w-[780px]">
        <img
          src={heroFigure}
          alt=""
          className="size-full object-cover object-[70%_top] opacity-60 [mask-image:linear-gradient(to_bottom,black_55%,transparent)] lg:object-center lg:opacity-100 lg:[mask-image:linear-gradient(to_right,transparent,black_28%,black_92%,transparent),linear-gradient(to_bottom,black_72%,transparent)] lg:[mask-composite:intersect]"
        />
      </div>

      <div className="mx-auto flex max-w-[1240px] flex-col gap-10 px-4 pt-40 pb-12 sm:px-8 sm:pt-52 lg:gap-12 lg:px-12 lg:pt-16">
        <div className="max-w-xl">
          <p className="text-sm font-semibold tracking-[0.35em] text-accent-400">CLARA</p>
          <h1 className="mt-3 max-w-lg text-4xl font-semibold leading-tight tracking-tight sm:text-5xl lg:text-[4rem] lg:leading-[1.05]">
            Inspiracje dla Twoich <span className="text-glow text-accent-300">stron</span>
          </h1>
          <p className="mt-5 max-w-lg text-muted sm:text-lg">
            Wybierz projekt i generuj inspiracje sekcji w jego stylu — kolory, typografia i screeny referencyjne z Figmy.
          </p>
        </div>

        {error && <Notice type="error">{error}</Notice>}

        <dl className="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:-mt-2" aria-label="Podsumowanie">
          <Stat label="Projekty" value={data?.stats.projects ?? 0} />
          <Stat label="Wygenerowane inspiracje" value={data?.stats.inspirations ?? 0} />
          <Stat label="Projekty z ustawionym stylem" value={data?.stats.styled ?? 0} />
        </dl>

        <section aria-labelledby="clara-dash-projects" className="flex flex-col gap-4">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <h2 id="clara-dash-projects" className="text-xl font-semibold sm:text-2xl">Projekty</h2>
            <a href={href('projects')} onClick={go('projects')} className="text-accent-300 hover:text-white">Zarządzaj projektami →</a>
          </div>
          {data && (
            <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
              {data.projects.map((p) => <ProjectCard key={p.id} project={p} />)}
              <li>
                <a
                  href={href('projects')}
                  onClick={go('projects')}
                  className="flex h-full min-h-48 flex-col items-center justify-center gap-3 rounded-3xl border border-dashed border-white/15 p-6 text-center text-muted transition-colors hover:border-accent-400/60 hover:text-white"
                  data-testid="dashboard-new-project"
                >
                  <span aria-hidden="true" className="grid size-12 place-items-center rounded-2xl bg-accent-500/15 text-2xl text-accent-300">+</span>
                  Nowy projekt
                </a>
              </li>
            </ul>
          )}
          {!data && !error && <p className="text-muted">Wczytywanie…</p>}
        </section>

        <section aria-labelledby="clara-recent-title" className="flex flex-col gap-4">
          <h2 id="clara-recent-title" className="text-xl font-semibold sm:text-2xl">Ostatnie inspiracje</h2>
          {data && data.recent.length > 0 ? (
            <ul className="grid grid-cols-2 gap-3 xl:grid-cols-4" data-testid="dashboard-recent">
              {data.recent.map((item) => (
                <li key={item.id}>
                  <a href={href(`projects/${item.projectId}/generate`)} onClick={go(`projects/${item.projectId}/generate`, item.projectId)} className="group block overflow-hidden rounded-2xl border border-white/10 hover:border-accent-400/50">
                    <img src={item.imageUrl} alt={`Inspiracja: ${item.title || 'sekcja'} — ${item.projectName}`} loading="lazy" className="aspect-[16/10] w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]" />
                    <span className="block truncate px-3 py-2 text-sm text-muted group-hover:text-white">{item.projectName}{item.title ? ` · ${item.title}` : ''}</span>
                  </a>
                </li>
              ))}
            </ul>
          ) : (
            data && <p className="glass rounded-2xl border px-5 py-6 text-center text-muted">Nie masz jeszcze inspiracji. Wybierz projekt i przejdź do zakładki Generowanie.</p>
          )}
        </section>
      </div>
    </div>
  );
}
