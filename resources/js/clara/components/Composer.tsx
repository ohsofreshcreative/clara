import { useState } from '@wordpress/element';
import type { FormEvent } from 'react';
import Icon, { type IconName } from './Icon';

export type Mode = 'generate' | 'search';

const modes: { value: Mode; label: string; icon: IconName; action: string }[] = [
  { value: 'generate', label: 'Generuj inspiracje', icon: 'sparkles', action: 'Generuj inspiracje' },
  { value: 'search', label: 'Szukaj zdjęć', icon: 'search', action: 'Szukaj zdjęć' },
];

export const PROMPT_MAX = 2000;

interface Props {
  prompt: string;
  onPromptChange: (value: string) => void;
  onGenerate: (prompt: string) => void;
  onSearch: (query: string) => void;
  busy?: boolean;
}

export default function Composer({ prompt, onPromptChange, onGenerate, onSearch, busy = false }: Props) {
  const [mode, setMode] = useState<Mode>('generate');
  const [message, setMessage] = useState<{ type: 'error' | 'info'; text: string } | null>(null);
  const current = modes.find((item) => item.value === mode) ?? modes[0];

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    const value = prompt.trim();

    if (mode === 'search') {
      if (value.length < 2) {
        setMessage({ type: 'error', text: 'Wpisz, czego szukasz — minimum 2 znaki.' });
        return;
      }
      setMessage(null);
      onSearch(value.slice(0, 200));
      return;
    }

    if (value.length < 5) {
      setMessage({ type: 'error', text: 'Opisz sekcję — np. „Sekcja hero dla krytego basenu H2Otwock, rodzinnie, CTA do grafiku”.' });
      return;
    }

    setMessage(null);
    onGenerate(value);
  };

  return (
    <form
      onSubmit={onSubmit}
      noValidate
      className="glass-strong rounded-3xl border p-3 sm:p-5"
      aria-labelledby="clara-composer-title"
    >
      <h2 id="clara-composer-title" className="sr-only">Nowe zapytanie</h2>

      <fieldset>
        <legend className="sr-only">Tryb</legend>
        <div className="grid grid-cols-2 gap-1 sm:flex sm:gap-2">
          {modes.map((item) => (
            <label
              key={item.value}
              className={`flex cursor-pointer items-center justify-center gap-2 rounded-full px-2 py-2 transition-colors has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-accent-300 sm:px-6 sm:py-2.5 ${
                mode === item.value ? 'bg-accent-600 text-white' : 'text-muted hover:text-white'
              }`}
            >
              <input
                type="radio"
                name="clara-mode"
                value={item.value}
                checked={mode === item.value}
                onChange={() => setMode(item.value)}
                className="sr-only"
              />
              <Icon name={item.icon} className="size-5 shrink-0" />
              {item.label}
            </label>
          ))}
        </div>
      </fieldset>

      <div className="mt-3 rounded-2xl border border-white/10 bg-ink-950/50 p-4 has-[textarea:focus-visible]:outline-2 has-[textarea:focus-visible]:outline-offset-2 has-[textarea:focus-visible]:outline-accent-300 sm:mt-4 sm:flex sm:items-end sm:gap-4 sm:p-5">
        <label htmlFor="clara-prompt" className="sr-only">Opis projektu i sekcji</label>
        <textarea
          id="clara-prompt"
          rows={3}
          maxLength={PROMPT_MAX}
          value={prompt}
          onChange={(event) => onPromptChange(event.target.value)}
          aria-invalid={message?.type === 'error'}
          aria-describedby={message ? 'clara-composer-message' : undefined}
          placeholder={mode === 'generate'
            ? 'Opisz sekcję po polsku…\nnp. „Sekcja hero dla krytego basenu H2Otwock w Otwocku. Rodzinnie, CTA do grafiku zajęć.”'
            : 'Czego ma dotyczyć zdjęcie? np. „kryty basen, dzieci, nauka pływania”'}
          className="block w-full min-w-0 resize-none bg-transparent sm:flex-1 text-white placeholder:text-subtle focus:outline-none"
        />

        <div className="mt-3 flex shrink-0 flex-wrap items-center justify-end gap-2 sm:mt-0">
          <button
            type="submit"
            disabled={busy}
            className="flex w-full items-center justify-center gap-3 rounded-xl bg-accent-600 px-6 py-3 font-medium text-white transition-colors hover:bg-accent-500 disabled:cursor-wait disabled:opacity-70 sm:w-auto"
          >
            {busy ? 'Generuję…' : current.action}
            <Icon name="arrowRight" />
          </button>
        </div>
      </div>

      {message && (
        <p
          id="clara-composer-message"
          role={message.type === 'error' ? 'alert' : 'status'}
          className={`mt-3 px-2 text-sm ${message.type === 'error' ? 'text-red-300' : 'text-accent-300'}`}
        >
          {message.text}
        </p>
      )}
    </form>
  );
}
