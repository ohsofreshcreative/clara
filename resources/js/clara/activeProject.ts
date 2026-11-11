import { useEffect, useState } from '@wordpress/element';

/**
 * Projekt wybrany w sidebarze. Na stronach projektu wynika z adresu; poza nimi pamiętamy ostatni
 * wybór (wygoda per przeglądarka — dostęp do localStorage może być zablokowany).
 */
const KEY = 'clara:active-project';
const EVENT = 'clara:active-project';

export function getActiveProject(): number {
  try {
    return Number(window.localStorage.getItem(KEY)) || 0;
  } catch {
    return 0;
  }
}

export function setActiveProject(id: number): void {
  try {
    if (id) window.localStorage.setItem(KEY, String(id));
    else window.localStorage.removeItem(KEY);
  } catch {
    // brak dostępu do storage — wybór działa do przeładowania strony
  }
  window.dispatchEvent(new CustomEvent(EVENT, { detail: id }));
}

/** Usunięty projekt znika z sidebaru od razu, bez czekania na ponowne pobranie listy. */
export const PROJECT_DELETED = 'clara:project-deleted';

export function projectDeleted(id: number): void {
  if (getActiveProject() === id) setActiveProject(0);
  window.dispatchEvent(new CustomEvent(PROJECT_DELETED, { detail: id }));
}

export function useActiveProject(): number {
  const [id, setId] = useState(getActiveProject);

  useEffect(() => {
    const onChange = (event: Event) => setId(Number((event as CustomEvent<number>).detail) || 0);
    window.addEventListener(EVENT, onChange);

    return () => window.removeEventListener(EVENT, onChange);
  }, []);

  return id;
}

/** Podstrony projektu w sidebarze: `projects/:id` + segment. */
export const projectTabs = [
  { segment: '', label: 'Przegląd', icon: 'folder' },
  { segment: 'generate', label: 'Generowanie', icon: 'sparkles' },
  { segment: 'style', label: 'Styl', icon: 'layers' },
] as const;

export type ProjectTab = (typeof projectTabs)[number]['segment'];
