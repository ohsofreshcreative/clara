/**
 * Paczka eksportu Clara (format clara-figma-bundle v1) z Layout JSON v1 i osadzonymi obrazami.
 * Walidacja po stronie pluginu powtarza kluczowe reguły serwera — plugin nie ufa danym z pliku.
 */

export type NodeType = 'frame' | 'text' | 'image' | 'rectangle' | 'button';

export interface Frame { x: number; y: number; width: number; height: number }

export interface LayoutNode {
  id: string;
  type: NodeType;
  role?: string;
  name?: string;
  parentId: string | null;
  order: number;
  frame: Frame;
  style: {
    fill?: string; color?: string; opacity?: number; radius?: number; fontFamily?: string; fontSize?: number; fontWeight?: number;
    lineHeight?: number; letterSpacing?: number; textAlign?: 'left' | 'center' | 'right'; stroke?: string; strokeWidth?: number; padding?: number; gap?: number;
  };
  content?: string;
  assetRef?: { generationId: number; crop?: Frame };
  layoutMode: 'none' | 'horizontal' | 'vertical';
  confidence: number;
  rasterized: boolean;
}

export interface Layout {
  schemaVersion: 1;
  canvas: { width: number; height: number; background?: string };
  nodes: LayoutNode[];
  tokens?: { colors?: { name: string; value: string }[] };
  rasterizedRegions: { nodeId: string; reason: string }[];
  warnings: string[];
}

export interface Asset { mime: 'image/png' | 'image/jpeg'; width: number; height: number; data: string }

export interface Bundle {
  format: 'clara-figma-bundle';
  version: 1;
  layoutId: number;
  generationId: number;
  title: string;
  mock?: boolean;
  layout: Layout;
  assets: Record<string, Asset>;
}

export const MAX_BUNDLE_CHARS = 15 * 1024 * 1024;
export const MAX_NODES = 200;
const TYPES: NodeType[] = ['frame', 'text', 'image', 'rectangle', 'button'];
const HEX = /^#[0-9a-f]{6}([0-9a-f]{2})?$/i;
const ID = /^[a-z0-9][a-z0-9_-]{0,63}$/;

const isNum = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v);
const isObj = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v);

export function parseBundle(text: string): { bundle?: Bundle; errors: string[] } {
  if (text.length > MAX_BUNDLE_CHARS) return { errors: ['Plik jest większy niż 15 MB.'] };
  let data: unknown;
  try {
    data = JSON.parse(text);
  } catch {
    return { errors: ['To nie jest poprawny plik JSON z Clara.'] };
  }
  const errors = validateBundle(data);
  return errors.length ? { errors } : { bundle: data as Bundle, errors: [] };
}

