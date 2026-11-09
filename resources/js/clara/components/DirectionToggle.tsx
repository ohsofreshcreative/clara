import type { ProjectDirection } from '../api';

/** Opcja „trzymaj się kierunku projektu” z miniaturą obranego wariantu. */
export default function DirectionToggle({ direction, checked, onChange }: { direction: ProjectDirection; checked: boolean; onChange: (value: boolean) => void }) {
  return (
    <label className={`glass flex cursor-pointer items-center gap-4 rounded-2xl border p-3 transition has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-accent-300 ${checked ? 'border-accent-400/60 shadow-glow' : ''}`} data-testid="direction-toggle">
      <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="size-5 shrink-0 accent-accent-500" />
      {direction.imageUrl && <img src={direction.imageUrl} alt="" className="h-12 w-18 shrink-0 rounded-lg border border-white/10 object-cover" />}
      <span className="min-w-0">
        <span className="block font-medium">Trzymaj się kierunku projektu</span>
        <span className="block truncate text-sm text-muted">{direction.title} — kolejne 4 warianty w tym samym stylu (paleta, typografia, zdjęcia)</span>
      </span>
    </label>
  );
}
