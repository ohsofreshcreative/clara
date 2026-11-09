import { useEffect, useState } from '@wordpress/element';
import type { MouseEvent } from 'react';
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

function NavLink({ route, active, onNavigate }: { route: Route; active: boolean; onNavigate: () => void }) {
  const onClick = (event: MouseEvent<HTMLAnchorElement>) => {
    event.preventDefault();
    navigate(route.path);
    onNavigate();
  };

  return (
    <a
      href={href(route.path)}
      onClick={onClick}
      aria-current={active ? 'page' : undefined}
      className={`flex items-center gap-4 rounded-xl px-5 py-3.5 transition-colors ${
        active ? 'bg-accent-500/15 text-white shadow-glow' : 'text-muted hover:bg-white/5 hover:text-white'
      }`}
    >
      <Icon name={icons[route.path] ?? 'home'} />
      {route.label}
    </a>
  );
}

export default function Sidebar({ current, userName }: { current?: Route; userName: string }) {
  const [open, setOpen] = useState(false);
  const main = routes.filter((route) => route.path !== 'settings');
  const settings = routes.find((route) => route.path === 'settings');

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
                <NavLink route={route} active={current?.path === route.path} onNavigate={close} />
              </li>
            ))}
          </ul>

          <div className="mt-auto flex flex-col gap-4">
            <p className="px-5 text-sm text-subtle">
              Zalogowano jako <span className="text-muted">{userName}</span>
            </p>
            {settings && <NavLink route={settings} active={current?.path === 'settings'} onNavigate={close} />}
          </div>
        </nav>
      </div>
    </header>
  );
}
