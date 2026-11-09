import { useState } from '@wordpress/element';
import { errorMessage, savePreferences, type Accent, type Effects } from '../api';
import { Button, Notice } from '../components/Field';

const ACCENTS: { key: Accent; label: string }[] = [
  { key: 'blue', label: 'Elektryczny niebieski' },
  { key: 'violet', label: 'Fiolet' },
  { key: 'pink', label: 'Róż' },
  { key: 'teal', label: 'Morski' },
  { key: 'sunset', label: 'Zachód słońca' },
];

const SCALES = [
  { value: 75, label: 'Mini' },
  { value: 80, label: 'Bardzo kompaktowa' },
  { value: 90, label: 'Kompaktowa' },
  { value: 100, label: 'Standardowa' },
  { value: 110, label: 'Większa' },
  { value: 125, label: 'Duża' },
];

const EFFECTS: { value: Effects; label: string; hint: string }[] = [
  { value: 'full', label: 'Pełne', hint: 'Szkło, poświata i animowane tło WebGL' },
  { value: 'reduced', label: 'Ograniczone', hint: 'Jednolite panele, bez animacji — lżejsze dla słabszego sprzętu' },
];

type Prefs = { accent: Accent; scale: number; effects: Effects };

/** Atrybuty na <html> sterują tokenami w clara.css — zmiana działa od razu, zapis idzie do profilu. */
const apply = (prefs: Prefs) => {
  document.documentElement.dataset.accent = prefs.accent;
  document.documentElement.dataset.scale = String(prefs.scale);
  document.documentElement.dataset.effects = prefs.effects;
};

export default function Settings() {
  const [prefs, setPrefs] = useState<Prefs>(window.claraConfig.preferences as Prefs);
  const [msg, setMsg] = useState<{ type: 'error' | 'success'; text: string } | null>(null);

  const change = async (next: Partial<Prefs>) => {
    const previous = prefs;
    const updated = { ...prefs, ...next };
    setPrefs(updated);
    apply(updated);
    setMsg(null);
    try {
      const saved = await savePreferences(next);
      window.claraConfig.preferences = { accent: saved.accent, scale: saved.scale, effects: saved.effects };
      setMsg({ type: 'success', text: 'Zapisano ustawienia wyglądu.' });
    } catch (e) {
      setPrefs(previous);
      apply(previous);
      setMsg({ type: 'error', text: errorMessage(e) });
    }
  };

  return (
    <div className="mx-auto flex max-w-[1240px] flex-col gap-6 px-4 py-10 sm:px-8 lg:px-12">
      <header>
        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Ustawienia</h1>
        <p className="mt-2 text-muted">Wygląd panelu Clara — zapisywany w Twoim profilu i stosowany na każdym urządzeniu.</p>
      </header>

      <fieldset className="flex flex-col gap-4 glass rounded-2xl border p-5">
        <legend className="px-1 text-lg font-semibold">Akcent kolorystyczny</legend>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          {ACCENTS.map((accent) => (
            <label key={accent.key} data-accent={accent.key} className="flex cursor-pointer items-center gap-3 rounded-xl border border-line p-3 has-[:checked]:border-accent-500 has-[:checked]:shadow-glow has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-accent-300">
              <input type="radio" name="clara-accent" value={accent.key} checked={prefs.accent === accent.key} onChange={() => change({ accent: accent.key })} className="sr-only" />
              <span aria-hidden="true" className="flex shrink-0 overflow-hidden rounded-full">
                <span className="size-6 bg-accent-600" />
                <span className="size-6 bg-accent-300" />
              </span>
              <span>{accent.label}</span>
            </label>
          ))}
        </div>
      </fieldset>

      <fieldset className="flex flex-col gap-4 glass rounded-2xl border p-5">
        <legend className="px-1 text-lg font-semibold">Skala interfejsu</legend>
        <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {SCALES.map((scale) => (
            <label key={scale.value} className="flex cursor-pointer flex-col rounded-xl border border-line p-3 has-[:checked]:border-accent-500 has-[:checked]:shadow-glow has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-accent-300">
              <input type="radio" name="clara-scale" value={scale.value} checked={prefs.scale === scale.value} onChange={() => change({ scale: scale.value })} className="sr-only" />
              <span>{scale.label}</span>
              <span className="text-muted">{scale.value}%</span>
            </label>
          ))}
        </div>
      </fieldset>

      <fieldset className="flex flex-col gap-4 glass rounded-2xl border p-5">
        <legend className="px-1 text-lg font-semibold">Efekty wizualne</legend>
        <div className="grid gap-3 sm:grid-cols-2">
          {EFFECTS.map((effect) => (
            <label key={effect.value} className="flex cursor-pointer flex-col rounded-xl border border-line p-3 has-[:checked]:border-accent-500 has-[:checked]:shadow-glow has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-accent-300">
              <input type="radio" name="clara-effects" value={effect.value} checked={prefs.effects === effect.value} onChange={() => change({ effects: effect.value })} className="sr-only" />
              <span>{effect.label}</span>
              <span className="text-muted">{effect.hint}</span>
            </label>
          ))}
        </div>
      </fieldset>

      <div className="flex flex-wrap items-center gap-3" role="group" aria-label="Podgląd akcentu">
        <span className="text-muted">Podgląd:</span>
        <Button tabIndex={-1}>Przycisk główny</Button>
        <Button variant="ghost" tabIndex={-1}>Przycisk drugorzędny</Button>
        <span className="text-accent-300">Tekst akcentu</span>
      </div>

      <div>
        <Button variant="ghost" disabled={prefs.accent === 'blue' && prefs.scale === 100 && prefs.effects === 'full'} onClick={() => change({ accent: 'blue', scale: 100, effects: 'full' })}>
          Przywróć domyślne
        </Button>
      </div>

      {msg && <Notice type={msg.type}>{msg.text}</Notice>}
    </div>
  );
}
