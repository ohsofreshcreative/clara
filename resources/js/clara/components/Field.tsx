import type { ButtonHTMLAttributes, ReactNode } from 'react';

export const inputClass =
  'mt-1.5 block w-full rounded-xl border border-line bg-ink-850 px-4 py-2.5 text-white placeholder:text-subtle aria-[invalid=true]:border-red-400';

interface BaseProps {
  id: string;
  label: string;
  error?: string;
  hint?: string;
}

function Wrapper({ id, label, error, hint, children }: BaseProps & { children: ReactNode }) {
  return (
    <div>
      <label htmlFor={id} className="text-sm text-muted">
        {label}
      </label>
      {children}
      {hint && !error && (
        <p id={`${id}-hint`} className="mt-1 text-xs text-subtle">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} className="mt-1 text-sm text-red-300">
          {error}
        </p>
      )}
    </div>
  );
}

const described = (id: string, error?: string, hint?: string) =>
  error ? `${id}-error` : hint ? `${id}-hint` : undefined;

export function TextField(props: BaseProps & { value: string; onChange: (v: string) => void; type?: string; required?: boolean; maxLength?: number; placeholder?: string }) {
  const { id, error, hint, value, onChange, type = 'text', required, maxLength = 191, placeholder } = props;

  return (
    <Wrapper {...props}>
      <input
        id={id}
        type={type}
        value={value}
        required={required}
        maxLength={maxLength}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={Boolean(error)}
        aria-describedby={described(id, error, hint)}
        className={inputClass}
      />
    </Wrapper>
  );
}

export function TextAreaField(props: BaseProps & { value: string; onChange: (v: string) => void; rows?: number; maxLength?: number }) {
  const { id, error, hint, value, onChange, rows = 3, maxLength = 4000 } = props;

  return (
    <Wrapper {...props}>
      <textarea
        id={id}
        rows={rows}
        value={value}
        maxLength={maxLength}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={Boolean(error)}
        aria-describedby={described(id, error, hint)}
        className={inputClass}
      />
    </Wrapper>
  );
}

/** Lista tekstów edytowana jako jedna pozycja na linię. */
export function ListField(props: BaseProps & { value: string[]; onChange: (v: string[]) => void; rows?: number }) {
  const { value, onChange, ...rest } = props;

  return (
    <TextAreaField
      {...rest}
      hint={rest.hint ?? 'Jedna pozycja na linię.'}
      value={value.join('\n')}
      onChange={(text) => onChange(text.split('\n'))}
    />
  );
}

export const cleanList = (items: string[] = []) => items.map((item) => item.trim()).filter(Boolean);

export function Button({ children, variant = 'primary', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'ghost' | 'danger' }) {
  const styles = {
    primary: 'bg-accent-600 text-white hover:bg-accent-500',
    ghost: 'border border-line text-muted hover:text-white hover:border-accent-500',
    danger: 'border border-red-400/50 text-red-200 hover:bg-red-950/50',
  };

  return (
    <button
      type="button"
      {...props}
      className={`inline-flex items-center justify-center gap-2 rounded-xl px-5 py-2.5 font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${styles[variant]} ${props.className ?? ''}`}
    >
      {children}
    </button>
  );
}

export function Notice({ type, children }: { type: 'error' | 'success'; children: ReactNode }) {
  return (
    <p
      role={type === 'error' ? 'alert' : 'status'}
      className={`rounded-xl border px-4 py-3 text-sm ${type === 'error' ? 'border-red-400/40 bg-red-950/40 text-red-200' : 'border-accent-500/40 bg-accent-600/10 text-accent-300'}`}
    >
      {children}
    </p>
  );
}
