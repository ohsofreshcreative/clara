/**
 * Testy importera pluginu Figmy na atrapie Plugin API (node --test, bez Figmy).
 * Paczka: CLARA_FIGMA_FIXTURE (prawdziwy eksport z tests/clara/figma.php) albo paczka wbudowana.
 * To NIE jest test live — ten wykonuje się przez MCP Figmy w realnym pliku.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { parseBundle, validateBundle, type Bundle } from '../../figma-plugin/src/bundle.ts';
import { importBundle } from '../../figma-plugin/src/importer.ts';

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

const builtin: Bundle = {
  format: 'clara-figma-bundle', version: 1, layoutId: 7, generationId: 3, title: 'Clara Hero — wariant A · v1', mock: true,
  layout: {
    schemaVersion: 1,
    canvas: { width: 1440, height: 810, background: '#0b1b3a' },
    nodes: [
      { id: 'hero', type: 'frame', role: 'root', name: 'Hero', parentId: null, order: 0, frame: { x: 0, y: 0, width: 1440, height: 810 }, style: { fill: '#0b1b3a' }, layoutMode: 'none', confidence: 0.9, rasterized: false },
      { id: 'visual', type: 'image', role: 'visual', name: 'Kadr wizualny', parentId: 'hero', order: 1, frame: { x: 749, y: 0, width: 691, height: 810 }, style: {}, assetRef: { generationId: 3, crop: { x: 624, y: 0, width: 576, height: 675 } }, layoutMode: 'none', confidence: 0.7, rasterized: true },
      { id: 'overlay', type: 'rectangle', role: 'overlay', name: 'Nakładka', parentId: 'hero', order: 2, frame: { x: 60, y: 140, width: 640, height: 520 }, style: { fill: '#0b1b3a', opacity: 0.78, radius: 24 }, layoutMode: 'none', confidence: 0.5, rasterized: false },
      { id: 'content', type: 'frame', role: 'container', name: 'Treść', parentId: 'hero', order: 3, frame: { x: 86, y: 178, width: 576, height: 470 }, style: { gap: 24 }, layoutMode: 'vertical', confidence: 0.6, rasterized: false },
      { id: 'headline-1', type: 'text', role: 'headline', name: 'Headline', parentId: 'content', order: 4, frame: { x: 86, y: 178, width: 576, height: 141 }, style: { color: '#ffffff', fontFamily: 'Poppins', fontSize: 64, fontWeight: 700, lineHeight: 1.1, textAlign: 'left' }, content: 'Basen H2Otwock', layoutMode: 'none', confidence: 0.6, rasterized: false },
      { id: 'body-2', type: 'text', role: 'body', name: 'Body', parentId: 'content', order: 5, frame: { x: 86, y: 343, width: 576, height: 27 }, style: { color: '#ffffff', fontFamily: 'Inter', fontSize: 18, fontWeight: 400, lineHeight: 1.5, textAlign: 'left' }, content: 'Otwarte codziennie 6:00–22:00', layoutMode: 'none', confidence: 0.6, rasterized: false },
      { id: 'cta-3', type: 'button', role: 'cta', name: 'Cta', parentId: 'content', order: 6, frame: { x: 86, y: 394, width: 300, height: 58 }, style: { color: '#0b1b3a', fill: '#00a6df', radius: 999, padding: 18, fontFamily: 'Inter', fontSize: 18, fontWeight: 600, lineHeight: 1.2, textAlign: 'center' }, content: 'Kup bilet', layoutMode: 'none', confidence: 0.6, rasterized: false },
    ],
    tokens: { colors: [{ name: 'Granat', value: '#0b1b3a' }, { name: 'Biel', value: '#ffffff' }, { name: 'Woda', value: '#00a6df' }] },
    rasterizedRegions: [{ nodeId: 'visual', reason: 'Fotografia — pozostaje bitmapą.' }],
    warnings: ['Układ odtworzony heurystycznie.'],
  },
  assets: { 3: { mime: 'image/png', width: 1200, height: 675, data: PNG_1X1 } },
};

const fixture = process.env.CLARA_FIGMA_FIXTURE;
const real: Bundle | null = fixture ? JSON.parse(readFileSync(fixture, 'utf8')) : null;
const clone = <T>(v: T): T => JSON.parse(JSON.stringify(v));

/** Atrapa Plugin API: tylko to, czego używa importer, z kontrolą fontów i zmiennych. */
function fakeFigma(opts: { fonts?: string[]; existing?: Array<{ name: string; layoutId?: string; x: number; width: number }>; variablesFail?: boolean } = {}) {
  let seq = 0;
  const available = new Set(opts.fonts ?? ['Inter|Regular', 'Inter|Semi Bold', 'Inter|Bold', 'Poppins|Bold']);
  const loaded: string[] = [];
  const all: any[] = [];
  const node = (type: string): any => {
    const n: any = {
      id: `${++seq}:1`, type, name: '', x: 0, y: 0, width: 100, height: 100, removed: false, children: [] as any[], data: {} as Record<string, string>, fills: [],
      resize(w: number, h: number) { this.width = w; this.height = h; },
      setSharedPluginData(ns: string, k: string, v: string) { this.data[`${ns}:${k}`] = v; },
      getSharedPluginData(ns: string, k: string) { return this.data[`${ns}:${k}`] ?? ''; },
      appendChild(c: any) { if (c.parent) c.parent.children = c.parent.children.filter((x: any) => x !== c); c.parent = this; this.children.push(c); },
      remove() { this.removed = true; if (this.parent) this.parent.children = this.parent.children.filter((x: any) => x !== this); },
    };
    if (type === 'TEXT') {
      Object.defineProperty(n, 'characters', {
        get() { return this._chars; },
        set(v: string) { if (!this.fontName || !loaded.includes(`${this.fontName.family}|${this.fontName.style}`)) throw new Error('Font not loaded'); this._chars = v; },
      });
    }
    all.push(n);
    return n;
  };
  const page: any = node('PAGE');
  for (const e of opts.existing ?? []) {
    const f = node('FRAME');
    Object.assign(f, { name: e.name, x: e.x, width: e.width, y: 40 });
    if (e.layoutId) f.setSharedPluginData('clara', 'clara-layout', e.layoutId);
    page.appendChild(f);
  }
  const collections: any[] = [];
  const variables = new Map<string, any>();
  const api: any = {
    currentPage: page,
    viewport: { scrollAndZoomIntoView() {} },
    createFrame: () => node('FRAME'),
    createRectangle: () => node('RECTANGLE'),
    createText: () => node('TEXT'),
    createImage: (bytes: Uint8Array) => ({ hash: `img-${bytes.length}` }),
    base64Decode: (s: string) => new Uint8Array(Buffer.from(s, 'base64')),
    async loadFontAsync(f: { family: string; style: string }) {
      const key = `${f.family}|${f.style}`;
      if (!available.has(key)) throw new Error(`The font "${f.family} ${f.style}" could not be loaded`);
      loaded.push(key);
    },
    variables: {
      async getLocalVariableCollectionsAsync() { if (opts.variablesFail) throw new Error('Variables unavailable'); return collections; },
      createVariableCollection(name: string) { const c = { name, modes: [{ modeId: 'm1' }], variableIds: [] as string[] }; collections.push(c); return c; },
      createVariable(name: string, c: any) { const v = { id: `v${variables.size + 1}`, name, valuesByMode: {} as any, setValueForMode(m: string, val: any) { this.valuesByMode[m] = val; } }; variables.set(v.id, v); c.variableIds.push(v.id); return v; },
      async getVariableByIdAsync(id: string) { return variables.get(id) ?? null; },
      setBoundVariableForPaint(paint: any, _f: string, v: any) { return { ...paint, boundVariables: { color: { type: 'VARIABLE_ALIAS', id: v.id } } }; },
    },
  };
  const walk = (n: any): any[] => [n, ...n.children.flatMap(walk)];
  return { api, page, all, loaded, collections, variables, walk };
}

