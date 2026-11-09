# Clara PoC — lokalne uruchomienie

PoC działa wyłącznie lokalnie (LocalWP, macOS). Nie jest przeznaczony do deployu.

## 1. Wymagania

- LocalWP ze stroną `clara` (`http://clara.local`), PHP 8.2+, MySQL 8.
- Node ≥ 20 i yarn, Composer.
- Motyw `clara` aktywny (Sage 11 + Acorn 5).

## 2. Instalacja

```bash
cd app/public/wp-content/themes/clara
composer install
yarn install
yarn build              # front Clara + motyw → public/build (commitowane w repo)
yarn figma:build        # plugin Figmy → figma-plugin/dist/code.js (ignorowane w git)
```

WP-CLI w LocalWP wymaga socketu MySQL — najprościej przez helper testów:

```bash
source tests/clara/lib.sh
wp_cli clara migrate                       # tabele wp_clara_* (idempotentnie)
wp_cli clara status                        # wersja schematu i tabele
wp_cli clara seed-demo --owner=<login>     # opcjonalnie: jawny projekt demo H2Otwock + Hero
wp_cli rewrite flush                       # jednorazowo, dla adresu /clara
```

## 3. Użycie

1. Zaloguj się do WordPressa kontem z uprawnieniem `edit_posts` (filtr `clara/capability`).
2. **Szybka ścieżka:** na dashboardzie `http://clara.local` opisz sekcję po polsku (tryb „Generuj inspiracje”) → OpenAI (`gpt-image-2`) generuje 4 obrazy całej sekcji w 4 kompozycjach (podział, pełny kadr, typografia, kolaż) w jednym kliknięciu — każdy kafelek ładuje się osobno (postęp, licznik czasu) i pokazuje obraz, gdy jest gotowy. Przyciskiem „Obierz ten kierunek” zapisujesz wariant jako kierunek projektu; z opcją „Trzymaj się kierunku projektu” kolejne 4 warianty powstają w jego stylu (obraz kierunku idzie do OpenAI jako referencja, endpoint `images/edits`). Bez wybranego projektu powstaje nowy projekt; obrazy trafiają do galerii sekcji „Hero — inspiracje” (remix, Layout JSON, eksport do Figmy). Wymaga `CLARA_OPENAI_API_KEY` (szacunek ok. 0,06 USD za obraz, limit 12 generowań/h i dzienny budżet obrazów). Tryb „Szukaj zdjęć” tłumaczy polskie zapytanie na angielski (Claude, jeśli jest klucz).
3. Ścieżka szczegółowa: otwórz `http://clara.local` → projekt → brief → design system → sekcja Hero.
4. Art direction (4 kierunki) → „Generuj obrazy” (4 warianty w jednym żądaniu) → galeria: wybór, porównanie, remix.
5. Wybrany wariant → „Zbuduj Layout JSON” → „Eksport do Figmy”: pobierz paczkę albo wygeneruj kod jednorazowy.

Kolejka obrazów działa przez WP-Cron; gdy cron lokalnie nie odpala, uruchom worker ręcznie:

```bash
wp_cli clara worker --limit=20
```

## 4. Plugin Figmy

1. `yarn figma:build`.
2. Figma desktop → Plugins → Development → **Import plugin from manifest…** → `figma-plugin/manifest.json`.
3. Uruchom „Clara — import Hero”: wybierz plik paczki **albo** wpisz adres `http://clara.local` i kod jednorazowy (10 min, jedno użycie).
4. Plugin tworzy nowy frame obok istniejących warstw; „Zastąp poprzedni import…” usuwa tylko wcześniejszy import tego samego layoutu.

`devAllowedDomains` w manifeście dopuszcza wyłącznie `clara.local` (tryb deweloperski pluginu).

## 5. Konfiguracja providerów (opcjonalna)

Bez kluczy wszystko działa na adapterach **mock** (oznaczonych w UI). Klucze wyłącznie po stronie serwera — w `wp-config.php` lub zmiennych środowiska, **nigdy** w repo ani w JS:

| Stała / env | Zastosowanie |
|---|---|
| `CLARA_ANTHROPIC_API_KEY` | LLM art direction + analiza wizyjna layoutu (gdy brak — fallback deterministyczny / heurystyka) |
| `CLARA_LLM_MODEL` | opcjonalny model LLM art direction / analizy (domyślnie `claude-opus-5-5`) |
| `CLARA_OPENAI_API_KEY` | generowanie obrazów (dashboard i sekcje projektu); bez klucza — mock |
| `CLARA_OPENAI_IMAGE_MODEL` | model obrazów OpenAI (domyślnie `gpt-image-2`) |
| `CLARA_FIGMA_TOKEN` | import design systemu z Figma REST (bez tokenu — import JSON) |
| `CLARA_SEARCH_PROVIDER` lub opcja `clara_search_provider` | `openverse` (domyślnie) lub `mock` |

Provider obrazów: `OpenAiImageProvider`, gdy ustawiono `CLARA_OPENAI_API_KEY`, w przeciwnym razie `MockImageProvider`; nadpisanie filtrem `clara/image_provider`.

## 6. Testy (w tle, bez otwierania aplikacji)

```bash
bash tests/clara/run.sh                    # PHP + REST + E2E H2Otwock + plugin (mock); CLARA_LIVE=1 dodaje Openverse live
CLARA_PLAYWRIGHT=<node_modules z playwright> CLARA_REFERENCE=<clara-dashboard.png> CLARA_OUT=<katalog> \
  bash tests/clara/visual.sh               # UI headless 1440/390, kontrast, focus, overflow
yarn figma:typecheck
```

Testy tworzą tymczasowych użytkowników i dane, a po sobie je usuwają. Na czas testów `run.sh` i `visual.sh` ustawiają opcję `clara_test_offline` (klucze z `wp-config.php` są ignorowane — zero kosztów, mocki) i usuwają ją przy wyjściu.
