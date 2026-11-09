import { FONT_STYLES, hexToPaint, validateBundle, type Bundle, type LayoutNode } from './bundle.ts';

export interface ImportOptions {
  /** Zastąp poprzedni import TEGO layoutu (tylko warstwy utworzone przez Clara). Domyślnie: nowa kopia obok. */
  replace?: boolean;
}

export interface FontIssue { requested: string; used: string; reason: string }

export interface ImportReport {
  ok: boolean;
  errors: string[];
  frameId?: string;
  frameName?: string;
  created: Record<string, number>;
  texts: number;
  bitmaps: number;
  autoLayoutFrames: number;
  fontIssues: FontIssue[];
  variables: { collection: string | null; created: number; reused: number };
  warnings: string[];
  replaced: number;
}

// Shared plugin data: działa w każdym runtime Figmy (także MCP); zapisujemy tylko identyfikatory layoutu.
const NS = 'clara';
const DATA_KEY = 'clara';
const GAP = 200;

/**
 * Tworzy edytowalny Hero w bieżącej stronie: frame, teksty (prawdziwe warstwy tekstowe), obrazy
 * (bitmapy z kadrem), proste kształty, Auto Layout tam, gdzie layout go deklaruje. Nigdy nie
 * modyfikuje istniejących warstw — nowy frame trafia obok treści strony; tryb „replace” usuwa
 * wyłącznie wcześniejsze importy tego samego layoutu (rozpoznane po pluginData), i to dopiero
 * po udanym utworzeniu nowego.
 */
