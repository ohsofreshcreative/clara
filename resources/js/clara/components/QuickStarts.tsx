import Icon, { type IconName } from './Icon';

export interface QuickStart {
  id: string;
  title: string;
  description: string;
  icon: IconName;
  prompt: string;
}

export const quickStarts: QuickStart[] = [
  { id: 'hero', title: 'Sekcja hero', description: 'Pierwsze wrażenie i kluczowy przekaz', icon: 'image', prompt: 'Szukam inspiracji na sekcję hero: nagłówek, krótki opis, CTA i zdjęcie obiektu.' },
  { id: 'about', title: 'O nas', description: 'Przedstawienie obiektu, zespołu lub misji', icon: 'users', prompt: 'Szukam inspiracji na sekcję „O nas”: historia, zespół i misja.' },
  { id: 'services', title: 'Usługi / atrakcje', description: 'Oferta, strefy, aktywności', icon: 'layers', prompt: 'Szukam inspiracji na sekcję usług i atrakcji: kafelki stref i aktywności.' },
  { id: 'pricing', title: 'Cennik', description: 'Przejrzysta prezentacja cen i pakietów', icon: 'tag', prompt: 'Szukam inspiracji na sekcję cennika: pakiety, ceny i wyróżniona oferta.' },
];

export default function QuickStarts({ onPick }: { onPick: (start: QuickStart) => void }) {
  return (
    <section aria-labelledby="clara-quick-title">
      <h2 id="clara-quick-title" className="text-xl font-semibold sm:text-2xl">Szybkie starty</h2>
      <ul className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {quickStarts.map((start) => (
          <li key={start.id}>
            <button
              type="button"
              onClick={() => onPick(start)}
              className="group flex h-full w-full items-start gap-4 glass rounded-2xl border p-5 text-left transition hover:border-accent-400 hover:shadow-glow hover:bg-ink-800"
            >
              <Icon name={start.icon} className="size-6 shrink-0 text-muted group-hover:text-accent-300" />
              <span className="flex min-w-0 flex-1 flex-col gap-1">
                <span className="font-semibold">{start.title}</span>
                <span className="text-sm text-muted">{start.description}</span>
              </span>
              <Icon name="arrowRight" className="mt-auto size-4 shrink-0 text-muted" />
            </button>
          </li>
        ))}
      </ul>
    </section>
  );
}
