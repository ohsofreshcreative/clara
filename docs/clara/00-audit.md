# Clara PoC — Etap 00: audyt środowiska i kontrakt PoC

Data audytu: 2026-11-07. Tylko odczyt; jedyną zmianą w repo jest ten plik.

## 1. Wykryte środowisko

| Element | Stan |
|---|---|
| LocalWP | strona `clara` (ID `XzmkTfHcR`), domena `http://clara.local`, status: running |
| Usługi Local | PHP 8.5.12, MySQL 8.4.0, nginx 1.26.1, Mailpit 1.24.1 |
| WordPress | 7.1.4, prefiks tabel `wp_`, `WP_ENVIRONMENT_TYPE=local`, `WP_DEBUG` wyłączony |
| WP-CLI | 2.12.0 (binarka Local). Wymaga socketu: `php -d "mysqli.default_socket=$HOME/Library/Application Support/Local/run/XzmkTfHcR/mysql/mysqld.sock" $(which wp) --path=/Users/Arek/Websites/clara/app/public …` |
| WP-Cron | `wp cron test` — działa |
| REST | `/wp-json/` → 200; `/wp-json/clara/v1/` → 404 (namespace jeszcze nie istnieje) |
| Motyw | `clara` (Sage 11, aktywny), Acorn 5.0.5 (Laravel 12.16), ACF Composer 3.x, `vendor/` zainstalowany |
| Composer | 2.8.6 |
| Node / yarn | Node 26.0.0, yarn 1.22.22 (repo deklaruje `.yarnrc.yml` z `nodeLinker` — to składnia Berry; yarn 1 ją ignoruje) |
| Front | Vite 8.3.5, Tailwind 4.3.3, React 19.2.6, `@vitejs/plugin-react` 5.2.0, Alpine, Swiper, GSAP (CDN) |
| TypeScript | **brak** (`typescript` niezainstalowany, brak `tsconfig.json`, zero plików `.ts/.tsx`) |
| Testy | brak PHPUnit/Pest, brak Vitest; jest tylko `vendor/bin/pint` |
| Pluginy | ACF Pro 6.8.8, CF7, All-in-One WP Migration i inne narzędziowe (pełna lista: `wp plugin list`); WooCommerce **nie** zainstalowany |
| Użytkownicy | 1 administrator |
| Tabele `clara_*` | brak |
| Figma | konektor MCP zalogowany; plan **Pro**, seat Full (zespół OhSoFresh) |
| Klucze AI / search | brak zmiennych środowiskowych i stałych w `wp-config.php` dla jakiegokolwiek providera |
| Referencja UI | `references/clara-dashboard.png` **nie występuje** na dysku (ani w repo, ani w załącznikach Christie) |
| Dokumenty projektu | MASTER/ARCHITECTURE/DECISIONS itd. są tylko w Christie, nie w repo |

## 2. Stan repozytorium

- Motyw sklonowany z poprzedniego projektu OhSoFresh (szablon Sage z 22 blokami ACF), jeden commit `Initial commit`.
- Niezacommitowane zmiany sprzed audytu (nie moje): `style.css`, `vite.config.js` — rebranding AluSteel → Clara.
- Projekt nie jest pusty, więc **bootstrap nowej instalacji nie jest potrzebny**; Clara powstanie jako moduł w istniejącym motywie.

## 3. Lista braków

1. TypeScript + konfiguracja (`tsconfig`, typy WP) — potrzebne od etapu 01 (React shell) i 10 (plugin Figmy).
2. Narzędzia testów: PHP (PHPUnit/Pest lub testy przez WP-CLI `eval-file`) i JS (Vitest) — dziś brak jakiejkolwiek automatyzacji.
3. `sass` nie jest w `devDependencies`, a `app.css` importuje pliki `.scss` — `yarn build` może się nie udać; do potwierdzenia przez użytkownika.
4. Brak obrazu referencyjnego `references/clara-dashboard.png` — blokuje porównanie wizualne w etapie 02.
5. Brak kluczy providerów AI (LLM, obraz, wyszukiwanie) — etapy 05–07 tylko z mockiem, dopóki ich nie będzie.
6. Brak testowego pliku Figmy dla eksportu (etap 10).
7. `AGENTS.md` opisuje inny projekt (`bergermann`, 35 bloków, port 5981) — rozjazd z rzeczywistością (22 bloki, `clara.local:6011`).
8. Znane martwe odwołania z szablonu: `App\Blocks\ExampleBlock` (`functions.php`, `ThemeServiceProvider`), glob `app/Woo/*` — nie blokują, ale warto pamiętać przy etapie 01.
9. W composer jest `generoi/sage-woocommerce`, choć PoC nie używa WooCommerce — zostawić, nie rozbudowywać.

## 4. Decyzje integracyjne (propozycja)