export async function importBundle(api: PluginAPI, bundle: Bundle, options: ImportOptions = {}): Promise<ImportReport> {
  const report: ImportReport = { ok: false, errors: [], created: {}, texts: 0, bitmaps: 0, autoLayoutFrames: 0, fontIssues: [], variables: { collection: null, created: 0, reused: 0 }, warnings: [...(bundle.layout?.warnings ?? [])], replaced: 0 };

  const errors = validateBundle(bundle);
  if (errors.length) return { ...report, errors };

  const { layout } = bundle;
  const page = api.currentPage;
  await page.loadAsync?.();
  const previous = page.children.filter((n) => n.getSharedPluginData(NS, DATA_KEY + '-layout') === String(bundle.layoutId));

  let fonts: Map<string, FontName>;
  try {
    fonts = await loadFonts(api, layout.nodes, report);
  } catch (error) {
    return { ...report, errors: [error instanceof Error ? error.message : String(error)] };
  }
  const variables = await tokenVariables(api, layout.tokens?.colors ?? [], report);
  const images = new Map<string, string>();
  const imageHash = (genId: number) => {
    const key = String(genId);
    if (!images.has(key)) images.set(key, api.createImage(api.base64Decode(bundle.assets[key].data)).hash);
    return images.get(key) as string;
  };

  const children = new Map<string | null, LayoutNode[]>();
  for (const node of layout.nodes) {
    children.set(node.parentId, [...(children.get(node.parentId) ?? []), node]);
  }
  children.forEach((list) => list.sort((a, b) => a.order - b.order));
  const reasons = new Map(layout.rasterizedRegions.map((r) => [r.nodeId, r.reason]));
  const count = (type: string) => { report.created[type] = (report.created[type] ?? 0) + 1; };

  const paint = (hex: string, opacity = 1): SolidPaint => {
    const { color, opacity: alpha } = hexToPaint(hex);
    const solid: SolidPaint = { type: 'SOLID', color, opacity: alpha * opacity };
    const variable = variables.get(hex.slice(0, 7).toLowerCase());
    return variable && alpha === 1 ? api.variables.setBoundVariableForPaint(solid, 'color', variable) : solid;
  };

  const tag = (sceneNode: SceneNode, node: LayoutNode) => {
    sceneNode.setSharedPluginData(NS, DATA_KEY, JSON.stringify({ layoutId: bundle.layoutId, nodeId: node.id, confidence: node.confidence }));
  };

  const createText = (node: LayoutNode, autoWidth: boolean): TextNode => {
    const s = node.style;
    const text = api.createText();
    text.fontName = fonts.get(fontKey(node)) as FontName;
    text.characters = node.content as string;
    text.fontSize = s.fontSize ?? 16;
    if (s.color) text.fills = [paint(s.color)];
    if (s.lineHeight) text.lineHeight = { value: s.lineHeight * 100, unit: 'PERCENT' };
    if (s.letterSpacing) text.letterSpacing = { value: s.letterSpacing, unit: 'PIXELS' };
    text.textAlignHorizontal = s.textAlign === 'center' ? 'CENTER' : s.textAlign === 'right' ? 'RIGHT' : 'LEFT';
    if (autoWidth) {
      text.textAutoResize = 'WIDTH_AND_HEIGHT';
    } else {
      text.resize(Math.max(1, node.frame.width), Math.max(1, node.frame.height));
      text.textAutoResize = 'HEIGHT';
    }
    report.texts++;
    return text;
  };

  const build = (node: LayoutNode, parent: LayoutNode | null, parentAuto: boolean): SceneNode => {
    const f = node.frame;
    const s = node.style;
    let out: SceneNode;

    if (node.type === 'text') {
      out = createText(node, false);
    } else if (node.type === 'button') {
      const button = api.createFrame();
      button.layoutMode = 'HORIZONTAL';
      button.primaryAxisAlignItems = 'CENTER';
      button.counterAxisAlignItems = 'CENTER';
      button.primaryAxisSizingMode = 'FIXED';
      button.counterAxisSizingMode = 'FIXED';
      button.paddingLeft = button.paddingRight = s.padding ?? 16;
      button.resize(Math.max(1, f.width), Math.max(1, f.height));
      button.fills = s.fill ? [paint(s.fill)] : [];
      button.cornerRadius = Math.min(s.radius ?? 0, f.height / 2);
      const label = createText(node, true);
      label.name = 'Etykieta';
      tag(label, node);
      button.appendChild(label);
      report.autoLayoutFrames++;
      out = button;
    } else if (node.type === 'image') {
      const rect = api.createRectangle();
      rect.resize(Math.max(1, f.width), Math.max(1, f.height));
      const asset = bundle.assets[String(node.assetRef!.generationId)];
      const crop = node.assetRef!.crop ?? { x: 0, y: 0, width: asset.width, height: asset.height };
      rect.fills = [{
        type: 'IMAGE',
        imageHash: imageHash(node.assetRef!.generationId),
        scaleMode: 'CROP',
        // Kadr w znormalizowanych współrzędnych obrazu źródłowego.
        imageTransform: [[crop.width / asset.width, 0, crop.x / asset.width], [0, crop.height / asset.height, crop.y / asset.height]],
      }];
      if (s.radius) rect.cornerRadius = s.radius;
      if (node.rasterized) {
        rect.setSharedPluginData(NS, DATA_KEY + '-raster', reasons.get(node.id) ?? '');
        report.bitmaps++;
      }
      out = rect;
    } else if (node.type === 'rectangle') {
      const rect = api.createRectangle();
      rect.resize(Math.max(1, f.width), Math.max(1, f.height));
      rect.fills = s.fill ? [paint(s.fill, s.opacity ?? 1)] : [];
      if (s.radius) rect.cornerRadius = Math.min(s.radius, Math.min(f.width, f.height) / 2);
      if (s.stroke) {
        rect.strokes = [paint(s.stroke)];
        rect.strokeWeight = s.strokeWidth ?? 1;
      }
      out = rect;
    } else {
      const frame = api.createFrame();
      frame.resize(Math.max(1, f.width), Math.max(1, f.height));
      frame.fills = s.fill ? [paint(s.fill)] : [];
      frame.clipsContent = parent === null;
      if (s.radius) frame.cornerRadius = s.radius;
      const kids = children.get(node.id) ?? [];
      const auto = node.layoutMode !== 'none';
      if (auto) {
        // Auto Layout zgodny z kompozycją: stos elementów w kolumnie/wierszu, wyrównanie z tekstów.
        frame.layoutMode = node.layoutMode === 'vertical' ? 'VERTICAL' : 'HORIZONTAL';
        frame.itemSpacing = s.gap ?? 0;
        frame.primaryAxisSizingMode = 'FIXED';
        frame.counterAxisSizingMode = 'FIXED';
        const align = kids.map((k) => k.style.textAlign ?? 'left');
        const main = align.filter((a) => a === 'center').length > kids.length / 2 ? 'CENTER' : align.filter((a) => a === 'right').length > kids.length / 2 ? 'MAX' : 'MIN';
        frame.counterAxisAlignItems = main;
        report.autoLayoutFrames++;
      }
      for (const kid of kids) {
        frame.appendChild(build(kid, node, auto));
      }
      out = frame;
    }

    out.name = node.rasterized ? `Bitmapa · ${node.name ?? node.id}` : node.name ?? node.id;
    if (parent && !parentAuto) {
      out.x = f.x - parent.frame.x;
      out.y = f.y - parent.frame.y;
    }
    tag(out, node);
    count(node.type);
    return out;
  };

  const rootNode = (children.get(null) ?? [])[0];
  let root: FrameNode;
  try {
    root = build(rootNode, null, false) as FrameNode;
  } catch (error) {
    return { ...report, errors: [`Nie udało się utworzyć warstw: ${error instanceof Error ? error.message : String(error)}`] };
  }

  const others = page.children.filter((n) => n !== root);
  const right = others.reduce((max, n) => Math.max(max, n.x + n.width), 0);
  root.x = others.length ? Math.round(right + GAP) : 0;
  root.y = others.length ? Math.min(...others.map((n) => n.y)) : 0;
  const copies = previous.length;
  root.name = copies && !options.replace ? `${bundle.title} (import ${copies + 1})` : bundle.title;
  root.setSharedPluginData(NS, DATA_KEY + '-layout', String(bundle.layoutId));
  page.appendChild(root);

  if (options.replace) {
    for (const old of previous) {
      if (old.getSharedPluginData(NS, DATA_KEY + '-layout') === String(bundle.layoutId) && !old.removed) {
        old.remove();
        report.replaced++;
      }
    }
  }

  if (bundle.mock && !report.warnings.some((w) => w.includes('(mock)'))) report.warnings.push('Obraz w paczce jest poglądowy (mock) — bitmapy pokazują tylko kompozycję.');
  page.selection = [root];
  api.viewport.scrollAndZoomIntoView([root]);

  return { ...report, ok: true, frameId: root.id, frameName: root.name };
}

