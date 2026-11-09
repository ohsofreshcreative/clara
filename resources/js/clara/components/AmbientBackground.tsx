import { useEffect, useRef } from '@wordpress/element';

const MAX_PANELS = 16;

const VERTEX = `attribute vec2 p; void main() { gl_Position = vec4(p, 0.0, 1.0); }`;

/*
 * Tło: miękkie, płynące poświaty w kolorach akcentu.
 * Szkło: w prostokątach paneli (.glass) to samo tło jest załamywane jak przez taflę z zaokrąglonymi
 * krawędziami — soczewkowanie przy brzegu, rozszczepienie RGB, świecący rant i ukośny refleks.
 */
const FRAGMENT = `
precision mediump float;
uniform vec2 res;
uniform float t;
uniform vec3 a1;
uniform vec3 a2;
uniform vec3 base;
uniform vec4 panels[${MAX_PANELS}];
uniform float radii[${MAX_PANELS}];
uniform int count;

float blob(vec2 uv, vec2 c, float r) {
  vec2 d = uv - c;
  return exp(-dot(d, d) / (r * r));
}

// Tło: poświaty + dwie delikatne smugi zorzy — szkło ma co załamywać.
vec3 field(vec2 px) {
  float aspect = res.x / res.y;
  vec2 uv = px / res;
  uv.x *= aspect;
  vec3 col = base;
  col += a1 * 0.36 * blob(uv, vec2(aspect * (0.78 + 0.08 * sin(t * 0.07)), 0.86 + 0.06 * cos(t * 0.09)), 0.42);
  col += a2 * 0.20 * blob(uv, vec2(aspect * (0.18 + 0.07 * cos(t * 0.05)), 0.22 + 0.08 * sin(t * 0.06)), 0.38);
  col += mix(a1, a2, 0.5) * 0.12 * blob(uv, vec2(aspect * (0.52 + 0.18 * sin(t * 0.04)), 0.5 + 0.14 * cos(t * 0.05)), 0.30);
  col += vec3(0.42, 0.25, 0.85) * 0.09 * blob(uv, vec2(aspect * (0.95 + 0.05 * cos(t * 0.08)), 0.15), 0.35);
  float r1 = uv.y - (0.62 + 0.07 * sin(uv.x * 2.3 + t * 0.11) + 0.03 * sin(uv.x * 5.1 - t * 0.07));
  float r2 = uv.y - (0.34 + 0.06 * sin(uv.x * 1.7 - t * 0.09 + 1.3) + 0.025 * sin(uv.x * 4.3 + t * 0.05));
  col += a2 * 0.06 * exp(-r1 * r1 * 900.0) + a1 * 0.04 * exp(-r1 * r1 * 120.0);
  col += a1 * 0.05 * exp(-r2 * r2 * 700.0) + mix(a1, a2, 0.5) * 0.03 * exp(-r2 * r2 * 90.0);
  return col;
}

float roundedBox(vec2 p, vec2 hs, float r) {
  vec2 q = abs(p) - hs + r;
  return length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - r;
}

void main() {
  vec2 px = gl_FragCoord.xy;
  vec3 col = field(px);
  vec2 lightDir = normalize(vec2(-0.55, 0.85));

  for (int i = 0; i < ${MAX_PANELS}; i++) {
    if (i >= count) break;
    vec4 rect = panels[i];
    vec2 hs = rect.zw * 0.5;
    vec2 p = px - (rect.xy + hs);
    float r = min(radii[i], min(hs.x, hs.y));
    // Tafla wsunięta ~3 px CSS pod krawędź panelu: obrys i zaokrąglenie wyznacza ostra ramka CSS,
    // a płótno w połowie rozdzielczości nie rysuje własnej (schodkowej) krawędzi.
    float inset = 1.5;
    float d = roundedBox(p, hs - inset, max(r - inset, 0.0));
    float outside = smoothstep(-1.0, 1.0, d);

    // Miękki cień pod taflą (przesunięty w dół), wygaszany płynnie przy krawędzi.
    float ds = roundedBox(p + vec2(0.0, 7.0), hs, r);
    col *= 1.0 - 0.28 * exp(-max(ds, 0.0) / 14.0) * smoothstep(0.0, 3.0, d);
    if (d > 1.0) continue;

    // Normalna tafli z gradientu SDF; soczewka w pasie ~16 px przy krawędzi (w pikselach płótna).
    vec2 e = vec2(1.0, 0.0);
    vec2 n = normalize(vec2(roundedBox(p + e.xy, hs, r) - roundedBox(p - e.xy, hs, r), roundedBox(p + e.yx, hs, r) - roundedBox(p - e.yx, hs, r)) + 1e-5);
    float edge = clamp(1.0 + d / 16.0, 0.0, 1.0);
    vec2 off = -n * edge * edge * 26.0 - p * 0.04;

    vec3 glass = vec3(
      field(px + off * 1.12).r,
      field(px + off).g,
      field(px + off * 0.88).b
    );
    glass *= 1.04;

    // Światło wpadające przez krawędź: miękko, bez twardej linii; mocniej od strony światła.
    float facing = clamp(dot(n, lightDir), 0.0, 1.0);
    float edgeGlow = exp(d / 5.0) * (0.10 + 0.45 * facing) + exp(d / 22.0) * 0.05 * facing;
    vec3 glow = mix(a2, vec3(1.0), 0.6) * edgeGlow * 0.4 * (1.0 - outside);

    // Refleks: miękki gradient od góry i powolny ukośny pas światła.
    vec2 local = p / max(hs, vec2(1.0));
    float top = smoothstep(-0.2, 1.0, local.y) * 0.035;
    float band = local.x * 0.55 + local.y - 0.9 + 0.5 * sin(t * 0.045 + float(i) * 1.7);
    float sheen = exp(-band * band * 18.0) * 0.045;

    col = mix(glass + glow + vec3(top + sheen), col, outside);
  }

  float grain = fract(sin(dot(px, vec2(12.9898, 78.233))) * 43758.5453);
  gl_FragColor = vec4(col + (grain - 0.5) * 0.012, 1.0);
}`;

