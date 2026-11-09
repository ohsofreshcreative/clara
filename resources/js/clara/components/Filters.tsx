import { type Project } from '../api';
import Icon, { type IconName } from './Icon';

interface Filter {
  id: string;
  label: string;
  icon: IconName;
  empty: string;
  options: { value: string; label: string }[];
}

export default function Filters({ projects, project, onProjectChange }: { projects: Project[]; project: number; onProjectChange: (id: number) => void }) {
  const clients = [...new Set(projects.map((p) => p.client_name).filter(Boolean))];

  const withDs = projects.filter((p) => p.design_system_version);

  const filters: Filter[] = [
    { id: 'project', label: 'Projekt', icon: 'building', empty: 'Brak projektów', options: projects.map((p) => ({ value: String(p.id), label: p.name })) },
    { id: 'design-system', label: 'Design system', icon: 'layers', empty: 'Brak design systemu', options: withDs.map((p) => ({ value: String(p.id), label: `${p.name} · v${p.design_system_version}` })) },
    { id: 'client', label: 'Klient', icon: 'users', empty: 'Brak klientów', options: clients.map((c) => ({ value: c, label: c })) },
  ];

  return (
    <div className="grid gap-3 md:grid-cols-3">
      {filters.map((filter) => (
        <div
          key={filter.id}
          className="relative flex min-w-0 items-center gap-3 glass rounded-2xl border px-4 py-3 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-accent-300"
        >
          <Icon name={filter.icon} className="size-5 shrink-0 text-muted" />
          <label htmlFor={`clara-filter-${filter.id}`} className="shrink-0">
            {filter.label}
          </label>
          <select
            id={`clara-filter-${filter.id}`}
            className="min-w-0 flex-1 appearance-none truncate bg-transparent pr-6 text-muted focus:outline-none"
            {...(filter.id === 'project'
              ? { value: project ? String(project) : '', onChange: (e: { target: { value: string } }) => onProjectChange(Number(e.target.value)) }
              : { defaultValue: '' })}
          >
            <option value="" className="bg-ink-900">{filter.options.length ? 'Wszystkie' : filter.empty}</option>
            {filter.options.map((option) => (
              <option key={option.value} value={option.value} className="bg-ink-900">{option.label}</option>
            ))}
          </select>
          <Icon name="chevronDown" className="pointer-events-none absolute right-4 size-4 text-muted" />
        </div>
      ))}
    </div>
  );
}
