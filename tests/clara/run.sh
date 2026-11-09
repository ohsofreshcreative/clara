#!/usr/bin/env bash
# Testy fundamentu Clara: integracyjne PHP (wp eval-file) + HTTP (curl).
# Wymaga uruchomionej strony w LocalWP.
set -uo pipefail

source "$(dirname "$0")/lib.sh"

fail=0
pass=0

# Klucze z wp-config są na czas testów wyłączone (mocki, zero kosztów); przywracane przy wyjściu.
wp_cli option update clara_test_offline 1 >/dev/null
trap 'wp_cli option delete clara_test_offline >/dev/null' EXIT
check() {
  if [ "$2" = "$3" ]; then
    pass=$((pass + 1)); echo "PASS $1"
  else
    fail=$((fail + 1)); echo "FAIL $1 — oczekiwano $3, jest $2"
  fi
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo '== PHP: migracje, REST, uprawnienia =='
wp_cli eval-file "$THEME_DIR/tests/clara/smoke.php" || fail=$((fail + 1))

echo '== PHP: projekty, sekcje, załączniki, uprawnienia =='
wp_cli eval-file "$THEME_DIR/tests/clara/projects.php" || fail=$((fail + 1))

echo '== PHP: design system, import JSON, Figma (mock HTTP) =='
wp_cli eval-file "$THEME_DIR/tests/clara/design-systems.php" || fail=$((fail + 1))

echo '== PHP: art direction (LLM mock, fallback) =='
wp_cli eval-file "$THEME_DIR/tests/clara/art-directions.php" || fail=$((fail + 1))

echo '== PHP: generowanie obrazów (kolejka, mock) =='
wp_cli eval-file "$THEME_DIR/tests/clara/images.php" || fail=$((fail + 1))

echo '== PHP: galeria, wybór i remix =='
wp_cli eval-file "$THEME_DIR/tests/clara/gallery.php" || fail=$((fail + 1))

echo '== PHP: Visual-to-Layout JSON (heurystyka + wizja na mocku HTTP) =='
wp_cli eval-file "$THEME_DIR/tests/clara/layouts.php" || fail=$((fail + 1))

echo '== PHP: inspiracje i kolekcje (mock + Openverse na mocku HTTP) =='
wp_cli eval-file "$THEME_DIR/tests/clara/inspirations.php" || fail=$((fail + 1))

echo '== PHP: preferencje wyglądu (akcent, skala UI) =='
wp_cli eval-file "$THEME_DIR/tests/clara/preferences.php" || fail=$((fail + 1))
wp_cli eval-file "$THEME_DIR/tests/clara/generate.php" || fail=$((fail + 1))

echo '== PHP: eksport do Figmy (paczka, kod jednorazowy, CORS) =='
FIGMA_FIXTURE="$(mktemp -t clara-figma-bundle)"
CLARA_FIGMA_FIXTURE="$FIGMA_FIXTURE" wp_cli eval-file "$THEME_DIR/tests/clara/figma.php" || fail=$((fail + 1))

echo '== Plugin Figmy: typy + importer na atrapie Plugin API (to nie jest test live) =='
(cd "$THEME_DIR" && npx tsc --noEmit -p figma-plugin/tsconfig.json) || fail=$((fail + 1))
CLARA_FIGMA_FIXTURE="$FIGMA_FIXTURE" node --test "$THEME_DIR/tests/clara/figma-plugin.test.ts" || fail=$((fail + 1))
rm -f "$FIGMA_FIXTURE"

echo '== E2E H2Otwock: brief → 4 wizualizacje → remix → layout → paczka Figmy + uprawnienia wszystkich tras (mock) =='
E2E_FIXTURE="$(mktemp -t clara-e2e-bundle)"
CLARA_E2E_FIXTURE="$E2E_FIXTURE" wp_cli eval-file "$THEME_DIR/tests/clara/e2e-h2otwock.php" || fail=$((fail + 1))
echo '== E2E H2Otwock: paczka remiksu przez importer pluginu (atrapa Plugin API) =='
CLARA_FIGMA_FIXTURE="$E2E_FIXTURE" node --test "$THEME_DIR/tests/clara/figma-plugin.test.ts" || fail=$((fail + 1))
rm -f "$E2E_FIXTURE"

if [ "${CLARA_LIVE:-0}" = '1' ]; then
  echo '== LIVE: Openverse =='
  wp_cli eval-file "$THEME_DIR/tests/clara/inspirations-live.php" || fail=$((fail + 1))
fi

echo '== HTTP: niezalogowany =='
check 'panel na stronie głównej przekierowuje do logowania (302)' "$(code "$URL/")" 302
location="$(curl -s -o /dev/null -w '%{redirect_url}' "$URL/projects")"
case "$location" in *wp-login.php*redirect_to=*projects*) r=ok ;; *) r="$location" ;; esac
check 'przekierowanie zachowuje adres podstrony' "$r" ok
check 'stary adres /clara/ → 301 na stronę główną' "$(code "$URL/clara/")|$(curl -s -o /dev/null -w '%{redirect_url}' "$URL/clara/")" "301|$URL/"
check 'stary adres /clara/projects/12 → 301 na /projects/12' "$(curl -s -o /dev/null -w '%{redirect_url}' "$URL/clara/projects/12")" "$URL/projects/12"
check 'REST /clara/v1/status odrzuca (401)' "$(code "$URL/wp-json/clara/v1/status")" 401

