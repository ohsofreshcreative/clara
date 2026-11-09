import type { CSSProperties, ReactNode } from 'react';
import type { LayoutDocument, LayoutJson, LayoutNode } from '../api';

const HEX = /^#[0-9a-f]{6}([0-9a-f]{2})?$/i;
const color = (value?: string) => (value && HEX.test(value) ? value : undefined);

/**
 * Podgląd HTML/CSS Layout JSON v1. Pozycje w % ramki rodzica, rozmiary fontu w jednostkach `cqw`
 * kontenera — podgląd skaluje się do szerokości bez JS. Tekst jest prawdziwym tekstem DOM;
 * węzły rasteryzowane to kadry obrazu źródłowego (background z przesunięciem kadru).
 */
export default function LayoutPreview({ layout, assets }: { layout: LayoutJson; assets: LayoutDocument['assets'] }) {
  const W = layout.canvas.width;
  const children = new Map<string | null, LayoutNode[]>();
  for (const node of layout.nodes) {
    const list = children.get(node.parentId) ?? [];
    list.push(node);
    children.set(node.parentId, list);
  }
  children.forEach((list) => list.sort((a, b) => a.order - b.order));
  const cq = (px: number) => `${(px / W) * 100}cqw`;

  const render = (node: LayoutNode, parent: LayoutNode['frame'] | null): ReactNode => {
    const f = node.frame;
    const s = node.style ?? {};
    const box: CSSProperties = parent
      ? {
          position: 'absolute',
          left: `${((f.x - parent.x) / parent.width) * 100}%`,
          top: `${((f.y - parent.y) / parent.height) * 100}%`,
          width: `${(f.width / parent.width) * 100}%`,
          height: `${(f.height / parent.height) * 100}%`,
        }
      : { position: 'absolute', inset: 0 };

    if (node.type !== 'image') box.backgroundColor = color(s.fill);
    if (s.radius) box.borderRadius = cq(Math.min(s.radius, f.height / 2));
    if (s.stroke) box.border = `${cq(s.strokeWidth ?? 1)} solid ${color(s.stroke)}`;
    if (node.type === 'rectangle' && s.opacity !== undefined) box.opacity = s.opacity;

    const common = { key: node.id, 'data-node-id': node.id, 'data-node-type': node.type, 'data-rasterized': node.rasterized ? 'true' : undefined };

    if (node.type === 'image') {
      const asset = node.assetRef ? assets?.[String(node.assetRef.generationId)] : undefined;
      const crop = node.assetRef?.crop;
      if (asset) {
        const iw = asset.width ?? crop?.width ?? 1;
        const ih = asset.height ?? crop?.height ?? 1;
        const c = crop ?? { x: 0, y: 0, width: iw, height: ih };
        box.backgroundImage = `url("${encodeURI(asset.url)}")`;
        box.backgroundSize = `${(iw / c.width) * 100}% ${(ih / c.height) * 100}%`;
        box.backgroundPosition = `${iw === c.width ? 0 : (c.x / (iw - c.width)) * 100}% ${ih === c.height ? 0 : (c.y / (ih - c.height)) * 100}%`;
      } else {
        box.backgroundColor = 'rgba(255,255,255,.06)';
      }
      return <div {...common} style={box} role="img" aria-label={node.name ?? 'Bitmapa'} />;
    }

    if (node.type === 'text' || node.type === 'button') {
      const text: CSSProperties = {
        ...box,
        color: color(s.color),
        fontFamily: s.fontFamily ? `"${s.fontFamily.replace(/"/g, '')}", system-ui, sans-serif` : undefined,
        fontSize: s.fontSize ? cq(s.fontSize) : undefined,
        fontWeight: s.fontWeight,
        lineHeight: s.lineHeight,
        letterSpacing: s.letterSpacing ? cq(s.letterSpacing) : undefined,
        textAlign: s.textAlign,
        whiteSpace: 'pre-wrap',
        overflowWrap: 'anywhere',
        margin: 0,
      };
      if (node.type === 'button') {
        Object.assign(text, { display: 'flex', alignItems: 'center', justifyContent: 'center', padding: `0 ${cq(s.padding ?? 16)}` });
      }
      return <div {...common} style={text}>{node.content}</div>;
    }

    return (
      <div {...common} style={box}>
        {(children.get(node.id) ?? []).map((child) => render(child, f))}
      </div>
    );
  };

  const root = (children.get(null) ?? [])[0];

  return (
    <div className="relative w-full overflow-hidden" style={{ aspectRatio: `${W} / ${layout.canvas.height}`, containerType: 'inline-size', backgroundColor: color(layout.canvas.background) }} data-testid="layout-preview">
      {root && render(root, null)}
    </div>
  );
}