for (const [label, bundle] of [['paczka wbudowana', builtin], ...(real ? [['prawdziwy eksport Clara', real]] : [])] as const) {
  test(`happy path (${label}): frame, teksty, bitmapa, Auto Layout, tokeny`, async () => {
    const f = fakeFigma();
    const report = await importBundle(f.api, clone(bundle));
    assert.equal(report.ok, true, report.errors.join('; '));
    const root = f.page.children[0];
    assert.equal(root.type, 'FRAME');
    assert.equal(root.width, bundle.layout.canvas.width);
    assert.equal(root.getSharedPluginData('clara', 'clara-layout'), String(bundle.layoutId));
    const nodes = f.walk(root);
    const texts = nodes.filter((n) => n.type === 'TEXT');
    const contents = bundle.layout.nodes.filter((n) => n.content).map((n) => n.content);
    assert.deepEqual(texts.map((t) => t._chars).sort(), [...contents].sort(), 'każda treść jest warstwą tekstową');
    const image = nodes.find((n) => n.fills?.[0]?.type === 'IMAGE');
    assert.ok(image, 'bitmapa jako wypełnienie obrazem');
    assert.equal(image.fills[0].scaleMode, 'CROP');
    assert.ok(image.name.startsWith('Bitmapa'));
    assert.ok(image.getSharedPluginData('clara', 'clara-raster').length > 0, 'powód rasteryzacji zapisany');
    const auto = nodes.filter((n) => n.layoutMode === 'VERTICAL');
    assert.equal(auto.length, bundle.layout.nodes.filter((n) => n.layoutMode === 'vertical').length);
    assert.ok(nodes.some((n) => n.type === 'FRAME' && n.layoutMode === 'HORIZONTAL' && n.children[0]?.type === 'TEXT'), 'CTA jako frame z Auto Layout i tekstem');
    assert.equal(report.texts, contents.length);
    assert.equal(report.bitmaps, bundle.layout.rasterizedRegions.length);
    assert.equal(report.variables.created, bundle.layout.tokens?.colors?.length ?? 0);
    assert.ok(nodes.some((n) => n.fills?.[0]?.boundVariables), 'kolory powiązane ze zmiennymi tokenów');
    assert.ok(nodes.every((n) => n.getSharedPluginData('clara', 'clara') !== '' ), 'każda warstwa oznaczona pluginData');
  });
}

