import { useEffect, useState } from '@wordpress/element';
import type { MouseEvent } from 'react';
import { listProjects, type Project } from '../api';
import { PROJECT_DELETED, projectTabs, setActiveProject, useActiveProject } from '../activeProject';
import { href, navigate, routes, type Route } from '../router';
import Icon, { type IconName } from './Icon';

const icons: Record<string, IconName> = {
  '': 'home',
  projects: 'folder',
  collections: 'collection',
  library: 'library',
  settings: 'settings',
};

function Logo() {
  return (
    <span className="flex items-center gap-3 text-2xl font-semibold tracking-tight">
      <svg viewBox="0 0 40 24" className="h-6 w-10 text-accent-400" aria-hidden="true" focusable="false">
        <path d="M2 18c6-12 12-12 18-6s12 6 18-6" fill="none" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
      </svg>
      Clara
    </span>
  );
}

function NavLink({ path, label, icon, active, onNavigate, compact = false }: { path: string; label: string; icon: IconName; active: boolean; onNavigate: () => void; compact?: boolean }) {
  const onClick = (event: MouseEvent<HTMLAnchorElement>) => {
    event.preventDefault();
    navigate(path);
    onNavigate();
  };

  return (
    <a
      href={href(path)}
      onClick={onClick}
      aria-current={active ? 'page' : undefined}
      className={`flex items-center gap-4 rounded-xl transition-colors ${compact ? 'px-5 py-2.5' : 'px-5 py-3.5'} ${
        active ? 'bg-accent-500/15 text-white shadow-glow' : 'text-muted hover:bg-white/5 hover:text-white'
      }`}
    >
      <Icon name={icon} />
      {label}
    </a>
  );
}

export default function Sidebar({ current, params, userName }: { current?: Route; params: string[]; userName: string }) {
  const [open, setOpen] = useState(false);
  const [projects, setProjects] = useState<Project[]>([]);
  const main = routes.filter((route) => route.path !== 'settings');
  const settings = routes.find((route) => route.path === 'settings');

  // Projekt z adresu ma pierwszeństwo przed ostatnim wyborem.
  const stored = useActiveProject();
  const fromUrl = current?.path === 'projects' && /^\d+$/.test(params[0] ?? '') ? Number(params[0]) : 0;
  const activeId = fromUrl || stored;
  const tab = fromUrl ? params[1] ?? '' : null;

  useEffect(() => {
    if (fromUrl && fromUrl !== stored) setActiveProject(fromUrl);
  }, [fromUrl]);

  // Odświeżamy przy każdej zmianie adresu (nowy / usunięty / przemianowany projekt).
  useEffect(() => {
    listProjects()
      .then((list) => {
        setProjects(list);
        if (!fromUrl && stored && !list.some((p) => p.id === stored)) setActiveProject(0);
      })
      .catch(() => setProjects([]));
  }, [current?.path, params.join('/')]);

  useEffect(() => {
    const onDeleted = (event: Event) => setProjects((list) => list.filter((p) => p.id !== (event as CustomEvent<number>).detail));
    window.addEventListener(PROJECT_DELETED, onDeleted);

    return () => window.removeEventListener(PROJECT_DELETED, onDeleted);
  }, []);

  const choose = (id: number) => {
    setActiveProject(id);
    navigate(id ? `projects/${id}/${tab ?? ''}`.replace(/\/$/, '') : 'projects');
    close();
  };

  useEffect(() => {
    if (!open) return;
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false);
    window.addEventListener('keydown', onKey);

    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  const close = () => setOpen(false);

  return (
    <header className="sticky top-0 z-30 glass border-b lg:static lg:h-screen lg:w-64 lg:shrink-0 lg:border-r lg:border-b-0">
      <div className="flex h-full flex-col">
        <div className="flex items-center justify-between px-4 py-4 lg:px-8 lg:pt-8 lg:pb-10">
          <a href={href('')} className="rounded-lg" onClick={(e) => { e.preventDefault(); navigate(''); close(); }}>
            <Logo />
          </a>
          <button
            type="button"
            className="rounded-lg p-2 text-muted hover:text-white lg:hidden"
            aria-expanded={open}
            aria-controls="clara-nav"
            onClick={() => setOpen(!open)}
          >
            <Icon name={open ? 'close' : 'menu'} className="size-6" />
            <span className="sr-only">{open ? 'Zamknij menu' : 'Otwórz menu'}</span>
          </button>
        </div>

        <nav
          id="clara-nav"
          aria-label="Nawigacja Clara"
          className={`${open ? 'flex' : 'hidden'} flex-1 flex-col gap-6 px-4 pb-6 lg:flex`}
        >
          <ul className="flex flex-col gap-2">
            {main.map((route) => (
              <li key={route.path}>
                <NavLink path={route.path} label={route.label} icon={icons[route.path] ?? 'home'} active={current?.path === route.path && !fromUrl} onNavigate={close} />
              </li>
            ))}
          </ul>

          <div className="flex flex-col gap-2 border-t border-white/5 pt-6" data-testid="sidebar-project">
            <label htmlFor="clara-active-project" className="px-5 text-xs font-semibold tracking-[0.2em] text-subtle uppercase">Projekt</label>
            <div className="px-2">
              <select
                id="clara-active-project"
                value={activeId || ''}
                onChange={(e) => choose(Number(e.target.value))}
                className="w-full rounded-xl border border-white/10 bg-ink-900/60 px-3 py-2.5 text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-300"
              >
                <option value="">Wybierz projekt…</option>
                {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </div>
            {activeId > 0 && (
              <ul className="flex flex-col gap-1" aria-label="Menu projektu">
                {projectTabs.map((t) => (
                  <li key={t.segment}>
                    <NavLink
                      path={`projects/${activeId}${t.segment ? `/${t.segment}` : ''}`}
                      label={t.label}
                      icon={t.icon}
                      active={tab === t.segment}
                      onNavigate={close}
                      compact
                    />
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="mt-auto flex flex-col gap-4">
            <p className="px-5 text-sm text-subtle">
              Zalogowano jako <span className="text-muted">{userName}</span>
            </p>
            {settings && <NavLink path={settings.path} label={settings.label} icon="settings" active={current?.path === 'settings'} onNavigate={close} />}
          </div>
        </nav>
      </div>
    </header>
  );
}