const hex = (value: string, fallback: [number, number, number]): [number, number, number] => {
  const m = value.trim().match(/^#([0-9a-f]{6})$/i);
  if (!m) return fallback;
  const n = parseInt(m[1], 16);
  return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255];
};

/** Tło WebGL za całym panelem i szkło paneli. Bez WebGL zostaje gradient CSS i szkło CSS; w trybie ograniczonym ukryte. */
export default function AmbientBackground() {
  const canvas = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const el = canvas.current;
    const gl = el?.getContext('webgl', { antialias: false, alpha: false, powerPreference: 'low-power' });
    if (!el || !gl) return;

    const shader = (type: number, src: string) => {
      const s = gl.createShader(type)!;
      gl.shaderSource(s, src);
      gl.compileShader(s);
      return s;
    };
    const program = gl.createProgram()!;
    gl.attachShader(program, shader(gl.VERTEX_SHADER, VERTEX));
    gl.attachShader(program, shader(gl.FRAGMENT_SHADER, FRAGMENT));
    gl.linkProgram(program);
    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
      // Zostaje szkło CSS; log pomaga zdiagnozować sterownik / błąd GLSL.
      console.warn('Clara: shader tła niedostępny —', gl.getProgramInfoLog(program));
      return;
    }
    gl.useProgram(program);
    // Szkło rysuje shader — CSS zostawia tylko lekkie rozmycie dla czytelności tekstu.
    document.documentElement.dataset.glass = 'webgl';

    const buffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
    const loc = gl.getAttribLocation(program, 'p');
    gl.enableVertexAttribArray(loc);
    gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);

    const u = (name: string) => gl.getUniformLocation(program, name);
    const colors = () => {
      const css = getComputedStyle(document.documentElement);
      gl.uniform3fv(u('a1'), hex(css.getPropertyValue('--color-accent-500'), [0.18, 0.42, 1]));
      gl.uniform3fv(u('a2'), hex(css.getPropertyValue('--color-accent-300'), [0.58, 0.71, 1]));
      gl.uniform3fv(u('base'), hex(css.getPropertyValue('--color-ink-950'), [0.02, 0.03, 0.08]));
    };
    colors();

    // Połowa rozdzielczości: tło jest miękkie, a koszt GPU spada 4×.
    const SCALE = 0.5;
    const resize = () => {
      el.width = Math.max(1, Math.floor(window.innerWidth * SCALE));
      el.height = Math.max(1, Math.floor(window.innerHeight * SCALE));
      gl.viewport(0, 0, el.width, el.height);
      gl.uniform2f(u('res'), el.width, el.height);
    };
    resize();

    const radiusCache = new WeakMap<Element, number>();
    const rects = new Float32Array(MAX_PANELS * 4);
    const radii = new Float32Array(MAX_PANELS);
    let signature = '';
    // Prostokąty widocznych paneli w pikselach płótna (oś Y od dołu, jak gl_FragCoord).
    const panels = () => {
      let n = 0;
      const vh = window.innerHeight;
      for (const node of document.querySelectorAll('.glass, .glass-strong')) {
        if (n >= MAX_PANELS) break;
        const b = node.getBoundingClientRect();
        if (b.width < 8 || b.height < 8 || b.bottom < 0 || b.top > vh) continue;
        if (!radiusCache.has(node)) radiusCache.set(node, parseFloat(getComputedStyle(node).borderTopLeftRadius) || 0);
        rects.set([b.left * SCALE, (vh - b.bottom) * SCALE, b.width * SCALE, b.height * SCALE], n * 4);
        radii[n] = radiusCache.get(node)! * SCALE;
        n++;
      }
      const next = `${n}:${Array.from(rects.subarray(0, n * 4), Math.round).join(',')}`;
      const changed = next !== signature;
      signature = next;
      gl.uniform4fv(u('panels'), rects);
      gl.uniform1fv(u('radii'), radii);
      gl.uniform1i(u('count'), n);
      return changed;
    };

    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let frame = 0;
    let last = 0;
    const start = performance.now();
    const draw = (now: number) => {
      frame = requestAnimationFrame(draw);
      if (document.hidden || document.documentElement.dataset.effects === 'reduced') return;
      // Panele śledzone co klatkę (przewijanie), pełne odświeżenie tła ~30 fps.
      const moved = panels();
      if (!moved && (still || now - last < 33)) return;
      last = now;
      gl.uniform1f(u('t'), still ? 12 : (now - start) / 1000);
      gl.drawArrays(gl.TRIANGLES, 0, 3);
    };
    frame = requestAnimationFrame(draw);

    const observer = new MutationObserver(() => {
      colors();
      signature = '';
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-accent', 'data-scale'] });
    const onResize = () => {
      resize();
      signature = '';
    };
    window.addEventListener('resize', onResize);

    return () => {
      cancelAnimationFrame(frame);
      observer.disconnect();
      window.removeEventListener('resize', onResize);
      delete document.documentElement.dataset.glass;
    };
  }, []);

  return (
    <div aria-hidden="true" className="clara-ambient pointer-events-none fixed inset-0 -z-10 bg-[radial-gradient(ellipse_at_80%_10%,color-mix(in_srgb,var(--color-accent-500)_30%,transparent),transparent_55%),radial-gradient(ellipse_at_15%_85%,color-mix(in_srgb,var(--color-accent-300)_18%,transparent),transparent_50%)]">
      <canvas ref={canvas} className="size-full" />
    </div>
  );
}