echo '== HTTP: zalogowany (tymczasowa sesja testowa) =='
TMP="$(mktemp -d)"
trap 'session_end; rm -rf "$TMP"; wp_cli option delete clara_test_offline >/dev/null' EXIT
session_start "$TMP/session"
JAR=(-H "Cookie: $COOKIE_NAME=$COOKIE_VAL")

check 'panel na stronie głównej dostępny po zalogowaniu (200)' "$(code "${JAR[@]}" "$URL/")" 200
check 'podstrona /projects obsłużona przez panel (200)' "$(code "${JAR[@]}" "$URL/projects")" 200
check 'podstrona /settings obsłużona przez panel (200)' "$(code "${JAR[@]}" "$URL/settings")" 200
grep -q 'id="clara-root"' <(curl -s "${JAR[@]}" "$URL/") && r=ok || r=brak
check 'panel renderuje punkt montowania React' "$r" ok
check 'REST z ciasteczkiem i nonce (200)' "$(code "${JAR[@]}" -H "X-WP-Nonce: $NONCE" "$URL/wp-json/clara/v1/status")" 200
check 'REST z ciasteczkiem bez nonce traktowany jak niezalogowany (401)' "$(code "${JAR[@]}" "$URL/wp-json/clara/v1/status")" 401
check 'REST z błędnym nonce (403)' "$(code "${JAR[@]}" -H 'X-WP-Nonce: deadbeef00' "$URL/wp-json/clara/v1/status")" 403

echo '== HTTP: projekt i upload przez serwer =='
API=(-s "${JAR[@]}" -H "X-WP-Nonce: $NONCE")
created="$(curl "${API[@]}" -H 'Content-Type: application/json' -d '{"name":"HTTP test","website_url":"https://example.com"}' "$URL/wp-json/clara/v1/projects")"
PID="$(printf '%s' "$created" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["id"] ?? "";')"
[ -n "$PID" ] && r=ok || r="$created"
check 'POST /projects przez HTTP (201)' "$r" ok
printf '\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\xdac\xf8\xcf\xc0\xf0\x1f\0\x05\0\x01\xff\x89\x99=\x1d\0\0\0\0IEND\xaeB`\x82' > "$TMP/ok.png"
printf 'nie obrazek' > "$TMP/fake.png"
up() { code "${API[@]}" -H 'Content-Type: application/octet-stream' -H "Content-Disposition: attachment; filename=\"$2\"" --data-binary "@$1" "$URL/wp-json/clara/v1/projects/$PID/attachments"; }
check 'upload PNG przez HTTP (201)' "$(up "$TMP/ok.png" ok.png)" 201
check 'upload fałszywego PNG przez HTTP (415)' "$(up "$TMP/fake.png" fake.png)" 415
check 'zapis i odczyt po ponownym żądaniu (200)' "$(code "${API[@]}" "$URL/wp-json/clara/v1/projects/$PID")" 200
check 'DELETE /projects/{id} przez HTTP (200)' "$(code "${API[@]}" -X DELETE "$URL/wp-json/clara/v1/projects/$PID")" 200

session_end

echo '== HTTP: regresja =='
check 'logowanie WordPress dostępne (200)' "$(code "$URL/wp-login.php")" 200
grep -q 'clara-login' <(curl -s "$URL/wp-login.php") && r=ok || r=brak
check 'ekran logowania w stylu Clara (klasa body, postać)' "$r" ok
check 'indeks REST WordPress (200)' "$(code "$URL/wp-json/")" 200

echo "HTTP: $pass PASS, $fail FAIL"
[ "$fail" -eq 0 ]
