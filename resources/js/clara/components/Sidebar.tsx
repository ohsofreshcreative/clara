import { useEffect, useRef, useState } from '@wordpress/element';
import type { MouseEvent } from 'react';
import heroFigure from '../../../images/clara/hero-figure.jpg';
import { listProjects, type Project } from '../api';
import { PROJECT_DELETED, projectTabs, setActiveProject, useActiveProject } from '../activeProject';
import { href, navigate, type Route } from '../router';
import Icon, { type IconName } from './Icon';

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

function NavLink({ path, label, icon, active, onNavigate }: { path: string; label: string; icon: IconName; active: boolean; onNavigate: () => void }) {
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
      className={`flex items-center gap-4 rounded-xl px-5 py-3 transition-colors ${
        active ? 'bg-accent-500/15 text-white shadow-glow' : 'text-muted hover:bg-white/5 hover:text-white'
      }`}
    >
      <Icon name={icon} />
      {label}
    </a>
  );
}

/** Wybór projektu: rozwijana lista projektów + przejście do zarządzania projektami. */
function ProjectPicker({ projects, activeId, onChoose, onManage }: { projects: Project[]; activeId: number; onChoose: (id: number) => void; onManage: () => void }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const active = projects.find((p) => p.id === activeId);

  useEffect(() => {
    if (!open) return;
    const onDown = (event: PointerEvent) => !ref.current?.contains(event.target as Node) && setOpen(false);
    const onKey = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      event.stopPropagation();
      setOpen(false);
      ref.current?.querySelector<HTMLButtonElement>('button')?.focus();
    };
    document.addEventListener('pointerdown', onDown);
    document.addEventListener('keydown', onKey, true);

    return () => {
      document.removeEventListener('pointerdown', onDown);
      document.removeEventListener('keydown', onKey, true);
    };
  }, [open]);

  const pick = (fn: () => void) => () => {
    setOpen(false);
    fn();
  };

  return (
    <div ref={ref} className="relative" data-testid="project-picker">
      <button
        type="button"
        aria-expanded={open}
        aria-controls="clara-project-list"
        onClick={() => setOpen(!open)}
        className="glass-strong flex w-full items-center gap-3 rounded-2xl border px-3 py-3 text-left transition-colors hover:border-accent-400/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-300"
      >
        <span aria-hidden="true" className="grid size-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-accent-400 to-accent-700 font-semibold text-white">
          {active ? active.name.trim().charAt(0).toUpperCase() : '+'}
        </span>
        <span className="flex min-w-0 flex-1 flex-col">
          <span className="text-xs tracking-[0.2em] text-subtle uppercase">Projekt</span>
          <span className="truncate text-white" data-testid="project-picker-name">{active ? active.name : 'Wybierz projekt'}</span>
        </span>
        <Icon name="chevron" className={`size-4 shrink-0 text-muted transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <div id="clara-project-list" className="glass-strong absolute inset-x-0 top-full z-40 mt-2 flex max-h-80 flex-col overflow-y-auto rounded-2xl border p-2">
          <ul className="flex flex-col gap-1" aria-label="Projekty">
            {projects.map((p) => (
              <li key={p.id}>
                <button
                  type="button"
                  aria-current={p.id === activeId ? 'true' : undefined}
                  onClick={pick(() => onChoose(p.id))}
                  className="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left text-muted hover:bg-white/5 hover:text-white aria-[current=true]:text-white"
                >
                  <span className="min-w-0 flex-1 truncate">{p.name}</span>
                  {p.id === activeId && <Icon name="check" className="size-4 shrink-0 text-accent-300" />}
                </button>
              </li>
            ))}
            {projects.length === 0 && <li className="px-3 py-2 text-sm text-subtle">Brak projektów.</li>}
          </ul>
          <button
            type="button"
            onClick={pick(onManage)}
            className="mt-2 flex items-center gap-3 rounded-xl border-t border-white/5 px-3 pt-3 pb-2 text-left text-accent-300 hover:text-white"
          >
            <Icon name="folder" className="size-4" />
            Zarządzaj projektami
          </button>
        </div>
      )}
    </div>
  );
}

export default function Sidebar({ current, params, userName }: { current?: Route; params: string[]; userName: string }) {
  const [open, setOpen] = useState(false);
  const [projects, setProjects] = useState<Project[]>([]);

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

  useEffect(() => {
    if (!open) return;
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false);
    window.addEventListener('keydown', onKey);

    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  const close = () => setOpen(false);

  const choose = (id: number) => {
    setActiveProject(id);
    navigate(`projects/${id}${tab ? `/${tab}` : ''}`);
    close();
  };

  return (
    <header className="sticky top-0 z-30 glass border-b lg:static lg:h-screen lg:w-64 lg:shrink-0 lg:border-r lg:border-b-0">
      <div className="flex h-full flex-col">
        <div className="flex items-center justify-between px-4 py-4 lg:px-8 lg:pt-8 lg:pb-8">
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
          className={`${open ? 'flex' : 'hidden'} min-h-0 flex-1 flex-col gap-6 px-4 pb-6 lg:flex`}
        >
          <ProjectPicker projects={projects} activeId={activeId} onChoose={choose} onManage={() => { navigate('projects'); close(); }} />

          <ul className="flex flex-col gap-1">
            <li>
              <NavLink path="" label="Dashboard" icon="grid" active={current?.path === ''} onNavigate={close} />
            </li>
          </ul>

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
                  />
                </li>
              ))}
            </ul>
          )}

          <div className="mt-auto flex flex-col gap-4">
            {/* Postać — lustrzane odbicie tej z dashboardu, patrzy w stronę treści. Tylko dekoracja. */}
            <div aria-hidden="true" className="pointer-events-none relative -mx-4 hidden h-52 overflow-hidden lg:block">
              <img
                src={heroFigure}
                alt=""
                className="size-full -scale-x-100 object-cover object-[14%_center] opacity-90 [mask-image:linear-gradient(to_bottom,transparent,black_35%,black_75%,transparent),linear-gradient(to_right,transparent,black_15%,black_85%,transparent)] [mask-composite:intersect]"
              />
            </div>
            <p className="px-5 text-sm text-subtle">
              Zalogowano jako <span className="text-muted">{userName}</span>
            </p>
            <NavLink path="settings" label="Ustawienia" icon="settings" active={current?.path === 'settings'} onNavigate={close} />
          </div>
        </nav>
      </div>
    </header>
  );
}