export function validateBundle(data: unknown): string[] {
  const e: string[] = [];
  if (!isObj(data) || data.format !== 'clara-figma-bundle') return ['To nie jest paczka eksportu Clara (format clara-figma-bundle).'];
  if (data.version !== 1) return [`Nieobsługiwana wersja paczki: ${String(data.version)} (plugin obsługuje 1).`];
  if (!isNum(data.layoutId) || !isObj(data.layout) || !isObj(data.assets)) return ['Paczka nie zawiera layoutu lub zasobów.'];

  const layout = data.layout;
  if (layout.schemaVersion !== 1) e.push(`Nieobsługiwana wersja Layout JSON: ${String(layout.schemaVersion)}.`);
  const canvas = layout.canvas;
  if (!isObj(canvas) || !isNum(canvas.width) || !isNum(canvas.height) || canvas.width < 320 || canvas.height < 200 || canvas.width > 4096 || canvas.height > 4096) {
    e.push('Nieprawidłowe płótno (320–4096 px).');
  }
  const nodes = layout.nodes;
  if (!Array.isArray(nodes) || nodes.length === 0 || nodes.length > MAX_NODES) {
    e.push(`Layout musi mieć od 1 do ${MAX_NODES} warstw.`);
    return e;
  }

  const byId = new Map<string, Record<string, unknown>>();
  for (const n of nodes) {
    if (isObj(n) && typeof n.id === 'string') {
      if (byId.has(n.id)) e.push(`Powtórzony identyfikator warstwy „${n.id}”.`);
      byId.set(n.id, n);
    }
  }

  let roots = 0;
  nodes.forEach((n: unknown, i: number) => {
    const p = `Warstwa ${i + 1}`;
    if (!isObj(n)) return e.push(`${p}: oczekiwano obiektu.`);
    if (typeof n.id !== 'string' || !ID.test(n.id)) e.push(`${p}: nieprawidłowy identyfikator.`);
    if (!TYPES.includes(n.type as NodeType)) return e.push(`${p}: nieznany typ „${String(n.type)}”.`);
    const f = n.frame;
    if (!isObj(f) || !isNum(f.x) || !isNum(f.y) || !isNum(f.width) || !isNum(f.height) || f.width <= 0 || f.height <= 0) e.push(`${p}: nieprawidłowa ramka.`);
    if (n.parentId === null) {
      roots++;
      if (n.type !== 'frame') e.push(`${p}: korzeń musi być typu frame.`);
    } else {
      const parent = typeof n.parentId === 'string' ? byId.get(n.parentId) : undefined;
      if (!parent || parent.type !== 'frame') e.push(`${p}: rodzic nie istnieje lub nie jest frame.`);
    }
    const style = isObj(n.style) ? n.style : {};
    for (const key of ['fill', 'color', 'stroke']) {
      if (style[key] !== undefined && (typeof style[key] !== 'string' || !HEX.test(style[key] as string))) e.push(`${p}: nieprawidłowy kolor ${key}.`);
    }
    if (n.type === 'text' || n.type === 'button') {
      if (typeof n.content !== 'string' || n.content.trim() === '' || n.content.length > 2000) e.push(`${p}: tekst wymaga treści (1–2000 znaków).`);
      if (n.rasterized === true) e.push(`${p}: tekst nie może być bitmapą.`);
      if (!isNum(style.fontSize) || style.fontSize < 6 || style.fontSize > 400) e.push(`${p}: nieprawidłowy rozmiar fontu.`);
    } else if (n.content !== undefined) {
      e.push(`${p}: treść mają tylko teksty i przyciski.`);
    }
    if (n.type === 'image') {
      const ref = isObj(n.assetRef) ? n.assetRef : null;
      const asset = ref && isObj(data.assets) ? (data.assets as Record<string, unknown>)[String(ref.generationId)] : undefined;
      if (!isObj(asset) || typeof asset.data !== 'string' || !['image/png', 'image/jpeg'].includes(asset.mime as string) || !isNum(asset.width) || !isNum(asset.height)) {
        e.push(`${p}: brak osadzonego obrazu (PNG/JPEG).`);
      }
    }
  });
  if (roots !== 1) e.push('Layout musi mieć dokładnie jeden korzeń.');

  // Cykle w drzewie.
  for (const id of byId.keys()) {
    const seen = new Set<string>();
    let cur: string | null = id;
    while (cur !== null) {
      if (seen.has(cur) || seen.size > 8) {
        e.push(`Cykl lub zbyt głębokie drzewo przy warstwie „${id}”.`);
        break;
      }
      seen.add(cur);
      const parentId: unknown = byId.get(cur)?.parentId;
      cur = typeof parentId === 'string' ? parentId : null;
    }
  }

  return [...new Set(e)].slice(0, 50);
}

export function hexToPaint(hex: string): { color: { r: number; g: number; b: number }; opacity: number } {
  const v = hex.replace('#', '');
  const c = (i: number) => parseInt(v.slice(i, i + 2), 16) / 255;
  return { color: { r: c(0), g: c(2), b: c(4) }, opacity: v.length === 8 ? c(6) : 1 };
}

export const FONT_STYLES: Record<number, string> = { 100: 'Thin', 200: 'Extra Light', 300: 'Light', 400: 'Regular', 500: 'Medium', 600: 'Semi Bold', 700: 'Bold', 800: 'Extra Bold', 900: 'Black' };
