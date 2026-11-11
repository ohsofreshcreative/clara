// Test wizualny/dostępności dashboardu Clara. Uruchamiany przez visual.sh.
import { createRequire } from 'node:module';
import { readFileSync, existsSync } from 'node:fs';
import { join, extname } from 'node:path';

const env = process.env;
const require = createRequire(env.CLARA_PLAYWRIGHT ? join(env.CLARA_PLAYWRIGHT, 'noop.js') : import.meta.url);
const { chromium } = require('playwright');

const URL = env.CLARA_URL;
const BUILD = env.CLARA_BUILD;
const OUT = env.CLARA_OUT;
const BUILD_PATH = '/wp-content/themes/clara/public/build/';
const manifest = JSON.parse(readFileSync(join(BUILD, 'manifest.json'), 'utf8'));

let pass = 0;
let fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? ` — ${detail}` : ''}`);
};

const types = { '.js': 'text/javascript', '.css': 'text/css', '.jpg': 'image/jpeg', '.png': 'image/png', '.svg': 'image/svg+xml' };

// Tagi wejść panelu z manifestu buildu testowego.
function assetTags() {
  const css = manifest['resources/css/clara.css'];
  const js = manifest['resources/js/clara/main.tsx'];
  const styles = [css.file, ...(js.css ?? [])].map((f) => `<link rel="stylesheet" href="${BUILD_PATH}${f}">`);
  return [...styles, `<script type="module" src="${BUILD_PATH}${js.file}"></script>`].join('\n');
}

async function newPage(browser, viewport) {
  const context = await browser.newContext({ viewport, deviceScaleFactor: 1, acceptDownloads: true });
  const host = new globalThis.URL(URL).hostname;
  await context.addCookies([{ name: env.CLARA_COOKIE_NAME, value: env.CLARA_COOKIE_VAL, domain: host, path: '/' }]);

  await context.route(`**${BUILD_PATH}**`, (route) => {
    const file = join(BUILD, new globalThis.URL(route.request().url()).pathname.slice(BUILD_PATH.length));
    if (!existsSync(file)) return route.fulfill({ status: 404 });
    return route.fulfill({ body: readFileSync(file), contentType: types[extname(file)] ?? 'application/octet-stream' });
  });

  // Panel jest na stronie głównej i podstronach (/projects…) — podmieniamy assety w każdym dokumencie panelu.
  const panel = new RegExp(`^${URL.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}(/(?!wp-)[^.?]*)?(\\?.*)?$`);
  await context.route(panel, async (route) => {
    if (route.request().resourceType() !== 'document') return route.continue();
    const response = await route.fetch();
    // Assety z prawdziwego buildu (jeśli istnieje) zastępujemy buildem testowym — bez dublowania CSS/JS.
    const escaped = BUILD_PATH.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');
    const own = new RegExp(`<(link|script)[^>]+(href|src)="[^"]*${escaped}[^"]*"[^>]*>(</script>)?\\s*`, 'g');
    const text = await response.text();
    if (!text.includes('id="clara-root"')) return route.fulfill({ response, body: text });
    const html = text.replace(own, '').replace('</head>', `${assetTags()}\n</head>`);
    return route.fulfill({ response, body: html });
  });

  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => m.type() === 'error' && errors.push(m.location()?.url ? `${m.text()} [${m.location().url}]` : m.text()));

  return { page, context, errors };
}

// Kontrast WCAG dla tekstu względem pierwszego jednolitego tła przodka (gradienty/obrazy pomijane).
const contrastAudit = () => {
  const parse = (c) => (c.match(/[\d.]+/g) || []).map(Number);
  const lum = ([r, g, b]) => {
    const f = (v) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  };
  const ratio = (a, b) => {
    const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m);
    return (x + 0.05) / (y + 0.05);
  };
  const background = (el) => {
    for (let n = el; n; n = n.parentElement) {
      const s = getComputedStyle(n);
      if (s.backgroundImage !== 'none' && n !== document.body) return null;
      const c = parse(s.backgroundColor);
      if (c.length >= 3 && (c[3] ?? 1) >= 0.9) return c;
    }
    return [5, 8, 20];
  };
  const issues = [];
  let checked = 0;
  for (const el of document.querySelectorAll('#clara-root *')) {
    const text = [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join('');
    if (!text || el.closest('[aria-hidden="true"]') || el.closest('.sr-only')) continue;
    const r = el.getBoundingClientRect();
    if (!r.width || !r.height) continue;
    const s = getComputedStyle(el);
    if (s.visibility === 'hidden' || el.closest('button:disabled')) continue;
    const bg = background(el);
    if (!bg) continue;
    const size = parseFloat(s.fontSize);
    const large = size >= 24 || (size >= 18.66 && Number(s.fontWeight) >= 700);
    const value = ratio(parse(s.color), bg);
    checked++;
    if (value < (large ? 3 : 4.5)) issues.push(`${text.slice(0, 30)}: ${value.toFixed(2)}`);
  }
  return { checked, issues };
};

// Dokument podstawiany przez route.fulfill Chromium traktuje jak publiczny i blokuje zasoby
// z loopback (Private/Local Network Access) — to artefakt harnessu, nie zachowanie strony.
const seen = (locator) => locator.first().waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false);

/** Odpowiedzi POST /generate (4 warianty w kolejce) i POST /generate/{id}/render (jeden wariant) w kształcie z InspirationGenerator. */
const SVG = (fill) => `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="1536" height="1024"><rect width="100%" height="100%" fill="${fill}"/></svg>`)}`;
const GEN_TITLES = ['Propozycja 1', 'Propozycja 2', 'Propozycja 3', 'Propozycja 4'];
const genItem = (i, status, call = 0) => ({
  id: 990000 + call * 10 + i, parent_id: 0, directionKey: 'ABCD'[i], title: GEN_TITLES[i], status, statusLabel: '',
  error: status === 'failed' ? { code: 'clara_image_moderation', message: 'OpenAI odrzucił prompt przez zasady treści — zmień opis.' } : null,
  lastError: null, cost: 0.06, costSource: 'estimate', prompt: '', remixOf: null, remixPrompt: null, version: 1, rootId: 990000 + call * 10 + i, sources: [],
  created_at: '2026-11-07T12:00:00+00:00', image: status === 'succeeded' ? { id: 1, url: SVG(['#123456', '#0a1630', '#2f6bff'][i]), width: 1536, height: 1024 } : null,
});
const GEN_PROJECT = { id: 990001, name: 'H2Otwock — test', client_name: '', industry: '', website_url: '', figma_url: '', brief: {}, created_at: '', updated_at: '', design_system_version: null };
const generateFixture = (keepDirection = false, call = 0, round = 0) => ({
  project: { id: GEN_PROJECT.id, name: GEN_PROJECT.name }, section: { id: 1, name: 'Hero — inspiracje' }, provider: 'openai', model: 'gpt-image-2', keepDirection, round,
  batch: { batchId: `test-${call}`, artRunId: null, provider: 'openai', model: 'gpt-image-2', mock: false, status: 'queued', cost: 0.24, created_at: '', items: [0, 1, 2, 3].map((i) => genItem(i, 'queued', call)) },
});

const browser = await chromium.launch({
  headless: true,
  // SwiftShader: WebGL bez GPU, żeby shader tła i szkła był naprawdę kompilowany w teście.
  args: ['--disable-features=BlockInsecurePrivateNetworkRequests,PrivateNetworkAccessSendPreflights,LocalNetworkAccessChecks', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});

for (const vp of [
  { name: 'desktop-1440', width: 1440, height: 1024 },
  { name: 'mobile-390', width: 390, height: 844 },
]) {
  console.log(`== ${vp.name} ==`);
  const { page, context, errors } = await newPage(browser, { width: vp.width, height: vp.height });
  await page.goto(`${URL}/`, { waitUntil: 'networkidle' });
  const heading = page.getByRole('heading', { level: 1, name: /Inspiracje dla Twoich/ });
  const rendered = await heading.isVisible().catch(() => false);
  check('dashboard renderuje się po zalogowaniu', rendered, errors.join(' | '));
  if (!rendered) {
    await page.screenshot({ path: join(OUT, `clara-${vp.name}-error.png`) });
    await browser.close();
    process.exit(1);
  }

  await page.screenshot({ path: join(OUT, `clara-${vp.name}.png`), fullPage: false });
  await page.screenshot({ path: join(OUT, `clara-${vp.name}-full.png`), fullPage: true });

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check('brak poziomego overflow', overflow <= 0, `${overflow}px`);

  const navLinks = page.locator('#clara-nav a');
  check('sidebar: 5 pozycji nawigacji', (await navLinks.count()) === 5);
  if (vp.width >= 1024) check('sidebar widoczny na desktopie', await navLinks.first().isVisible());
  check('tryby Generuj inspiracje / Szukaj zdjęć', (await page.getByRole('radio').count()) === 2);
  check('composer bez nieaktywnych przycisków „wkrótce”', !(await page.locator('form button[disabled]').count()));
  check('filtry projekt/design system/klient', (await page.getByRole('combobox').count()) === 3);
  check('4 szybkie starty', (await page.getByRole('button', { name: /Sekcja hero|O nas|Usługi|Cennik/ }).count()) === 4);
  check('brak fikcyjnych danych z mockupu', !(await page.getByText(/Plan Pro|1 240|2 000 generacji/).count()));

  // Postać hero jest dekoracją: nie może przechwytywać kliknięć w kontrolki ani nagłówek.
  const covered = await page.evaluate(() => {
    const targets = ['h1', '#clara-prompt', 'form button[type="submit"]', 'select'];
    return targets.filter((sel) => {
      const el = document.querySelector(sel);
      el.scrollIntoView({ block: 'center' });
      const r = el.getBoundingClientRect();
      const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return !(hit && (el === hit || el.contains(hit) || hit.contains(el) || hit.closest('label, form')?.contains(el)));
    });
  });
  check('postać nie zasłania interfejsu', covered.length === 0, covered.join(', '));
  await page.evaluate(() => window.scrollTo(0, 0));
  check('obraz postaci jest dekoracyjny (alt="")', (await page.locator('img[alt=""]').count()) >= 1);

  const contrast = await page.evaluate(contrastAudit);
  check(`kontrast WCAG AA (${contrast.checked} elementów)`, contrast.issues.length === 0, contrast.issues.join('; '));

  // Focus states: każdy element osiągalny Tabem ma widoczny wskaźnik.
  const missingFocus = [];
  for (let i = 0; i < 20; i++) {
    await page.keyboard.press('Tab');
    const info = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) return null;
      const visible = (n) => {
        const s = getComputedStyle(n);
        return s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0;
      };
      // Pola formularza mogą pokazywać focus na swoim kontenerze (has-[:focus-visible]) — do 3 poziomów w górę.
      let ok = false;
      for (let n = el, depth = 0; n && depth < 4 && !ok; n = n.parentElement, depth++) ok = visible(n);
      return { name: (el.getAttribute('aria-label') || el.textContent || el.id || el.tagName).trim().slice(0, 30), ok };
    });
    if (info && !info.ok) missingFocus.push(info.name);
  }
  check('widoczny focus dla 20 kolejnych elementów', missingFocus.length === 0, missingFocus.join(', '));

  if (vp.width < 1024) {
    const toggle = page.getByRole('button', { name: /Otwórz menu/ });
    check('mobile: menu zwinięte', (await toggle.getAttribute('aria-expanded')) === 'false' && !(await page.locator('#clara-nav').isVisible()));
    await toggle.click();
    check('mobile: menu otwiera się', (await page.locator('#clara-nav').isVisible()) && (await page.getByRole('button', { name: /Zamknij menu/ }).getAttribute('aria-expanded')) === 'true');
    const overflowOpen = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check('mobile: otwarte menu bez overflow', overflowOpen <= 0, `${overflowOpen}px`);
    await page.screenshot({ path: join(OUT, `clara-${vp.name}-menu.png`) });
    await page.keyboard.press('Escape');
    check('mobile: Escape zamyka menu', !(await page.locator('#clara-nav').isVisible()));
  }

  // Happy path: szybki start wypełnia prompt i ustawia focus.
  await page.getByRole('button', { name: /Sekcja hero/ }).click();
  const prompt = page.locator('#clara-prompt');
  check('szybki start wypełnia prompt', (await prompt.inputValue()).includes('sekcję hero') && (await prompt.evaluate((el) => el === document.activeElement)));

  // Tryb zmienia akcję; domyślnie generowanie sekcji.
  check('domyślnie tryb Generuj inspiracje', await page.getByRole('button', { name: 'Generuj inspiracje' }).isVisible());
  await page.locator('label', { hasText: 'Szukaj zdjęć' }).click();
  check('tryb Szukaj zdjęć zmienia przycisk', await page.getByRole('button', { name: 'Szukaj zdjęć' }).isVisible());
  await page.locator('label', { hasText: 'Generuj inspiracje' }).click();

  // Błędne dane: zbyt krótki prompt.
  await prompt.fill('abc');
  await page.getByRole('button', { name: 'Generuj inspiracje' }).click();
  check('walidacja zbyt krótkiego promptu', (await page.getByRole('alert').filter({ hasText: 'Opisz sekcję' }).isVisible()) && (await prompt.getAttribute('aria-invalid')) === 'true');

  // Testy działają bez klucza AI: serwer odpowiada jasnym błędem, bez udawanego wyniku.
  await prompt.fill('sekcja Hero basen otwock h2otwock');
  await page.getByRole('button', { name: 'Generuj inspiracje' }).click();
  check('bez klucza AI: komunikat zamiast udawanych sekcji', (await seen(page.getByText(/wymaga klucza OpenAI/))) && !(await page.getByTestId('generated-item').count()));

  // Render 4 inspiracji (odpowiedzi API podstawione w przeglądarce — bez kosztów). Warianty kończą się w różnym czasie.
  const genBodies = [];
  let direction = null;
  await page.route('**/clara/v1/generate*', (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    const body = route.request().postDataJSON() ?? {};
    genBodies.push(body);
    return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify(generateFixture(Boolean(body.keepDirection), genBodies.length - 1, body.round ?? 0)) });
  });
  const queue = [];
  const waitQueue = async (n) => { for (let t = 0; queue.length < n && t < 100; t++) await page.waitForTimeout(50); };
  const release = async (pick, min = 4) => {
    await waitQueue(min);
    for (const entry of [...queue]) if (!pick || pick(entry.i)) { entry.resolve(); queue.splice(queue.indexOf(entry), 1); }
  };
  const settled = () => page.waitForFunction(() => /Gotowe/.test(document.querySelector('[data-testid="generated-progress"]')?.textContent ?? ''), null, { timeout: 5000 }).catch(() => {});
  await page.route('**/clara/v1/generate/*/render*', async (route) => {
    const id = Number(route.request().url().match(/generate\/(\d+)\/render/)[1]) - 990000;
    const i = id % 10;
    await new Promise((resolve) => queue.push({ i, resolve }));
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(genItem(i, i === 3 ? 'failed' : 'succeeded', Math.floor(id / 10))) }).catch(() => {});
  });
  await page.route('**/clara/v1/projects*', async (route) => {
    if (route.request().method() !== 'GET') return route.continue();
    // Trasa bywa zdejmowana w trakcie żądania — wtedy obsłużył je już kto inny.
    try {
      const res = await route.fetch();
      const list = await res.json();
      await route.fulfill({ response: res, body: JSON.stringify([{ ...GEN_PROJECT, brief: direction ? { direction } : {} }, ...(Array.isArray(list) ? list : [])]) });
    } catch {
      // Trasa zdjęta w trakcie żądania — nic do zrobienia.
    }
  });
  await page.route('**/clara/v1/projects/990001/direction*', (route) => {
    direction = { generationId: 990001, sectionId: 1, title: 'Pełny kadr', layout: 'full-bleed', imageUrl: SVG('#0a1630'), setAt: '' };
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ direction }) });
  });

  await page.getByRole('button', { name: 'Generuj inspiracje' }).click();
  const generated = page.getByTestId('generated-item');
  const progress = page.getByTestId('generated-progress');
  check('GEN: od razu 4 kafelki ładowania z postępem 0/4', (await seen(page.getByTestId('generated-loading'))) && (await page.getByTestId('generated-loading').count()) === 4 && /Generowanie 0\/4/.test(await progress.textContent()));
  check('GEN: kafelki ładowania mają animację i status kroku', (await page.getByTestId('generated-loading').first().locator('.shimmer').count()) === 1 && (await page.getByTestId('generated-results').getAttribute('aria-busy')) === 'true');
  check('GEN: licznik czasu generowania', /\d:\d\d/.test(await progress.textContent()), await progress.textContent());
  check('GEN: generowanie zablokowane w trakcie', await page.getByRole('button', { name: /Generuj/ }).first().isDisabled());
  await release((i) => i === 1);
  await seen(page.getByTestId('generated-results').getByRole('img', { name: /wariant B/ }));
  check('GEN: gotowy wariant pojawia się, reszta nadal się ładuje (1/4)', (await page.getByTestId('generated-loading').count()) === 3 && /Generowanie 1\/4/.test(await progress.textContent()));
  await release(null, 3);
  await settled();
  check('GEN: 4 warianty, 3 obrazy całej sekcji z opisem alt', (await generated.count()) === 4 && (await page.getByTestId('generated-results').getByRole('img', { name: /Inspiracja sekcji/ }).count()) === 3 && /Gotowe 3\/4/.test(await progress.textContent()));
  check('GEN: błąd wariantu czytelny przy kafelku', await generated.nth(3).getByRole('alert').filter({ hasText: 'zasady treści' }).isVisible());
  check('GEN: link do projektu i model', (await page.getByTestId('generated-results').getByRole('link', { name: 'H2Otwock — test' }).isVisible()) && (await page.getByText(/gpt-image-2/).isVisible()));
  await generated.nth(0).getByRole('button', { name: 'Powiększ' }).click();
  check('GEN: powiększenie wariantu', (await generated.nth(0).getByRole('button', { name: 'Zmniejsz' }).getAttribute('aria-expanded')) === 'true');
  const genOverflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check('GEN: brak overflow z wynikami', genOverflow <= 0, `${genOverflow}px`);
  await page.getByTestId('generated-results').screenshot({ path: join(OUT, `clara-generated-${vp.width}.png`) });

  // Kierunek projektu: obranie wariantu → opcja „trzymaj się kierunku” → kolejne generowanie z flagą.
  await generated.nth(1).getByRole('button', { name: 'Obierz ten kierunek' }).click();
  check('DIR: wariant oznaczony jako kierunek projektu', await seen(generated.nth(1).getByText('Kierunek projektu')));
  const toggle = page.getByTestId('direction-toggle');
  check('DIR: opcja „Trzymaj się kierunku projektu” włączona, z miniaturą', (await seen(toggle)) && (await toggle.getByRole('checkbox').isChecked()) && (await toggle.locator('img').count()) === 1);
  await page.getByRole('button', { name: 'Generuj inspiracje' }).click();
  await seen(page.getByRole('heading', { name: '4 odmiany w kierunku projektu' }));
  check('DIR: kolejne generowanie w tym samym projekcie, z flagą kierunku', genBodies.at(-1)?.projectId === GEN_PROJECT.id && genBodies.at(-1)?.keepDirection === true, JSON.stringify(genBodies.at(-1)));
  await release();
  await settled();
  await toggle.getByRole('checkbox').uncheck();
  await page.getByRole('button', { name: 'Generuj inspiracje' }).click();
  await seen(page.getByRole('heading', { name: '4 inspiracje sekcji Hero' }));
  check('DIR: wyłączona opcja → zwykłe 4 kompozycje', genBodies.at(-1)?.keepDirection === false, JSON.stringify(genBodies.at(-1)));
  await release();
  await settled();

  // „Generuj jeszcze” — kolejne 4 propozycje doklejone pod poprzednimi, ten sam projekt i sekcja, następna runda.
  const more = page.getByTestId('generate-more');
  check('MORE: przycisk „Generuj jeszcze 4 propozycje” pod wynikami', (await seen(more)) && /Generuj jeszcze 4/.test(await more.textContent()));
  await more.click();
  check('MORE: od razu 4 nowe kafelki ładowania, poprzednie zostają', (await seen(page.getByRole('heading', { name: /Kolejne 4 propozycje \(runda 2\)/ }))) && (await generated.count()) === 8 && (await page.getByTestId('generated-loading').count()) === 4);
  const moreBody = genBodies.at(-1);
  check('MORE: ten sam prompt, projekt i sekcja, runda 1', moreBody?.prompt === 'sekcja Hero basen otwock h2otwock' && moreBody?.projectId === GEN_PROJECT.id && moreBody?.sectionId === 1 && moreBody?.round === 1, JSON.stringify(moreBody));
  check('MORE: przycisk zablokowany w trakcie', await more.isDisabled());
  await release();
  await page.waitForFunction(() => document.querySelectorAll('[data-testid="generated-loading"]').length === 0, null, { timeout: 5000 }).catch(() => {});
  check('MORE: gotowe — 8 propozycji (6 obrazów)', (await page.getByTestId('generated-results').getByRole('img', { name: /Inspiracja sekcji/ }).count()) === 6 && !(await more.isDisabled()));
  await more.click();
  await seen(page.getByRole('heading', { name: /runda 3/ }));
  check('MORE: kolejna runda → round 2', genBodies.at(-1)?.round === 2, JSON.stringify(genBodies.at(-1)));
  await release();
  await page.waitForFunction(() => document.querySelectorAll('[data-testid="generated-loading"]').length === 0, null, { timeout: 5000 }).catch(() => {});
  const moreOverflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check('MORE: brak overflow z 12 propozycjami', moreOverflow <= 0, `${moreOverflow}px`);
  await page.unroute('**/clara/v1/generate/*/render*', { behavior: 'ignoreErrors' });
  await page.unroute('**/clara/v1/projects*', { behavior: 'wait' });
  await page.unroute('**/clara/v1/projects/990001/direction*');
  await page.unroute('**/clara/v1/generate*');

  // Routing klienta.
  if (vp.width < 1024) await page.getByRole('button', { name: /Otwórz menu/ }).click();
  await page.getByRole('link', { name: 'Projekty' }).click();
  check('routing: /projects', new globalThis.URL(page.url()).pathname === '/projects' && (await page.getByRole('heading', { level: 1, name: 'Projekty' }).isVisible()));
  await page.goBack();
  check('routing: powrót do dashboardu', await heading.isVisible());

  // 409 z /generate jest oczekiwany (test bez klucza OpenAI).
  check('brak błędów JS w konsoli', errors.filter((e) => !/status of 409.*\/clara\/v1\/generate/.test(e)).length === 0, errors.join(' | '));
  await context.close();
}

