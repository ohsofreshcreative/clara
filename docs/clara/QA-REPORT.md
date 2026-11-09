# Clara PoC — raport QA i odbioru

Data: 2026-11-07. Środowisko: LocalWP `clara.local`, PHP 8.5, MySQL 8.4, WP 7.1.4, Node 26. Brak deployu, commitów i pushy.
Testy uruchamiane w tle (WP-CLI, node --test, Playwright headless). Instrukcja uruchomienia: `LOCAL-SETUP.md`.

## 1. Wyniki testów (rzeczywiste przebiegi)

| Zestaw | Wynik |
|---|---|
| Smoke: migracje, REST, uprawnienia | 16 / 16 |
| Projekty, sekcje, załączniki | 58 / 58 |
| Design system, import JSON, Figma REST (mock HTTP) | 56 / 56 |
| Art direction (LLM mock + fallback) | 54 / 54 |
| Generowanie obrazów (kolejka, mock) | 42 / 42 |
| Galeria, wybór, remix | 48 / 48 |
| Visual-to-Layout JSON | 67 / 67 |
| Inspiracje i kolekcje | 47 / 47 |
| Eksport Figmy (paczka, kod, CORS) | 24 / 24 |
| Plugin Figmy — typy + importer na atrapie API (2 paczki) | tsc OK, 23 / 23 ×2 |
| **E2E H2Otwock** (`tests/clara/e2e-h2otwock.php`) | **23 / 23** |
| HTTP (prawdziwe żądania, sesja testowa) | 16 / 16 |
| UI headless 1440 / 390 (`visual.sh`) | 125 / 125 |

## 2. Przebieg E2E H2Otwock (mock providerów)

Brief → DS v1 → sekcja Hero → 4 kierunki (4 różne kompozycje, wymagane treści zachowane) → 4 obrazy z jednego żądania (4 różne pliki, koszt 0 oznaczony jako mock, powiązanie z przebiegiem art direction, DS v1 i promptem z treścią briefu) → wybór B → remix = v2 z `parent_id`, oryginał i plik bez zmian, lineage oryginał → remix → Layout JSON v1 (walidacja OK, teksty i CTA jako tekst, fotografia jako region rasteryzowany; walidator odrzuca błędne dane) → paczka Figmy z obrazem remiksu → kod jednorazowy wymieniony bez sesji, drugie użycie 401 → ta sama paczka przechodzi przez importer pluginu (atrapa API).

Uprawnienia: automatyczny przegląd **wszystkich** tras `clara/v1` — niezalogowany 401, subskrybent 403 (jedyny wyjątek z założenia: `POST /figma/bundle`, autoryzowany kodem); inny redaktor dostaje 404 na 17 zasobach właściciela i nie widzi projektu na liście; nic nie zostało zmienione.

## 3. Kryteria globalne

