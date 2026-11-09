import { useState } from '@wordpress/element';
import type { FormEvent } from 'react';
import { deleteSection, errorMessage, fieldErrors, updateSection, type Section } from '../api';
import ArtDirectionsPanel from './ArtDirectionsPanel';
import GalleryPanel from './GalleryPanel';
import { Button, cleanList, inputClass, ListField, Notice, TextAreaField, TextField } from './Field';

const statuses: { value: Section['status']; label: string }[] = [
  { value: 'draft', label: 'Szkic' },
  { value: 'ready', label: 'Gotowa' },
  { value: 'archived', label: 'Zarchiwizowana' },
];

export default function SectionEditor({ section, onChange, onDelete }: { section: Section; onChange: (s: Section) => void; onDelete: (id: number) => void }) {
  const [name, setName] = useState(section.name);
  const [status, setStatus] = useState(section.status);
  const [req, setReq] = useState({
    goal: section.requirements.goal ?? '',
    content: section.requirements.content ?? [],
    hardConstraints: section.requirements.hardConstraints ?? [],
    softConstraints: section.requirements.softConstraints ?? [],
    notes: section.requirements.notes ?? '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<{ type: 'error' | 'success'; text: string } | null>(null);
  const prefix = `section-${section.id}`;

  const save = async (event: FormEvent) => {
    event.preventDefault();
    setErrors({});

    try {
      const updated = await updateSection(section.id, {
        name,
        status,
        requirements: {
          ...req,
          content: cleanList(req.content),
          hardConstraints: cleanList(req.hardConstraints),
          softConstraints: cleanList(req.softConstraints),
        },
      });
      onChange(updated);
      setNotice({ type: 'success', text: 'Zapisano sekcję.' });
    } catch (e) {
      setErrors(fieldErrors(e));
      setNotice({ type: 'error', text: errorMessage(e) });
    }
  };

  const remove = async () => {
    if (!window.confirm(`Usunąć sekcję „${section.name}”?`)) return;

    try {
      await deleteSection(section.id);
      onDelete(section.id);
    } catch (e) {
      setNotice({ type: 'error', text: errorMessage(e) });
    }
  };

  return (
    <details className="glass group rounded-2xl border">
      <summary className="flex cursor-pointer list-none items-center justify-between gap-3 rounded-2xl px-5 py-4">
        <span className="min-w-0">
          <span className="block truncate font-semibold">{section.name}</span>
          <span className="text-sm text-muted">{section.type_label} · {statuses.find((s) => s.value === section.status)?.label}</span>
        </span>
        <span className="text-sm text-accent-300 group-open:hidden">Edytuj</span>
      </summary>

      <form onSubmit={save} noValidate className="grid gap-4 border-t border-line p-5 md:grid-cols-2">
        <TextField id={`${prefix}-name`} label="Nazwa sekcji" value={name} onChange={setName} error={errors.name} />
        <div>
          <label htmlFor={`${prefix}-status`} className="text-sm text-muted">Status</label>
          <select id={`${prefix}-status`} value={status} onChange={(e) => setStatus(e.target.value as Section['status'])} className={inputClass}>
            {statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
        </div>
        <div className="md:col-span-2">
          <TextAreaField id={`${prefix}-goal`} label="Cel sekcji" value={req.goal} onChange={(goal) => setReq({ ...req, goal })} rows={2} maxLength={1000} error={errors['requirements.goal']} />
        </div>
        <ListField id={`${prefix}-content`} label="Wymagane treści" value={req.content} onChange={(content) => setReq({ ...req, content })} error={errors['requirements.content']} />
        <TextAreaField id={`${prefix}-notes`} label="Notatki" value={req.notes} onChange={(notes) => setReq({ ...req, notes })} maxLength={2000} error={errors['requirements.notes']} />
        <ListField id={`${prefix}-hard`} label="Hard constraints — muszą być spełnione" value={req.hardConstraints} onChange={(hardConstraints) => setReq({ ...req, hardConstraints })} error={errors['requirements.hardConstraints']} />
        <ListField id={`${prefix}-soft`} label="Soft constraints — preferencje" value={req.softConstraints} onChange={(softConstraints) => setReq({ ...req, softConstraints })} error={errors['requirements.softConstraints']} />
        <div className="flex flex-wrap items-center gap-3 md:col-span-2">
          <Button type="submit">Zapisz sekcję</Button>
          <Button variant="danger" onClick={remove}>Usuń sekcję</Button>
          {notice && <Notice type={notice.type}>{notice.text}</Notice>}
        </div>
      </form>

      <ArtDirectionsPanel section={section} />
      <GalleryPanel section={section} />
    </details>
  );
}