test('pozycje: dzieci względem rodzica, Auto Layout zarządza pozycją w kolumnie', async () => {
  const f = fakeFigma();
  await importBundle(f.api, clone(builtin));
  const root = f.page.children[0];
  const visual = root.children.find((n: any) => n.name.includes('Kadr'));
  assert.equal(visual.x, 749);
  const crop = visual.fills[0].imageTransform;
  assert.deepEqual(crop, [[576 / 1200, 0, 624 / 1200], [0, 1, 0]]);
  const overlay = root.children.find((n: any) => n.name === 'Nakładka');
  assert.equal(overlay.fills[0].opacity.toFixed(2), '0.78');
});

test('błędy fontów: brak kroju → fallback Inter i raport', async () => {
  const f = fakeFigma({ fonts: ['Inter|Regular', 'Inter|Bold', 'Inter|Semi Bold'] });
  const report = await importBundle(f.api, clone(builtin));
  assert.equal(report.ok, true);
  assert.equal(report.fontIssues.length, 1);
  assert.deepEqual([report.fontIssues[0].requested, report.fontIssues[0].used], ['Poppins Bold', 'Inter Bold']);
  assert.match(report.fontIssues[0].reason, /could not be loaded/);
});

test('błędy fontów: brak stylu → Regular tego samego kroju', async () => {
  const f = fakeFigma({ fonts: ['Inter|Regular', 'Poppins|Regular'] });
  const report = await importBundle(f.api, clone(builtin));
  assert.equal(report.ok, true);
  assert.ok(report.fontIssues.some((i) => i.requested === 'Poppins Bold' && i.used === 'Poppins Regular'));
  assert.ok(report.fontIssues.some((i) => i.requested === 'Inter Semi Bold' && i.used === 'Inter Regular'));
});

test('błędy fontów: brak nawet Inter Regular → import przerwany bez warstw', async () => {
  const f = fakeFigma({ fonts: [] });
  const report = await importBundle(f.api, clone(builtin));
  assert.equal(report.ok, false);
  assert.match(report.errors[0] ?? '', /Brak dostępnych fontów/);
  assert.equal(f.page.children.length, 0);
});

test('nie nadpisuje istniejących frame’ów: nowy frame obok, cudze warstwy nietknięte', async () => {
  const f = fakeFigma({ existing: [{ name: 'Projekt klienta', x: 0, width: 1600 }] });
  const report = await importBundle(f.api, clone(builtin));
  assert.equal(report.ok, true);
  assert.equal(f.page.children.length, 2);
  assert.equal(f.page.children[0].name, 'Projekt klienta');
  assert.equal(f.page.children[0].removed, false);
  assert.equal(f.page.children[1].x, 1800);
});

