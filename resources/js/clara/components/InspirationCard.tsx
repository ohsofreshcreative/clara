import type { ReactNode } from 'react';
import type { License } from '../api';
import Icon from './Icon';

interface Props {
  title: string;
  sourceUrl: string;
  thumbnailUrl: string;
  creator: string;
  creatorUrl: string;
  sourceName: string;
  indexedAt?: string;
  license: License | null;
  licenseSource: string | null;
  children?: ReactNode;
}

const host = (url: string) => {
  try {
    return new URL(url).hostname.replace(/^www\./, '');
  } catch {
    return url;
  }
};

/**
 * Karta inspiracji: link do źródła, atrybucja i licencja dokładnie taka, jaką podał dostawca.
 * Brak licencji → „nieznana”, nigdy domysł.
 */
export default function InspirationCard({ title, sourceUrl, thumbnailUrl, creator, creatorUrl, sourceName, indexedAt, license, licenseSource, children }: Props) {
  const name = title || host(sourceUrl);

  return (
    <article className="flex min-w-0 flex-col overflow-hidden glass rounded-2xl border" data-testid="inspiration-card">
      <div className="aspect-[16/10] bg-ink-850">
        {thumbnailUrl ? (
          <img src={thumbnailUrl} alt="" loading="lazy" referrerPolicy="no-referrer" className="size-full object-cover" />
        ) : (
          <span className="grid size-full place-items-center text-subtle" aria-hidden="true"><Icon name="image" className="size-8" /></span>
        )}
      </div>
      <div className="flex flex-1 flex-col gap-2 p-4">
        <h3 className="line-clamp-2 break-words font-semibold">{name}</h3>
        <p className="text-sm text-muted">
          {creator ? (
            <>Autor: {creatorUrl ? <a href={creatorUrl} target="_blank" rel="noopener noreferrer" className="text-accent-300 hover:text-white">{creator}</a> : creator}</>
          ) : 'Autor nieznany'}
          {sourceName && ` · ${sourceName}`}
        </p>
        {indexedAt && <p className="text-xs text-subtle">Zindeksowano: {new Date(indexedAt).toLocaleDateString('pl-PL')}</p>}
        <p className="text-sm" data-testid="license">
          {license ? (
            <>
              Licencja:{' '}
              {license.url ? <a href={license.url} target="_blank" rel="noopener noreferrer license" className="text-accent-300 hover:text-white">{license.name}</a> : license.name}
              <span className="block text-xs text-subtle">wg {licenseSource ?? 'dostawcy'} — zweryfikuj u źródła przed użyciem</span>
            </>
          ) : (
            <span className="text-muted">Licencja nieznana — sprawdź u źródła</span>
          )}
        </p>
        <div className="mt-auto flex flex-wrap items-center gap-2 pt-2">
          <a href={sourceUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 rounded-xl border border-line px-4 py-2 text-sm text-muted hover:border-accent-500 hover:text-white">
            Otwórz źródło <span className="sr-only">(nowa karta): {name}</span>
            <Icon name="arrowRight" className="size-4" />
          </a>
          {children}
        </div>
      </div>
    </article>
  );
}
