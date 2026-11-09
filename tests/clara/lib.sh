# Wspólne funkcje testów Clara (source z run.sh / visual.sh).
# Nadpisanie środowiska: CLARA_URL, CLARA_WP_PATH, CLARA_LOCAL_SITE_ID

THEME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
URL="${CLARA_URL:-http://clara.local}"
WP_PATH="${CLARA_WP_PATH:-$(cd "$THEME_DIR/../../.." && pwd)}"
SITE_ID="${CLARA_LOCAL_SITE_ID:-XzmkTfHcR}"
SOCKET="$HOME/Library/Application Support/Local/run/$SITE_ID/mysql/mysqld.sock"

wp_cli() {
  php -d "mysqli.default_socket=$SOCKET" "$(command -v wp)" --path="$WP_PATH" "$@"
}

# Tworzy krótką (10 min) sesję administratora. Ciasteczko i nonce trafiają tylko do pliku $1.
# Ustawia: COOKIE_NAME, COOKIE_VAL, NONCE, SESSION_USER, SESSION_TOKEN.
session_start() {
  wp_cli eval '
$u = get_users(["role" => "administrator", "number" => 1])[0];
$exp = time() + 600;
$token = WP_Session_Tokens::get_instance($u->ID)->create($exp);
$cookie = wp_generate_auth_cookie($u->ID, $exp, "logged_in", $token);
$_COOKIE[LOGGED_IN_COOKIE] = $cookie;
wp_set_current_user($u->ID);
echo LOGGED_IN_COOKIE, "\n", $cookie, "\n", wp_create_nonce("wp_rest"), "\n", $u->ID, "\n", $token, "\n";
' > "$1" 2>/dev/null
  COOKIE_NAME="$(sed -n 1p "$1")"
  COOKIE_VAL="$(sed -n 2p "$1")"
  NONCE="$(sed -n 3p "$1")"
  SESSION_USER="$(sed -n 4p "$1")"
  SESSION_TOKEN="$(sed -n 5p "$1")"
}

session_end() {
  [ -n "${SESSION_TOKEN:-}" ] && wp_cli eval "WP_Session_Tokens::get_instance((int) '$SESSION_USER')->destroy('$SESSION_TOKEN');" >/dev/null 2>&1
  SESSION_TOKEN=''
}