// E2E: projekt, brief, sekcja, załączniki — zapis i odczyt po odświeżeniu, potem sprzątanie.
{
  console.log('== projekty E2E ==');
  const { page, context, errors } = await newPage(browser, { width: 1440, height: 1024 });
  page.on('dialog', (dialog) => dialog.accept());
  const name = `E2E projekt ${Date.now()}`;

  await page.goto(`${URL}/projects`, { waitUntil: 'networkidle' });
  check('strona projektów', await seen(page.getByRole('heading', { level: 1, name: 'Projekty' })));

  await page.getByRole('button', { name: 'Utwórz projekt' }).click();
  check('walidacja: wymagana nazwa', await seen(page.locator('#project-name-error').filter({ hasText: 'wymagane' })));

  await page.locator('#project-name').fill(name);
  await page.locator('#project-figma').fill('https://evil.example.com/design/x');
  await page.getByRole('button', { name: 'Utwórz projekt' }).click();
  check('walidacja: link Figma spoza figma.com', await seen(page.locator('#project-figma-error')));

  await page.locator('#project-figma').fill('https://www.figma.com/design/AbC123/Test');
  await page.locator('#project-client').fill('Klient E2E');
  await page.getByRole('button', { name: 'Utwórz projekt' }).click();
  await page.waitForURL(/\/projects\/\d+$/);
  const projectUrl = page.url();
  check('utworzenie projektu przenosi do szczegółów', await seen(page.getByRole('heading', { level: 1, name })));

  await page.locator('#p-summary').fill('Brief zapisany w E2E');
  await page.locator('#p-goals').fill('Cel A\nCel B');
  await page.getByRole('button', { name: 'Zapisz projekt' }).click();
  check('zapis briefu', await seen(page.getByRole('status').filter({ hasText: 'Zapisano projekt' })));

  await page.locator('#p-new-section').selectOption('hero');
  await page.getByRole('button', { name: 'Dodaj sekcję' }).click();
  const section = page.locator('details').filter({ hasText: 'Sekcja hero' });
  await section.locator('summary').click();
  await section.getByLabel(/Hard constraints/).fill('CTA widoczne bez przewijania');
  await section.getByLabel(/Soft constraints/).fill('Wodne błękity');
  await section.getByRole('button', { name: 'Zapisz sekcję' }).click();
  check('zapis sekcji z constraints', await seen(section.getByRole('status').filter({ hasText: 'Zapisano sekcję' })));

  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
  await page.locator('#p-file').setInputFiles({ name: 'szkic.png', mimeType: 'image/png', buffer: png });
  check('upload PNG z UI', await seen(page.getByRole('link', { name: 'szkic.png' })));
  await page.locator('#p-file').setInputFiles({ name: 'fałszywy.pdf', mimeType: 'application/pdf', buffer: Buffer.from('to nie jest pdf') });
  await page.getByRole('alert').filter({ hasText: 'Niedozwolony typ pliku' }).waitFor({ timeout: 5000 }).catch(() => {});
  check('fałszywy PDF odrzucony przez serwer z czytelnym błędem', await seen(page.getByRole('alert').filter({ hasText: 'Niedozwolony typ pliku' })));

  await page.reload({ waitUntil: 'networkidle' });
  check('po odświeżeniu: projekt i brief', (await page.locator('#p-summary').inputValue()) === 'Brief zapisany w E2E' && (await page.locator('#p-goals').inputValue()) === 'Cel A\nCel B');
  const reloaded = page.locator('details').filter({ hasText: 'Sekcja hero' });
  await reloaded.locator('summary').click();
  check('po odświeżeniu: sekcja i hard constraints', (await reloaded.getByLabel(/Hard constraints/).inputValue()) === 'CTA widoczne bez przewijania');
  check('po odświeżeniu: załącznik', await seen(page.getByRole('link', { name: 'szkic.png' })));

  await page.setViewportSize({ width: 390, height: 844 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check('szczegóły projektu 390: brak overflow', overflow <= 0, `${overflow}px`);
  await page.screenshot({ path: join(OUT, 'clara-project-390-full.png'), fullPage: true });
  await page.setViewportSize({ width: 1440, height: 1024 });
  await page.screenshot({ path: join(OUT, 'clara-project-1440-full.png'), fullPage: true });

  await page.getByRole('button', { name: 'Usuń projekt' }).click();
  await page.waitForURL(/\/projects$/);
  await page.waitForLoadState('networkidle');
  check('usunięcie projektu', !(await page.getByText(name).count()));
  await page.goto(projectUrl, { waitUntil: 'networkidle' });
  const gone = await seen(page.getByRole('alert').filter({ hasText: 'Nie znaleziono' }));
  check('usunięty projekt: czytelne „Nie znaleziono”', gone);

  check('projekty: brak błędów JS (poza oczekiwanymi 4xx)', errors.filter((e) => !/status of (400|404|415)/.test(e)).length === 0, errors.join(' | '));
  await context.close();
}

// E2E: Design System Manager + brak sekretów w JS.
{
  console.log('== design system E2E ==');
  const { page, context, errors } = await newPage(browser, { width: 1440, height: 1024 });
  page.on('dialog', (dialog) => dialog.accept());
  const responses = [];
  page.on('response', async (r) => {
    if (r.url().includes('/wp-json/clara/')) responses.push(await r.text().catch(() => ''));
  });
  const SECRET = /figd_|X-Figma-Token|CLARA_FIGMA_TOKEN/i;

  // Statyczny skan zbudowanych assetów — klucze nie mogą trafić do bundla.
  const { readdirSync } = await import('node:fs');
  const leaked = readdirSync(join(BUILD, 'assets')).filter((f) => f.endsWith('.js') && SECRET.test(readFileSync(join(BUILD, 'assets', f), 'utf8')));
  check('bundle JS bez tokenów/kluczy Figmy', leaked.length === 0, leaked.join(', '));

  await page.goto(`${URL}/projects`, { waitUntil: 'networkidle' });
  check('HTML panelu bez tokenów Figmy', !SECRET.test(await page.content()));
  await page.locator('#project-name').fill(`E2E DS ${Date.now()}`);
  await page.locator('#project-figma').fill('https://www.figma.com/design/AbCdEf1234567890XyZ/DS');
  await page.getByRole('button', { name: 'Utwórz projekt' }).click();
  await page.waitForURL(/\/projects\/\d+$/);

  const panel = page.locator('section', { has: page.getByRole('heading', { name: 'Design system' }) });
  check('DS: stan pusty', await seen(panel.getByText('Brak wersji')));
  check('DS: Figma wyłączona bez tokenu + informacja o Enterprise', (await panel.getByRole('button', { name: 'Pobierz style z Figmy' }).isDisabled()) && (await seen(panel.getByText(/Enterprise/))));

  await panel.getByRole('button', { name: '+ Dodaj: kolory' }).click();
  await panel.getByLabel('Kolory 1: Nazwa').fill('Primary');
  await panel.getByLabel('Kolory 1: Kolor (#hex)').fill('blue');
  await panel.getByRole('button', { name: 'Zapisz jako nową wersję' }).click();
  check('DS: błędny kolor → czytelny błąd przy polu', await seen(panel.getByText(/Niepoprawny kolor „blue”/)));
  await panel.getByLabel('Kolory 1: Kolor (#hex)').fill('#0A6CFF');
  await panel.getByRole('button', { name: 'Zapisz jako nową wersję' }).click();
  check('DS: zapis ręcznych tokenów → v1', await seen(panel.getByText('Zapisano tokeny jako v1.')));

  await panel.locator('#ds-import').setInputFiles({ name: 'zepsuty.json', mimeType: 'application/json', buffer: Buffer.from('{"colors": [}') });
  check('DS: import błędnego JSON → czytelny błąd', await seen(panel.getByRole('alert').filter({ hasText: 'Niepoprawny JSON' })));

  const dtcg = { color: { $type: 'color', water: { $value: '#00A6DF' }, accent: { $value: '{color.water}' } }, motion: { fast: { $type: 'duration', $value: '100ms' } } };
  await panel.locator('#ds-import').setInputFiles({ name: 'figma-variables.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(dtcg)) });
  check('DS: import W3C tokens → v2 z ostrzeżeniami', (await seen(panel.getByText('Zaimportowano „figma-variables.json” jako v2.'))) && (await seen(panel.getByLabel('Ostrzeżenia').getByText(/motion\.fast/))));

  await panel.locator('li', { hasText: 'v1' }).filter({ hasText: 'Ręcznie' }).getByRole('button', { name: 'Przywróć' }).click();
  check('DS: przywrócenie v1 → v3', await seen(panel.getByText('Przywrócono v1 jako v3.')));

  await page.reload({ waitUntil: 'networkidle' });
  check('DS po odświeżeniu: v3 z historią 3 wersji', (await seen(panel.getByTestId('ds-current').filter({ hasText: 'Wersja v3' }))) && (await panel.locator('ol > li').count()) === 3);
  check('DS po odświeżeniu: przywrócony kolor', (await panel.getByLabel('Kolory 1: Kolor (#hex)').inputValue()) === '#0a6cff');

  await page.setViewportSize({ width: 390, height: 844 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check('DS 390: brak overflow', overflow <= 0, `${overflow}px`);
  await panel.screenshot({ path: join(OUT, 'clara-ds-390.png') });
  await page.setViewportSize({ width: 1440, height: 1024 });
  await panel.screenshot({ path: join(OUT, 'clara-ds-1440.png') });

  check('odpowiedzi REST bez tokenów Figmy', !responses.some((body) => SECRET.test(body)));

  await page.getByRole('button', { name: 'Usuń projekt' }).click();
  await page.waitForURL(/\/projects$/);
  check('DS: brak błędów JS (poza oczekiwanymi 4xx)', errors.filter((e) => !/status of (400|404|409|415)/.test(e)).length === 0, errors.join(' | '));
  await context.close();
}

// E2E: Creative Brief + Art Direction (bez skonfigurowanego dostawcy → szablon deterministyczny).
{
  console.log('== art direction E2E ==');
  const { page, context, errors } = await newPage(browser, { width: 1440, height: 1024 });
  page.on('dialog', (dialog) => dialog.accept());

  await page.goto(`${URL}/projects`, { waitUntil: 'networkidle' });
  await page.locator('#project-name').fill(`E2E AD ${Date.now()}`);
  await page.getByRole('button', { name: 'Utwórz projekt' }).click();
  await page.waitForURL(/\/projects\/\d+$/);
  await page.getByRole('button', { name: 'Dodaj sekcję' }).click();
  const section = page.locator('details').first();
  await section.locator('summary').click();
  await section.locator('textarea[id$="-content"]').fill('Nagłówek z nazwą obiektu\nGodziny otwarcia');
  await section.locator('textarea[id$="-hard"]').fill('Kontrast tekstu WCAG AA');
  await section.getByRole('button', { name: 'Zapisz sekcję' }).click();
  check('AD: zapis wymagań sekcji', await seen(section.getByText('Zapisano sekcję.')));
  check('AD: informacja o braku dostawcy AI', await seen(section.getByText(/nie jest skonfigurowany/)));

  await section.getByRole('button', { name: 'Wygeneruj 4 kierunki' }).click();
  const run = section.getByTestId('art-run');
  check('AD: 4 kierunki po jednym żądaniu', (await seen(run)) && (await run.locator('article').count()) === 4);
  check('AD: oznaczenie szablonu i powód', (await seen(run.getByText('Szablon deterministyczny'))) && (await seen(run.getByTestId('fallback-reason'))));
  const compositions = await run.locator('article').evaluateAll((els) => els.map((el) => el.querySelector('p')?.textContent?.split('·')[0]));
  check('AD: 4 różne kompozycje', new Set(compositions).size === 4, compositions.join(' | '));
  const kept = await run.locator('article').evaluateAll((els) => els.every((el) => el.textContent.includes('Godziny otwarcia') && el.textContent.includes('Kontrast tekstu WCAG AA')));
  check('AD: każdy kierunek zawiera wymagane treści i hard constraint', kept);

  // Obrazy: 1 żądanie → 4 joby przetwarzane asynchronicznie przez WP-Cron, UI odpytuje status.
  const images = run.getByTestId('images-panel');
  check('IMG: oznaczenie mocka (brak generatora AI)', await seen(images.getByText(/obrazy poglądowe \(mock\)/)));
  const started = Date.now();
  await images.getByRole('button', { name: 'Wygeneruj 4 obrazy' }).click();
  check('IMG: statusy w trakcie (kolejka/generowanie)', await seen(images.locator('[data-testid="image-item"]')));
  const allDone = await images.locator('[data-testid="image-item"][data-status="succeeded"]').nth(3).waitFor({ timeout: 45000 }).then(() => true, () => false);
  check('IMG: 4 obrazy gotowe po 1 żądaniu (WP-Cron)', allDone && (await images.locator('[data-testid="image-item"]').count()) === 4, `${Date.now() - started} ms`);
  const loaded = await images.locator('img').evaluateAll((els) => els.length === 4 && els.every((img) => img.complete && img.naturalWidth === 1200));
  check('IMG: pliki obrazów wczytane (1200 px)', loaded);
  check('IMG: koszt zapisany', await seen(images.getByText(/koszt \$0\.0000/)));
  await images.screenshot({ path: join(OUT, 'clara-img-1440.png') });

  await page.reload({ waitUntil: 'networkidle' });
  await page.locator('details').first().locator('summary').click();
  check('IMG: obrazy zachowane po odświeżeniu', (await seen(page.getByTestId('images-panel').locator('img'))) && (await page.getByTestId('images-panel').locator('img').count()) === 4);
  check('AD: kierunki zachowane po odświeżeniu', (await seen(page.getByTestId('art-run'))) && (await page.getByTestId('art-run').locator('article').count()) === 4);

  // Galeria, wybór, Remix z referencją z kolekcji, porównanie i historia wersji.
  const projectUrl = page.url();
  await page.goto(`${URL}/collections`, { waitUntil: 'networkidle' });
  await page.locator('#c-url').fill('https://example.com/basen-noca');
  await page.locator('#c-title').fill('Basen nocą');
  await page.getByRole('button', { name: 'Dodaj', exact: true }).click();
  await seen(page.getByText('Dodano link do kolekcji.'));
  await page.goto(projectUrl, { waitUntil: 'networkidle' });
  await page.locator('details').first().locator('summary').click();
  const gallery = page.getByTestId('gallery-panel');
  const cards = gallery.getByTestId('gallery-item');
  check('GAL: galeria 4 gotowych wariantów', (await seen(cards.first())) && (await cards.count()) === 4 && (await gallery.locator('[data-testid="gallery-item"][data-status="succeeded"]').count()) === 4);
  const original = cards.filter({ hasText: 'A · v1' });
  const originalSrc = await original.locator('img').getAttribute('src');
  await original.getByRole('button', { name: 'Wybierz' }).click();
  check('GAL: wybór wariantu', await original.getByText('Wybrany').waitFor({ timeout: 5000 }).then(() => true, () => false));

  await original.getByRole('button', { name: 'Remix' }).click();
  const remixForm = gallery.getByTestId('remix-form');
  await remixForm.getByRole('button', { name: 'Utwórz nową wersję' }).click();
  check('GAL: remix bez instrukcji → błąd przy polu', await seen(remixForm.getByText('Pole jest wymagane.')));
  await remixForm.locator('textarea').fill('Cieplejsze światło zachodu słońca');
  await remixForm.getByLabel('Basen nocą').check();
  await remixForm.getByRole('button', { name: 'Utwórz nową wersję' }).click();
  check('GAL: remix zlecony jako nowa wersja', await seen(gallery.getByText(/oryginał pozostaje bez zmian/)));
  const remixCard = cards.filter({ hasText: 'A · v2 (remiks)' });
  const remixDone = await gallery.locator('[data-testid="gallery-item"][data-status="succeeded"]').nth(4).waitFor({ timeout: 45000 }).then(() => true, () => false);
  check('GAL: 5 wariantów, remiks gotowy (WP-Cron)', remixDone && (await cards.count()) === 5 && (await seen(remixCard.locator('img'))));
  check('GAL: oryginał zachowany (ten sam plik, nadal wybrany)', (await original.locator('img').getAttribute('src')) === originalSrc && (await original.getAttribute('data-selected')) === 'true' && (await remixCard.locator('img').getAttribute('src')) !== originalSrc);

  await original.getByLabel('Porównaj').check();
  await remixCard.getByLabel('Porównaj').check();
  const compareView = gallery.getByTestId('compare-view');
  check('GAL: porównanie 2 wariantów obok siebie z instrukcją i referencją', (await seen(compareView)) && (await compareView.locator('figure').count()) === 2 && (await seen(compareView.getByText('Instrukcja remiksu: Cieplejsze światło zachodu słońca'))) && (await seen(compareView.getByRole('link', { name: 'Basen nocą' }))));
  await gallery.screenshot({ path: join(OUT, 'clara-gallery-1440.png') });

  await remixCard.getByRole('button', { name: 'Historia' }).click();
  const history = gallery.getByTestId('lineage');
  check('GAL: historia wersji v1 → v2 z kierunkiem A', (await seen(history)) && (await history.locator('ol > li').count()) === 2 && (await seen(history.getByText(/kierunek A/))) && (await seen(history.getByText('v2 ← z v1'))));

  await page.reload({ waitUntil: 'networkidle' });
  await page.locator('details').first().locator('summary').click();
  check('GAL: wybór i wersje zachowane po odświeżeniu', (await seen(cards.first())) && (await cards.count()) === 5 && (await cards.filter({ hasText: 'A · v1' }).getAttribute('data-selected')) === 'true');

  // Visual-to-Layout JSON dla wybranego wariantu: tekst jako tekst, bitmapy, porównanie z obrazem.
  const layoutPanel = gallery.getByTestId('layout-panel');
  check('LAY: panel layoutu dla wybranego wariantu', await seen(layoutPanel.getByText(/Layout JSON — wariant A · v1/)));
  await layoutPanel.getByRole('button', { name: 'Zbuduj Layout JSON' }).click();
  const preview = layoutPanel.getByTestId('layout-preview');
  check('LAY: zbudowany layout + podgląd HTML/CSS', (await seen(layoutPanel.getByText('Zbudowano nową wersję Layout JSON.'))) && (await seen(preview)));
  check('LAY: oznaczenie rekonstrukcji heurystycznej i podsumowanie warstw', (await seen(layoutPanel.getByTestId('layout-analyzer').getByText(/heurystyczna/))) && (await seen(layoutPanel.getByTestId('layout-summary').getByText(/1 bitmap/))));
  const domTexts = await preview.locator('[data-node-type="text"], [data-node-type="button"]').allInnerTexts();
  check('LAY: tekst pozostaje tekstem (DOM) — wymagane treści i CTA', domTexts.includes('Nagłówek z nazwą obiektu') && domTexts.includes('Godziny otwarcia') && domTexts.length >= 3, domTexts.join(' | '));
  const raster = preview.locator('[data-rasterized="true"]');
  check('LAY: kadr wizualny jako bitmapa obrazu źródłowego', (await raster.count()) === 1 && (await raster.evaluate((el) => getComputedStyle(el).backgroundImage)).includes('clara-hero-A'));
  check('LAY: porównanie obok siebie (obraz | podgląd)', await seen(layoutPanel.getByTestId('layout-compare').locator('img')));
  const sourceShot = await layoutPanel.getByTestId('layout-compare').locator('img').screenshot();
  const previewShot = await preview.screenshot();
  const diff = await page.evaluate(async ([a, b]) => {
    const load = (b64) => new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.src = `data:image/png;base64,${b64}`; });
    const [ia, ib] = await Promise.all([load(a), load(b)]);
    const c = document.createElement('canvas');
    c.width = 160; c.height = 90;
    const ctx = c.getContext('2d');
    const px = (img) => { ctx.clearRect(0, 0, 160, 90); ctx.drawImage(img, 0, 0, 160, 90); return ctx.getImageData(0, 0, 160, 90).data; };
    const pa = px(ia); const pb = px(ib);
    let sum = 0;
    for (let k = 0; k < pa.length; k += 4) sum += (Math.abs(pa[k] - pb[k]) + Math.abs(pa[k + 1] - pb[k + 1]) + Math.abs(pa[k + 2] - pb[k + 2])) / 765;
    return sum / (pa.length / 4);
  }, [sourceShot.toString('base64'), previewShot.toString('base64')]);
  check('LAY: podgląd zbliżony do obrazu (średnia różnica pikseli < 25%)', diff < 0.25, `${(diff * 100).toFixed(1)}%`);
  console.log(`  (różnica obraz ↔ podgląd: ${(diff * 100).toFixed(1)}%)`);
  await layoutPanel.getByLabel('Nakładka').check();
  check('LAY: tryb nakładki z suwakiem przezroczystości', (await seen(layoutPanel.getByTestId('layout-overlay'))) && (await seen(layoutPanel.getByLabel('Przezroczystość podglądu'))));
  await layoutPanel.screenshot({ path: join(OUT, 'clara-layout-1440.png') });
  await layoutPanel.getByLabel('Obok siebie').check();

  await layoutPanel.getByText('Edytuj Layout JSON').click();
  const editor = layoutPanel.locator('textarea[id^="layout-json-"]');
  const originalJson = await editor.inputValue();
  const edit = (fn) => { const l = JSON.parse(originalJson); fn(l, l.nodes.find((n) => n.type === 'text')); return JSON.stringify(l, null, 2); };
  await editor.fill(edit((l, t) => { t.rasterized = true; }));
  await layoutPanel.getByRole('button', { name: 'Sprawdź' }).click();
  check('LAY: walidator odrzuca rasteryzowany tekst (błąd ze ścieżką)', await seen(layoutPanel.getByTestId('layout-errors').getByText(/Tekst musi pozostać tekstem/)));
  await editor.fill('{"schemaVersion": 1,');
  await layoutPanel.getByRole('button', { name: 'Zapisz jako nową wersję' }).click();
  check('LAY: uszkodzony JSON nie jest zapisywany', await seen(layoutPanel.getByText('Niepoprawny JSON — sprawdź składnię.')));
  await editor.fill(edit((l, t) => { t.frame.y = Math.max(0, t.frame.y - 10); }));
  await layoutPanel.getByRole('button', { name: 'Zapisz jako nową wersję' }).click();
  check('LAY: poprawka zapisana jako nowa wersja (2 wersje)', (await seen(layoutPanel.getByText(/Zapisano poprawkę jako nową wersję/))) && (await layoutPanel.getByLabel('Wersja layoutu').locator('option').count()) === 2);

  const fig = layoutPanel.getByTestId('figma-export');
  check('FIG: sekcja eksportu bez obietnicy pixel-perfect', (await seen(fig)) && (await fig.innerText()).includes('nie jest odwzorowanie pixel-perfect'));
  const [figDownload] = await Promise.all([page.waitForEvent('download'), fig.getByRole('button', { name: 'Pobierz paczkę dla Figmy' }).click()]);
  const figBundle = JSON.parse(readFileSync(await figDownload.path(), 'utf8'));
  check('FIG: pobrana paczka clara-figma-bundle v1 z osadzonym obrazem', figDownload.suggestedFilename().startsWith('clara-figma-layout-') && figBundle.format === 'clara-figma-bundle' && figBundle.version === 1 && Object.values(figBundle.assets).every((a) => a.mime === 'image/png' && a.data.length > 1000));
  await fig.getByRole('button', { name: 'Wygeneruj kod jednorazowy' }).click();
  const figCode = await fig.getByTestId('figma-token').locator('input').inputValue();
  check('FIG: kod jednorazowy (43 znaki) z czasem ważności i adresem Clara', /^[A-Za-z0-9_-]{43}$/.test(figCode) && (await fig.getByTestId('figma-token').innerText()).includes('ważny do'));
  check('FIG: kod nie trafia do adresu strony', !page.url().includes(figCode));
  const figRedeem = await page.request.post(new globalThis.URL('/wp-json/clara/v1/figma/bundle', URL).href, { headers: { 'Content-Type': 'text/plain', Origin: 'null' }, data: JSON.stringify({ token: figCode }) });
  const figRedeemed = await figRedeem.json();
  check('FIG: plugin wymienia kod na tę samą paczkę (CORS *)', figRedeem.status() === 200 && figRedeemed.layoutId === figBundle.layoutId && figRedeem.headers()['access-control-allow-origin'] === '*');
  const figAgain = await page.request.post(new globalThis.URL('/wp-json/clara/v1/figma/bundle', URL).href, { headers: { 'Content-Type': 'text/plain' }, data: JSON.stringify({ token: figCode }) });
  check('FIG: drugie użycie kodu → 401', figAgain.status() === 401);

  await page.setViewportSize({ width: 390, height: 844 });
  const layOverflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check('LAY 390: brak overflow z podglądem', layOverflow <= 0, `${layOverflow}px`);
  await layoutPanel.screenshot({ path: join(OUT, 'clara-layout-390.png') });
  await page.setViewportSize({ width: 1440, height: 1024 });

  await page.setViewportSize({ width: 390, height: 844 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check('AD 390: brak overflow', overflow <= 0, `${overflow}px`);
  await page.getByTestId('art-run').screenshot({ path: join(OUT, 'clara-ad-390.png') });
  await page.setViewportSize({ width: 1440, height: 1024 });
  await page.getByTestId('art-run').screenshot({ path: join(OUT, 'clara-ad-1440.png') });

  await page.getByRole('button', { name: 'Usuń projekt' }).click();
  await page.waitForURL(/\/projects$/);
  check('AD: brak błędów JS (poza oczekiwanym 400 walidacji)', errors.filter((e) => !/status of 400/.test(e)).length === 0, errors.join(' | '));
  await context.close();
}

// E2E: Inspiration Finder i kolekcje (wyszukiwarka testowa przełączona w visual.sh na mock).
{
  console.log('== inspiration finder E2E ==');
  const { page, context, errors } = await newPage(browser, { width: 1440, height: 1024 });
  page.on('dialog', (dialog) => dialog.accept());
  const name = `E2E INS ${Date.now()}`;

  await page.goto(`${URL}/projects`, { waitUntil: 'networkidle' });
  await page.locator('#project-name').fill(name);
  await page.getByRole('button', { name: 'Utwórz projekt' }).click();
  await page.waitForURL(/\/projects\/\d+$/);
  const projectId = page.url().split('/').pop();

  await page.goto(`${URL}/`, { waitUntil: 'networkidle' });
  await page.locator('label', { hasText: 'Szukaj zdjęć' }).click();
  await page.locator('#clara-prompt').fill('basen hero');
  await page.getByRole('button', { name: 'Szukaj zdjęć' }).click();
  const results = page.getByTestId('search-results');
  check('INS: wyniki z oznaczeniem źródła testowego', (await seen(results.getByText(/6 zdjęć · źródło: Mock/))) && (await results.getByTestId('inspiration-card').count()) === 6);
  const cards = results.getByTestId('inspiration-card');
  check('INS: licencja tylko gdy zadeklarowana', (await cards.nth(0).getByTestId('license').innerText()).includes('CC BY 4.0') && (await cards.nth(1).getByTestId('license').innerText()).includes('Licencja nieznana'));
  const source = cards.nth(0).getByRole('link', { name: /Otwórz źródło/ });
  const href = await source.getAttribute('href');
  const linkStatus = (await page.request.get(href)).status();
  check('INS: link źródłowy działa (nowa karta, noopener)', linkStatus === 200 && (await source.getAttribute('target')) === '_blank' && (await source.getAttribute('rel')).includes('noopener'), `${href} → ${linkStatus}`);

  // Zapis bez wybranego projektu: trafia do ostatniego projektu (tu: właśnie utworzonego), bez błędu.
  await cards.nth(0).getByRole('button', { name: 'Zapisz' }).click();
  check('INS: zapis bez wyboru projektu → ostatni projekt', (await seen(cards.nth(0).getByText('Zapisano'))) && (await page.locator('#clara-save-project').inputValue()) === projectId && !(await page.getByText('Wybierz projekt, do którego').count()));
  await page.locator('#clara-filter-project').selectOption(projectId);
  check('INS: filtr projektu ustawia projekt zapisu', (await page.locator('#clara-save-project').inputValue()) === projectId);

  await page.setViewportSize({ width: 390, height: 844 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check('INS 390: brak overflow z wynikami', overflow <= 0, `${overflow}px`);
  await page.setViewportSize({ width: 1440, height: 1024 });
  await results.screenshot({ path: join(OUT, 'clara-search-1440.png') });

  await page.locator('#clara-prompt').fill('zz nic takiego');
  await page.getByRole('button', { name: 'Szukaj zdjęć' }).click();
  check('INS: puste wyniki — czytelny komunikat', await seen(page.getByTestId('search-empty')));
  await page.locator('#clara-prompt').fill('blad dostawcy');
  await page.getByRole('button', { name: 'Szukaj zdjęć' }).click();
  check('INS: błąd dostawcy — komunikat', await seen(results.getByText('Wyszukiwarka testowa zwróciła błąd.')));

  await page.goto(`${URL}/collections`, { waitUntil: 'networkidle' });
  await page.locator('#clara-collection-project').selectOption(projectId);
  const saved = page.getByRole('list', { name: 'Zapisane inspiracje' }).getByTestId('inspiration-card');
  check('INS: kolekcja po odświeżeniu zawiera zapisaną inspirację z licencją', (await seen(saved)) && (await saved.count()) === 1 && (await saved.first().getByTestId('license').innerText()).includes('CC BY 4.0'));
  await page.locator('#c-url').fill('http://192.168.1.10/x');
  await page.getByRole('button', { name: 'Dodaj', exact: true }).click();
  check('INS: ręczny link prywatny → błąd przy polu', await seen(page.getByText('Adres musi prowadzić do publicznej strony.')));
  await page.locator('#c-url').fill('https://example.com/inspiracja');
  await page.getByRole('button', { name: 'Dodaj', exact: true }).click();
  check('INS: ręczny link dodany z licencją nieznaną', (await seen(page.getByText('Dodano link do kolekcji.'))) && (await saved.count()) === 2 && (await saved.first().getByTestId('license').innerText()).includes('Licencja nieznana'));
  await saved.first().getByRole('button', { name: /Usuń z kolekcji/ }).click();
  check('INS: usunięcie z kolekcji', await saved.nth(1).waitFor({ state: 'detached', timeout: 5000 }).then(() => true, () => false));
  await page.screenshot({ path: join(OUT, 'clara-collections-1440.png') });

  await page.goto(`${URL}/projects/${projectId}`, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Usuń projekt' }).click();
  await page.waitForURL(/\/projects$/);
  check('INS: brak błędów JS (poza oczekiwanymi 4xx/5xx)', errors.filter((e) => !/status of (400|502)/.test(e)).length === 0, errors.join(' | '));
  await context.close();
}

// Ustawienia: akcent i skala UI (zapis w profilu; oryginalne preferencje przywracane na końcu).
{
  console.log('== ustawienia wyglądu E2E ==');
  const { page, context, errors } = await newPage(browser, { width: 1440, height: 1024 });
  const api = (method, data) => page.evaluate(([m, d]) => window.wp.apiFetch({ path: '/clara/v1/preferences', method: m, data: d }), [method, data]);
  await page.goto(`${URL}/settings`, { waitUntil: 'networkidle' });
  const original = await api('GET');
  try {
    await api('PATCH', { accent: 'blue', scale: 100, effects: 'full' });
    await page.reload({ waitUntil: 'networkidle' });
    const root = () => page.evaluate(() => ({ accent: document.documentElement.dataset.accent, scale: document.documentElement.dataset.scale, font: getComputedStyle(document.documentElement).fontSize, btn: getComputedStyle(document.querySelector('[aria-label="Podgląd akcentu"] button')).backgroundColor }));
    check('SET: strona ustawień z grupami akcentu i skali', (await seen(page.getByRole('group', { name: 'Akcent kolorystyczny' }))) && (await seen(page.getByRole('group', { name: 'Skala interfejsu' }))));
    const before = await root();
    await page.locator('label', { hasText: 'Róż' }).click();
    await seen(page.getByText('Zapisano ustawienia wyglądu.'));
    // Przycisk ma transition-colors — czekamy na koniec przejścia, nie na kolor w połowie animacji.
    await page.waitForFunction(() => getComputedStyle(document.querySelector('[aria-label="Podgląd akcentu"] button')).backgroundColor === 'rgb(192, 37, 111)', null, { timeout: 2000 }).catch(() => {});
    const pink = await root();
    check('SET: akcent Róż zmienia tokeny od razu (przycisk #c0256f)', pink.accent === 'pink' && pink.btn === 'rgb(192, 37, 111)' && before.btn !== pink.btn, `${before.btn} → ${pink.btn}`);
    await page.locator('label', { hasText: /Duża/ }).click();
    await seen(page.getByText('Zapisano ustawienia wyglądu.'));
    check('SET: skala 125% → rozmiar bazowy 20px', (await root()).font === '20px', (await root()).font);
    await page.reload({ waitUntil: 'networkidle' });
    const persisted = await root();
    check('SET: ustawienia zapisane w profilu i renderowane przez serwer po odświeżeniu', persisted.accent === 'pink' && persisted.scale === '125' && persisted.font === '20px', persisted);
    const contrast = await page.evaluate(contrastAudit);
    check(`SET: kontrast WCAG AA z akcentem Róż (${contrast.checked} elementów)`, contrast.issues.length === 0, contrast.issues.join('; '));
    for (const accent of ['violet', 'teal', 'sunset']) {
      await api('PATCH', { accent });
      await page.goto(`${URL}/`, { waitUntil: 'networkidle' });
      const audit = await page.evaluate(contrastAudit);
      check(`SET: kontrast WCAG AA na dashboardzie — akcent ${accent}`, audit.issues.length === 0, audit.issues.join('; '));
    }
    await page.setViewportSize({ width: 390, height: 844 });
    for (const path of ['', 'projects', 'settings']) {
      await page.goto(`${URL}/${path}`, { waitUntil: 'networkidle' });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      check(`SET 390 @125%: brak overflow (/${path})`, overflow <= 0, `${overflow}px`);
    }
    await page.screenshot({ path: join(OUT, 'clara-settings-390-125.png'), fullPage: true });
    await page.setViewportSize({ width: 1440, height: 1024 });
    await page.locator('label', { hasText: 'Mini' }).click();
    await seen(page.getByText('Zapisano ustawienia wyglądu.'));
    check('SET: skala Mini 75% → rozmiar bazowy 12px', (await root()).font === '12px', (await root()).font);
    await page.setViewportSize({ width: 390, height: 844 });
    const miniOverflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check('SET 390 @75%: brak overflow', miniOverflow <= 0, `${miniOverflow}px`);
    await page.setViewportSize({ width: 1440, height: 1024 });
    const glass = () => page.evaluate(() => { const el = document.querySelector('fieldset.glass'); const cs = getComputedStyle(el); return { blur: cs.backdropFilter, effects: document.documentElement.dataset.effects, glass: document.documentElement.dataset.glass, ambient: getComputedStyle(document.querySelector('.clara-ambient')).display }; });
    const full = await glass();
    check('SET: efekty pełne — szkło z rozmyciem tła i warstwa tła WebGL', /blur/.test(full.blur) && full.effects === 'full' && full.ambient !== 'none', JSON.stringify(full));
    const webgl = await page.evaluate(() => Boolean(document.createElement('canvas').getContext('webgl')));
    check(webgl ? 'SET: szkło rysowane przez WebGL (shader skompilowany, CSS tylko lekkie zmatowienie)' : 'SET: bez WebGL — szkło CSS jako fallback', webgl ? full.glass === 'webgl' && /blur\(6px\)/.test(full.blur) : !full.glass && /blur\(18px\)/.test(full.blur), JSON.stringify({ webgl, ...full }));
    await page.locator('label', { hasText: 'Ograniczone' }).click();
    await seen(page.getByText('Zapisano ustawienia wyglądu.'));
    const reduced = await glass();
    check('SET: efekty ograniczone — bez rozmycia i bez animowanego tła', reduced.effects === 'reduced' && !/blur/.test(reduced.blur) && reduced.ambient === 'none', JSON.stringify(reduced));
    const reducedContrast = await page.evaluate(contrastAudit);
    check(`SET: kontrast WCAG AA w trybie ograniczonym (${reducedContrast.checked} elementów)`, reducedContrast.issues.length === 0, reducedContrast.issues.join('; '));
    const bad = await page.evaluate(() => window.wp.apiFetch({ path: '/clara/v1/preferences', method: 'PATCH', data: { accent: 'neon', scale: 300, effects: 'max' } }).then(() => null, (e) => e));
    check('SET: błędne wartości odrzucone z błędami pól', bad?.code === 'clara_invalid' && bad.data.errors.accent && bad.data.errors.scale && bad.data.errors.effects);
    await page.getByRole('button', { name: 'Przywróć domyślne' }).click();
    await seen(page.getByText('Zapisano ustawienia wyglądu.'));
    const reset = await root();
    check('SET: przywrócenie domyślnych (niebieski, 100%, efekty pełne)', reset.accent === 'blue' && reset.scale === '100' && reset.font === '16px' && (await glass()).effects === 'full', reset);
    await page.screenshot({ path: join(OUT, 'clara-settings-1440.png') });
    check('SET: brak błędów JS (poza oczekiwanym 400 walidacji)', errors.filter((e) => !/status of 400/.test(e)).length === 0, errors.join(' | '));
  } finally {
    await api('PATCH', { accent: original.accent, scale: original.scale, effects: original.effects ?? 'full' });
  }
  await context.close();
}

// Porównanie obok siebie z mockupem (desktop).
if (env.CLARA_REFERENCE && existsSync(env.CLARA_REFERENCE)) {
  const page = await browser.newPage({ viewport: { width: 2976, height: 1024 } });
  const img = (p) => `data:image/png;base64,${readFileSync(p).toString('base64')}`;
  await page.setContent(`<body style="margin:0;display:flex;gap:16px;background:#000">
    <img src="${img(env.CLARA_REFERENCE)}" style="width:1440px;height:960px;object-fit:cover;object-position:top left">
    <img src="${img(join(OUT, 'clara-desktop-1440.png'))}" style="width:1440px;height:1024px;object-fit:cover;object-position:top left"></body>`);
  await page.screenshot({ path: join(OUT, 'clara-compare-1440.png') });
  console.log('Porównanie: clara-compare-1440.png (lewo: mockup, prawo: implementacja)');
}

{
  // Ekran logowania (bez sesji): wp-login.php w stylu Clara, assety z buildu testowego.
  console.log('== ekran logowania ==');
  for (const vp of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    const context = await browser.newContext({ viewport: vp, deviceScaleFactor: 1 });
    await context.route(`**${BUILD_PATH}**`, (route) => {
      const file = join(BUILD, new globalThis.URL(route.request().url()).pathname.slice(BUILD_PATH.length));
      if (!existsSync(file)) return route.fulfill({ status: 404 });
      return route.fulfill({ body: readFileSync(file), contentType: types[extname(file)] ?? 'application/octet-stream' });
    });
    await context.route(/\/wp-login\.php/, async (route) => {
      if (route.request().resourceType() !== 'document') return route.continue();
      const response = await route.fetch();
      const escaped = BUILD_PATH.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');
      const own = new RegExp(`<link[^>]+href="[^"]*${escaped}[^"]*"[^>]*>\\s*`, 'g');
      const css = manifest['resources/css/clara-login.css'];
      const html = (await response.text()).replace(own, '').replace('</head>', `<link rel="stylesheet" href="${BUILD_PATH}${css.file}">\n</head>`);
      return route.fulfill({ response, body: html });
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
    const tag = `LOGIN ${vp.width}`;
    const redirect = await page.request.get(`${URL}/`, { maxRedirects: 0 });
    check(`${tag}: strona główna bez sesji → przekierowanie do logowania`, redirect.status() === 302 && /wp-login\.php/.test(redirect.headers().location ?? ''), redirect.status());
    await page.goto(`${URL}/wp-login.php?redirect_to=${encodeURIComponent(`${URL}/`)}`, { waitUntil: 'networkidle' });
    check(`${tag}: ekran logowania Clara`, (await page.locator('body.clara-login').count()) === 1);
    check(`${tag}: postać i nagłówek Clara`, (await page.locator('.clara-login-figure').isVisible()) && (await page.getByText('Inspiracje dla Twoich').isVisible()));
    const figure = await page.locator('.clara-login-figure').evaluate((el) => ({ bg: getComputedStyle(el).backgroundImage, h: el.getBoundingClientRect().height }));
    check(`${tag}: obraz postaci załadowany`, /hero-figure/.test(figure.bg) && figure.h > 100, JSON.stringify(figure));
    check(`${tag}: formularz logowania (login, hasło, przycisk)`, (await page.getByLabel(/Nazwa użytkownika|adres e-mail|Username/i).isVisible()) && (await page.locator('#user_pass').isVisible()) && (await page.getByRole('button', { name: /Zaloguj|Log In/ }).isVisible()));
    check(`${tag}: stary nagłówek WP ukryty wizualnie, dostępny dla czytników`, (await page.getByRole('heading', { level: 1 }).count()) >= 1);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check(`${tag}: brak poziomego overflow`, overflow <= 0, `${overflow}px`);
    const audit = await page.evaluate(contrastAudit);
    check(`${tag}: kontrast WCAG AA (${audit.checked} elementów)`, audit.issues.length === 0, audit.issues.join('; '));
    await page.locator('#user_pass').focus();
    const ring = await page.locator('#user_pass').evaluate((el) => getComputedStyle(el).boxShadow);
    check(`${tag}: widoczny focus pola`, ring !== 'none', ring);
    await page.screenshot({ path: join(OUT, `clara-login-${vp.width}.png`) });
    await page.locator('#user_login').fill('clara-e2e-nie-istnieje');
    await page.locator('#user_pass').fill('zle-haslo-e2e');
    await page.getByRole('button', { name: /Zaloguj|Log In/ }).click();
    await page.waitForLoadState('networkidle');
    const errorBox = await page.locator('#login_error').evaluate((el) => getComputedStyle(el).borderLeftColor).catch(() => null);
    check(`${tag}: błąd logowania czytelny w stylu Clara`, errorBox === 'rgb(248, 113, 113)' && (await page.locator('body.clara-login').count()) === 1, errorBox);
    check(`${tag}: brak błędów JS`, errors.length === 0, errors.join(' | '));
    await context.close();
  }
}

await browser.close();
console.log(`UI: ${pass} PASS, ${fail} FAIL`);
process.exit(fail ? 1 : 0);