- **I01 Umiejscowienie:** Clara jako moduł motywu `clara`: `app/Clara/*` (namespace `App\Clara`), REST `clara/v1` rejestrowany z Service Providera Acorn, tabele przez `$wpdb->prefix . 'clara_'` + `dbDelta` z wersją schematu w opcji (idempotentne migracje). Bez osobnego pluginu, chyba że użytkownik zdecyduje inaczej.
- **I02 Panel:** osobna strona (route/szablon) dostępna po zalogowaniu, z React+TS montowanym przez Vite (`@vitejs/plugin-react` jest już w zależnościach); auth przez cookie + `X-WP-Nonce`, capability właściciela projektu.
- **I03 Kolejka generacji:** tabela `clara_jobs` + worker WP-CLI (`wp clara worker`) jako główny tryb lokalny; WP-Cron (działa) jako fallback. To funkcja biznesowa, nie runner Christie.
- **I04 Providerzy:** interfejsy `LlmProvider`, `ImageProvider`, `SearchProvider` z implementacją `Mock*` jako domyślną; realne adaptery włączane kluczem w środowisku serwera (`wp-config.php`/env), nigdy w JS.
- **I05 Figma:** eksport przez plugin TS (Plugin API) importujący Layout JSON z pliku/endpointu. Ingest design systemu: REST `GET /v1/files/:key` (style, węzły) + ręczny import tokenów JSON jako fallback (D06).
- **I06 Testy:** do ustalenia w etapie 01 — minimum PHPUnit/WP-CLI dla REST i migracji, Vitest dla walidatora Layout JSON.

## 5. Realne API, limity i ryzyka

| Obszar | Ustalenie | Ryzyko / skutek |
|---|---|---|
| Figma REST — Variables | `GET /v1/files/:key/variables/local` wymaga planu **Enterprise** | Plan Pro → tokeny ze zmiennych niedostępne przez REST; zostają style z pliku, Plugin API (w pluginie zmienne są dostępne) albo ręczny import JSON |
| Figma REST — limity | limity zależne od planu i typu seatu (tiered rate limits), odpowiedź 429 z `Retry-After` | Ingest musi mieć cache i obsługę 429; nie odpytywać w pętli |
| Figma REST — auth | personal access token lub OAuth, tylko po stronie serwera | Token nie może trafić do JS ani logów |
| Figma Plugin API | wymaga uruchomienia w aplikacji Figma (desktop, dev plugin z manifestu); fonty przez `loadFontAsync` — brak fontu = błąd; obrazy przez `createImage` (bytes), limit rozmiaru obrazu ~4096 px | Test live etapu 10 wymaga ręcznego uruchomienia przez użytkownika; agent nie zautomatyzuje GUI Figmy |
| Plugin → endpoint | plugin działa w sandboxie, sieć przez UI iframe z `networkAccess` w manifeście; `clara.local` (HTTP, lokalny) | Prościej: import z pliku JSON; endpoint wymaga dopisania domeny do `allowedDomains`/`devAllowedDomains` |
| MCP Figma | dostępny (whoami OK) — przydatny do odczytu i testowego pliku, ale nie zastępuje pluginu w PoC | Nie traktować wyniku MCP jako testu pluginu |
| Provider AI obrazów | brak kluczy; koszt per obraz zależny od dostawcy; 4 obrazy na żądanie = 4× koszt | Live test etapu 06 **BLOCKED** do czasu klucza i budżetu |
| LLM (brief, art direction, layout) | brak kluczy | Etapy 05/09 na mocku; live BLOCKED |
| Wyszukiwanie inspiracji | brak klucza API wyszukiwarki; scraping stron niewskazany (licencje, ToS) | Etap 07 na mocku; atrybucja tylko z metadanych źródła, bez deklaracji licencji |
| SSRF | pobieranie miniatur/URL-i klienta | `wp_safe_remote_get` + blokada prywatnych IP i walidacja redirectów |
| Sekrety w repo | śledzony prywatny klucz `key` (nie odczytany) | Nie używać; rozważyć usunięcie z historii po decyzji użytkownika |
| PHP 8.5 | bardzo nowy runtime; zależności (Acorn 5/Laravel 12) działają, ale mogą emitować deprecations | Obserwować logi przy testach |

## 6. Wymagane od użytkownika (przed odpowiednimi etapami)

- Obraz `references/clara-dashboard.png` (etap 02).
- Decyzja o providerach i budżecie AI + klucze w `wp-config.php` (etapy 05–07, 09).
- Figma: personal access token (serwer) i link do testowego pliku (etapy 04, 10).
- Potwierdzenie, czy `yarn build` przechodzi przy braku `sass`.

## 7. Wykonane sprawdzenia

- `php -l` na 44 plikach PHP motywu — 0 błędów.
- WP-CLI: `core version`, `theme list`, `plugin list`, `user list`, `cron test`, `acorn --version`, sprawdzenie tabel `clara_*` — wszystko odczytem.
- HTTP: `/wp-json/` 200, `/wp-json/clara/v1/` 404 (oczekiwane).
- Figma MCP `whoami` — zalogowany, plan Pro.
- Nie uruchamiano: `yarn build`, `yarn dev`, komend Acorn zmieniających stan, testów live AI (brak kluczy).
