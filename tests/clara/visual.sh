#!/usr/bin/env bash
# Test wizualny i dostępności dashboardu Clara (Playwright headless, bez okna przeglądarki).
# Buduje assety do katalogu tymczasowego (public/build pozostaje nietknięty) i podstawia je
# w prawdziwej stronie /clara z tymczasową sesją administratora.
#   CLARA_PLAYWRIGHT — katalog node_modules z pakietem playwright (domyślnie: rozwiązanie przez node)
#   CLARA_REFERENCE  — mockup do porównania (opcjonalnie)
#   CLARA_OUT        — katalog na zrzuty (domyślnie: katalog tymczasowy)
set -uo pipefail

source "$(dirname "$0")/lib.sh"

TMP="$(mktemp -d)"
OUT="${CLARA_OUT:-$TMP/out}"
mkdir -p "$OUT"
# Wyszukiwarka na czas testu: mock (bez ruchu do zewnętrznego API); przywracana po teście.
wp_cli option update clara_search_provider mock >/dev/null
wp_cli option update clara_test_offline 1 >/dev/null
trap 'wp_cli option delete clara_search_provider >/dev/null; wp_cli option delete clara_test_offline >/dev/null; session_end; rm -rf "$TMP/build" "$TMP/session"' EXIT

(cd "$THEME_DIR" && npx vite build --outDir "$TMP/build" --emptyOutDir --logLevel error) || { echo 'FAIL build'; exit 1; }

session_start "$TMP/session"

CLARA_URL="$URL" CLARA_BUILD="$TMP/build" CLARA_OUT="$OUT" \
  CLARA_COOKIE_NAME="$COOKIE_NAME" CLARA_COOKIE_VAL="$COOKIE_VAL" \
  node "$THEME_DIR/tests/clara/visual.mjs"
status=$?

echo "Zrzuty: $OUT"
exit $status
