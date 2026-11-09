import { useEffect, useState } from '@wordpress/element';

export interface Route {
  path: string;
  label: string;
}

export const routes: Route[] = [
  { path: '', label: 'Nowe inspiracje' },
  { path: 'projects', label: 'Projekty' },
  { path: 'collections', label: 'Kolekcje' },
  { path: 'library', label: 'Biblioteka' },
  { path: 'settings', label: 'Ustawienia' },
];

export interface Location {
  /** Pozycja sidebaru, do której należy adres (np. `projects` dla `projects/12`). */
  route?: Route;
  /** Segmenty po pozycji sidebaru, np. `['12']`. */
  params: string[];
}

export function href(path: string): string {
  return window.claraConfig.basePath.replace(/\/$/, '') + '/' + path;
}

export function currentPath(): string {
  const base = window.claraConfig.basePath.replace(/\/$/, '');
  const rest = window.location.pathname.startsWith(base) ? window.location.pathname.slice(base.length) : '';

  return rest.replace(/^\/|\/$/g, '');
}

export function navigate(path: string): void {
  window.history.pushState({}, '', href(path));
  window.dispatchEvent(new PopStateEvent('popstate'));
}

export function resolve(path: string): Location {
  const [head = '', ...params] = path.split('/').filter(Boolean);

  return { route: routes.find((route) => route.path === head), params };
}

export function useLocation(): Location {
  const [path, setPath] = useState(currentPath());

  useEffect(() => {
    const onChange = () => setPath(currentPath());
    window.addEventListener('popstate', onChange);

    return () => window.removeEventListener('popstate', onChange);
  }, []);

  return resolve(path);
}
