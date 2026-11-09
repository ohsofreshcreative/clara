import { useEffect, useState } from '@wordpress/element';
import { createFigmaToken, errorMessage, getFigmaBundle, type FigmaToken } from '../api';
import { Button, Notice } from './Field';

/**
 * Eksport bieżącej wersji layoutu do pluginu „Clara — import Hero”: plik do wczytania w pluginie
 * albo jednorazowy kod (10 min). Kod pokazujemy tylko w tym widoku — nie trafia do adresu ani logów.
 */
export default function FigmaExport({ layoutId }: { layoutId: number }) {
  const [token, setToken] = useState<FigmaToken | null>(null);
  const [msg, setMsg] = useState<{ type: 'error' | 'success'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    setToken(null);
    setMsg(null);
  }, [layoutId]);

  const run = async (task: () => Promise<void>) => {
    setBusy(true);
    setMsg(null);
    try {
      await task();
    } catch (error) {
      setMsg({ type: 'error', text: errorMessage(error) });
    } finally {
      setBusy(false);
    }
  };

  const download = () => run(async () => {
    const bundle = await getFigmaBundle(layoutId);
    const url = URL.createObjectURL(new Blob([JSON.stringify(bundle)], { type: 'application/json' }));
    Object.assign(document.createElement('a'), { href: url, download: `clara-figma-layout-${layoutId}.json` }).click();
    URL.revokeObjectURL(url);
    setMsg({ type: 'success', text: 'Pobrano paczkę — wczytaj ją w pluginie Figmy.' });
  });

  const code = () => run(async () => setToken(await createFigmaToken(layoutId)));

  const copy = () => run(async () => {
    await navigator.clipboard.writeText(token?.token ?? '');
    setMsg({ type: 'success', text: 'Skopiowano kod.' });
  });

  return (
    <section className="flex flex-col gap-3 rounded-xl border border-line p-4" aria-labelledby={`figma-${layoutId}`} data-testid="figma-export">
      <h4 id={`figma-${layoutId}`}>Eksport do Figmy</h4>
      <p className="text-muted">
        Plugin „Clara — import Hero” tworzy nowy frame obok istniejących warstw: teksty i przycisk są edytowalne, a zdjęcie
        i efekty zostają bitmapami. To nie jest odwzorowanie pixel-perfect.
      </p>
      <div className="flex flex-wrap gap-3">
        <Button variant="ghost" disabled={busy} onClick={download}>Pobierz paczkę dla Figmy</Button>
        <Button variant="ghost" disabled={busy} onClick={code}>Wygeneruj kod jednorazowy</Button>
      </div>
      {token && (
        <div className="flex flex-col gap-2" data-testid="figma-token">
          <label className="flex flex-col gap-1">
            <span>Kod dla pluginu (jednorazowy, ważny do {new Date(token.expiresAt).toLocaleTimeString('pl-PL')})</span>
            <input readOnly value={token.token} className="rounded-xl border border-line bg-transparent px-3 py-2 font-mono" onFocus={(e) => e.currentTarget.select()} />
          </label>
          <p className="text-muted">Adres Clara w pluginie: <code>{new URL(token.endpoint).origin}</code></p>
          <div><Button variant="ghost" onClick={copy}>Kopiuj kod</Button></div>
        </div>
      )}
      {msg && <Notice type={msg.type}>{msg.text}</Notice>}
    </section>
  );
}