const fontKey = (n: LayoutNode) => `${n.style.fontFamily ?? 'Inter'}|${n.style.fontWeight ?? 400}`;

/**
 * Ładuje kroje wymagane przez teksty. Kolejność prób: krój + odpowiadający grubości styl → krój Regular
 * → Inter w tym stylu → Inter Regular. Każde zastąpienie trafia do raportu jako błąd fontu.
 */
async function loadFonts(api: PluginAPI, nodes: LayoutNode[], report: ImportReport): Promise<Map<string, FontName>> {
  const result = new Map<string, FontName>();
  for (const node of nodes) {
    if (node.type !== 'text' && node.type !== 'button') continue;
    const key = fontKey(node);
    if (result.has(key)) continue;
    const family = node.style.fontFamily ?? 'Inter';
    const style = FONT_STYLES[node.style.fontWeight ?? 400] ?? 'Regular';
    const candidates: FontName[] = [{ family, style }, { family, style: 'Regular' }, { family: 'Inter', style }, { family: 'Inter', style: 'Regular' }];
    let firstError = '';
    for (const candidate of candidates) {
      try {
        await api.loadFontAsync(candidate);
        result.set(key, candidate);
        if (candidate.family !== family || candidate.style !== style) {
          report.fontIssues.push({ requested: `${family} ${style}`, used: `${candidate.family} ${candidate.style}`, reason: firstError || 'Styl niedostępny.' });
        }
        break;
      } catch (error) {
        if (!firstError) firstError = error instanceof Error ? error.message : String(error);
      }
    }
    if (!result.has(key)) {
      throw new Error(`Brak dostępnych fontów (nawet Inter Regular) dla „${family} ${style}”.`);
    }
  }
  return result;
}

/**
 * Tokeny kolorów jako zmienne w kolekcji „Clara tokens”. Istniejących zmiennych nie nadpisujemy —
 * tworzymy tylko brakujące; konflikt wartości trafia do ostrzeżeń.
 */
async function tokenVariables(api: PluginAPI, colors: { name: string; value: string }[], report: ImportReport): Promise<Map<string, Variable>> {
  const byValue = new Map<string, Variable>();
  if (!colors.length) return byValue;
  try {
    const name = 'Clara tokens';
    const collection = (await api.variables.getLocalVariableCollectionsAsync()).find((c) => c.name === name) ?? api.variables.createVariableCollection(name);
    const modeId = collection.modes[0].modeId;
    const existing = new Map<string, Variable>();
    for (const id of collection.variableIds) {
      const v = await api.variables.getVariableByIdAsync(id);
      if (v) existing.set(v.name, v);
    }
    for (const token of colors) {
      const value = token.value.slice(0, 7).toLowerCase();
      const varName = `Kolory/${token.name}`.slice(0, 120);
      let variable = existing.get(varName);
      if (variable) {
        report.variables.reused++;
        const current = variable.valuesByMode[modeId] as RGBA | undefined;
        const { color } = hexToPaint(value);
        if (current && (Math.abs(current.r - color.r) > 0.004 || Math.abs(current.g - color.g) > 0.004 || Math.abs(current.b - color.b) > 0.004)) {
          report.warnings.push(`Zmienna „${varName}” ma inną wartość niż token z Clara — pozostawiono istniejącą.`);
          continue;
        }
      } else {
        variable = api.variables.createVariable(varName, collection, 'COLOR');
        variable.setValueForMode(modeId, { ...hexToPaint(value).color, a: 1 });
        report.variables.created++;
      }
      byValue.set(value, variable);
    }
    report.variables.collection = name;
  } catch (error) {
    report.warnings.push(`Nie utworzono zmiennych tokenów: ${error instanceof Error ? error.message : String(error)}`);
  }
  return byValue;
}