test('ponowny import bez wyboru → kopia; „zastąp” usuwa tylko import tego layoutu', async () => {
  const f = fakeFigma({ existing: [{ name: 'Projekt klienta', x: 0, width: 800 }, { name: 'Clara Hero (stary)', layoutId: '7', x: 1000, width: 1440 }, { name: 'Inny layout Clara', layoutId: '8', x: 2600, width: 1440 }] });
  const copy = await importBundle(f.api, clone(builtin));
  assert.equal(copy.replaced, 0);
  assert.match(copy.frameName ?? '', /\(import 2\)$/);
  assert.equal(f.page.children.length, 4);
  const replaced = await importBundle(f.api, clone(builtin), { replace: true });
  assert.equal(replaced.ok, true);
  assert.equal(replaced.replaced, 2);
  assert.deepEqual(f.page.children.map((n: any) => n.name).sort(), ['Clara Hero — wariant A · v1', 'Inny layout Clara', 'Projekt klienta'].sort());
});

test('tokeny: istniejące zmienne nie są nadpisywane', async () => {
  const f = fakeFigma();
  await importBundle(f.api, clone(builtin));
  const changed = clone(builtin);
  changed.layout.tokens!.colors![2].value = '#ff0000';
  const report = await importBundle(f.api, changed);
  assert.equal(report.variables.created, 0);
  assert.equal(report.variables.reused, 3);
  assert.ok(report.warnings.some((w) => w.includes('pozostawiono istniejącą')));
  assert.equal(f.collections.length, 1);
});

test('tokeny: niedostępne zmienne → ostrzeżenie, import kontynuowany', async () => {
  const f = fakeFigma({ variablesFail: true });
  const report = await importBundle(f.api, clone(builtin));
  assert.equal(report.ok, true);
  assert.ok(report.warnings.some((w) => w.startsWith('Nie utworzono zmiennych')));
});

const invalid: Array<[string, (b: any) => void, RegExp]> = [
  ['zły format', (b) => { b.format = 'inny'; }, /clara-figma-bundle/],
  ['nowsza wersja paczki', (b) => { b.version = 2; }, /wersja paczki/],
  ['nowsza wersja layoutu', (b) => { b.layout.schemaVersion = 2; }, /Layout JSON/],
  ['brak warstw', (b) => { b.layout.nodes = []; }, /od 1 do 200/],
  ['dwa korzenie', (b) => { b.layout.nodes[3].parentId = null; }, /korzeń/],
  ['tekst bez treści', (b) => { b.layout.nodes[4].content = ''; }, /wymaga treści/],
  ['tekst jako bitmapa', (b) => { b.layout.nodes[4].rasterized = true; }, /nie może być bitmapą/],
  ['zły kolor', (b) => { b.layout.nodes[4].style.color = 'red'; }, /kolor/],
  ['obraz bez danych', (b) => { b.assets = {}; }, /osadzonego obrazu/],
  ['obraz GIF/SVG', (b) => { b.assets[3].mime = 'image/svg+xml'; }, /osadzonego obrazu/],
  ['cykl', (b) => { b.layout.nodes[3].parentId = 'headline-1'; b.layout.nodes[4].type = 'frame'; delete b.layout.nodes[4].content; }, /Cykl|rodzic/],
  ['rodzic nie istnieje', (b) => { b.layout.nodes[1].parentId = 'brak'; }, /rodzic/],
];
for (const [label, mutate, message] of invalid) {
  test(`błędne dane: ${label} → odrzucone, bez warstw`, async () => {
    const b = clone(builtin);
    mutate(b);
    const f = fakeFigma();
    const report = await importBundle(f.api, b);
    assert.equal(report.ok, false);
    assert.ok(report.errors.some((e) => message.test(e)), report.errors.join('; '));
    assert.equal(f.page.children.length, 0);
  });
}

test('parseBundle: uszkodzony JSON i limit rozmiaru', () => {
  assert.match(parseBundle('{"format":').errors[0], /poprawny plik JSON/);
  assert.match(parseBundle(' '.repeat(15 * 1024 * 1024 + 1)).errors[0], /15 MB/);
  assert.deepEqual(validateBundle(clone(builtin)), []);
});