| Kryterium | Status | Dowód / uwagi |
|---|---|---|
| Projekt i brief H2Otwock, zapis i odczyt po odświeżeniu | PASS | E2E krok 1, UI (projekt po przeładowaniu) |
| 4 koncepcje o odmiennych kompozycjach | PASS (mock) | 4 różne `composition.layout`. Ocena manualna (zrzut galerii): A „Klarowny podział” — tekst w lewej kolumnie, wizual na prawej połowie; B „Pełny kadr” — obraz na całym tle, tekst w centralnej karcie; C „Typografia na pierwszym planie” — wyśrodkowany duży nagłówek, mały akcent wizualny pod spodem; D „Kolaż doświadczeń” — tekst po lewej, asymetryczny kolaż 3 kadrów. Obrazy mock pokazują tylko schemat kompozycji i paletę, nie jakość kreatywną AI |
| Powiązanie generacji z briefem, DS i promptem | PASS | E2E krok 2 |
| Wyszukiwanie: źródła, atrybucja, puste wyniki | PASS | mock + **Openverse live 6/6** (etap 8) |
| Remix bez nadpisania oryginału | PASS | E2E krok 3, galeria 48/48 |
| Hero → testowa Figma jako edytowalne teksty, ramki, obrazy | PASS z zastrzeżeniem | Live przez MCP w pliku „Clara — test importu Hero”: frame, 3 teksty, 2× Auto Layout, bitmapa z kadrem, 3 zmienne. Obraz dostarczono `upload_assets` (bajty identyczne ze źródłem) po błędzie ręcznego przenoszenia base64 — `createImage` z bajtów paczki pokryty tylko atrapą. Plugin w GUI Figma desktop: **BLOCKED** (zakaz automatyzacji GUI) — do ręcznego smoke |
| Mock nieprzedstawiany jako live | PASS | UI i raporty oznaczają mock; testy live raportowane osobno |
| Dostęp tylko dla uprawnionych | PASS | E2E krok 6 (wszystkie trasy) |
| Dashboard zgodny kierunkowo z mockupem, 1440 / 390, brak overflow | PASS | `visual.sh`: porównanie obok siebie, overflow 0, kontrast AA, focus; bez fikcyjnych danych z mockupu |
| Żaden sekret w kliencie, logach, repo | PASS | skan repo (299 plików, bez `key`/vendor/build): tylko celowo fałszywy token testowy; brak odwołań do kluczy w JS/pluginie; odpowiedzi REST w E2E bez soli, kluczy, e-maili; kody Figmy tylko jako HMAC |
| Brak destrukcyjnych zmian WP i deployu | PASS | tabele `wp_clara_*` dodane migracją; dane testowe sprzątane; brak deployu |

## 4. Blokady (jawne)

| Obszar | Status |
|---|---|
| Generowanie obrazów przez prawdziwy model AI | **BLOCKED** — brak klucza i budżetu; w PoC istnieje tylko interfejs `ImageProvider` + `MockImageProvider` (adapter realnego providera nie został zaimplementowany ani przetestowany) |
| LLM art direction live (Anthropic) | **BLOCKED** — brak `CLARA_ANTHROPIC_API_KEY`; adapter przetestowany na mocku HTTP, fallback deterministyczny działa |
| Analiza wizyjna layoutu live | **BLOCKED** — j.w.; działa heurystyka (oznaczona w UI) |
| Import DS z Figma REST live | **BLOCKED** — brak `CLARA_FIGMA_TOKEN`; testy na mocku HTTP, import JSON działa |
| Plugin w aplikacji Figma desktop | **BLOCKED** dla automatyzacji — do ręcznego sprawdzenia wg `LOCAL-SETUP.md` §4 |
| Koszty realnych providerów | nieweryfikowalne bez live; koszt zapisywany (mock = 0, częściowe partie liczone z udanych) |

## 5. Ograniczenia PoC i ryzyka

- Rekonstrukcja Figmy jest best-effort: pozycje tekstów z heurystyki są przybliżone, zdjęcia i efekty zostają bitmapami; nie pixel-perfect.
- Fonty spoza Figmy są zastępowane (Regular / Inter) z raportem w pluginie.
- Endpoint `/figma/bundle` jest publiczny z założenia (CORS `*` bez poświadczeń); chroni go kod 256-bit, TTL 10 min, jedno użycie, limit IP. Limit IP liczony po `REMOTE_ADDR` — za proxy wymagałby korekty.
- Kolejka obrazów zależy od WP-Cron lub ręcznego `wp clara worker`; brak niezawodnego workera produkcyjnego (poza zakresem).
- Wyszukiwarka Openverse: licencje tylko deklarowane przez źródło; brak weryfikacji praw.
- `yarn build` nie był uruchamiany przez agenta (artefakty w `public/build` trzeba odświeżyć i zacommitować samodzielnie); testy UI budują front do katalogu tymczasowego.
- Dokumentacja projektu (MASTER, ARCHITECTURE itd.) znajduje się w Christie, nie w repo.
- Poza zakresem: SaaS, zespoły, pełne podstrony, eksport do WordPressa, deploy (Hostinger).
