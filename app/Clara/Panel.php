<?php

namespace App\Clara;

use App\Clara\Database\Schema;
use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * Panel aplikacji na stronie głównej (/) i podstronach panelu (/projects, /settings…);
 * routing podstron obsługuje React. Stare adresy /clara/* przekierowują 301 na nowe.
 */
class Panel
{
	public const QUERY_VAR = 'clara_app';

	/** Pierwsze segmenty adresów obsługiwane przez panel (= trasy z resources/js/clara/router.ts). */
	public const ROUTES = ['projects', 'collections', 'library', 'settings'];

	public const LEGACY_VAR = 'clara_legacy';

	public const LEGACY_PATH = 'clara';

	public const ENTRIES = ['resources/css/clara.css', 'resources/js/clara/main.tsx'];

	private const REWRITE_VERSION = '2';

	private const REWRITE_OPTION = 'clara_rewrite_version';

	public function register(): void
	{
		add_action('init', [$this, 'addRewrite']);
		add_filter('query_vars', fn (array $vars) => [...$vars, self::QUERY_VAR, self::LEGACY_VAR]);
		add_action('template_redirect', [$this, 'render'], 1);
	}

	public function addRewrite(): void
	{
		add_rewrite_rule('^(?:' . implode('|', self::ROUTES) . ')(?:/.*)?/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
		add_rewrite_rule('^' . self::LEGACY_PATH . '(?:/(.*?))?/?$', 'index.php?' . self::LEGACY_VAR . '=/$matches[1]', 'top');

		// Jednorazowe odświeżenie reguł po zmianie wersji trasy; bez hard flush (.htaccess).
		if (get_option(self::REWRITE_OPTION) !== self::REWRITE_VERSION) {
			flush_rewrite_rules(false);
			update_option(self::REWRITE_OPTION, self::REWRITE_VERSION, false);
		}
	}

	public static function url(string $path = ''): string
	{
		return home_url('/' . ltrim($path, '/'));
	}

	public function render(): void
	{
		if ($legacy = get_query_var(self::LEGACY_VAR)) {
			wp_safe_redirect(self::url(sanitize_text_field((string) $legacy)), 301);
			exit;
		}

		// Strona główna = dashboard; wyszukiwanie, kanały i stronicowanie zostają przy WordPressie.
		$home = (is_front_page() || is_home()) && ! is_search() && ! is_feed() && ! is_paged() && self::requestedPath() === '';
		if (! get_query_var(self::QUERY_VAR) && ! $home) {
			return;
		}

		nocache_headers();

		// Nie auth_redirect(): ono waliduje ciasteczko wp-admin, którego przeglądarka nie wysyła poza /wp-admin.
		if (! is_user_logged_in()) {
			wp_safe_redirect(wp_login_url(self::url(self::requestedPath())));
			exit;
		}

		if (! Access::canUse()) {
			wp_die(esc_html__('Brak uprawnień do Clara.', 'clara'), 403);
		}

		Schema::maybeMigrate();

		show_admin_bar(false);
		status_header(200);

		$preferences = Preferences::get(get_current_user_id());

		echo view('clara.app', [
			'assets' => $this->assetTags(),
			'preferences' => $preferences,
			'scripts' => ['wp-element', 'wp-api-fetch'],
			'config' => [
				'basePath' => wp_parse_url(self::url(), PHP_URL_PATH),
				'restNamespace' => 'clara/v1',
				'userName' => wp_get_current_user()->display_name,
				'preferences' => $preferences,
			],
		])->render();

		exit;
	}

	private static function requestedPath(): string
	{
		$path = (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
		$prefix = (string) wp_parse_url(self::url(), PHP_URL_PATH);

		return str_starts_with($path, $prefix) ? trim(sanitize_text_field(substr($path, strlen($prefix))), '/') : '';
	}

	/**
	 * Brak wpisu w manifeście (przed `yarn build`) nie może wywrócić panelu.
	 */
	private function assetTags(): ?string
	{
		try {
			return (string) Vite::withEntryPoints(self::ENTRIES)->toHtml();
		} catch (Throwable) {
			return null;
		}
	}
}
