<?php

namespace App\Clara\Layout;

use App\Clara\Creative\Generations;
use App\Clara\Projects\Projects;
use App\Clara\Projects\Sections;
use WP_Error;

/**
 * Paczka dla pluginu Figmy: Layout JSON + osadzone obrazy (base64). Bez sekretów, adresów
 * e-mail, identyfikatorów użytkowników i adresów mediów WordPressa.
 *
 * Transfer: (1) plik pobrany z Clara i wczytany w pluginie albo (2) jednorazowy kod ważny
 * 10 minut. Kod jest losowy (256 bitów), w bazie zapisujemy tylko jego skrót HMAC; plugin wysyła
 * go w treści żądania (nie w URL), więc nie trafia do logów serwera ani historii.
 */
class FigmaExport
{
	public const FORMAT = 'clara-figma-bundle';

	public const VERSION = 1;

	public const TOKEN_TTL = 10 * MINUTE_IN_SECONDS;

	public const MAX_BUNDLE_BYTES = 15 * 1024 * 1024;

	private const TOKENS_PER_WINDOW = 10;

	public static function bundle(array $doc, array $generation): array|WP_Error
	{
		$meta = json_decode((string) $generation['prompt_json'], true) ?: [];
		$assets = [];

		foreach (array_unique(array_filter(array_map(fn ($n) => $n['assetRef']['generationId'] ?? null, $doc['layout']['nodes'] ?? []))) as $genId) {
			$source = (int) $genId === (int) $generation['id'] ? $generation : Generations::find((int) $genId, LayoutInterpreter::ASSET_KINDS);
			if (! $source || (int) $source['section_id'] !== (int) $generation['section_id'] || ! $source['result_asset_id']) {
				return new WP_Error('clara_export_asset', __('Obraz użyty w layoucie jest niedostępny.', 'clara'), ['status' => 409]);
			}
			$path = (string) get_attached_file((int) $source['result_asset_id']);
			$size = $path && is_readable($path) ? @getimagesize($path) : false;
			if (! $size || ! in_array($size['mime'] ?? '', ['image/png', 'image/jpeg'], true)) {
				// Figma przyjmuje PNG, JPEG i GIF — inne formaty nie są osadzane.
				return new WP_Error('clara_export_asset', __('Obraz musi być w formacie PNG lub JPEG.', 'clara'), ['status' => 409]);
			}
			$assets[(string) $genId] = ['mime' => $size['mime'], 'width' => $size[0], 'height' => $size[1], 'data' => base64_encode((string) file_get_contents($path))];
		}

		$bundle = [
			'format' => self::FORMAT,
			'version' => self::VERSION,
			'layoutId' => (int) $doc['id'],
			'generationId' => (int) $generation['id'],
			'title' => ($meta['exportTitle'] ?? '') ?: sprintf('Clara Hero — wariant %s · v%d', $meta['directionKey'] ?? '?', (int) ($meta['version'] ?? 1)),
			'mock' => (bool) ($meta['mock'] ?? false),
			'createdAt' => $doc['created_at'],
			'layout' => $doc['layout'],
			'assets' => $assets,
		];

		if (strlen((string) wp_json_encode($bundle)) > self::MAX_BUNDLE_BYTES) {
			return new WP_Error('clara_export_size', __('Paczka dla Figmy przekracza 15 MB.', 'clara'), ['status' => 413]);
		}

		return $bundle;
	}

	public static function createToken(int $layoutId, int $userId): array|WP_Error
	{
		$window = 'clara_figma_tok_rl_' . $userId;
		$count = (int) get_transient($window);
		if ($count >= self::TOKENS_PER_WINDOW) {
			return new WP_Error('clara_export_rate', sprintf(__('Limit %d kodów na 10 minut — użyj wygenerowanego wcześniej.', 'clara'), self::TOKENS_PER_WINDOW), ['status' => 429]);
		}
		set_transient($window, $count + 1, self::TOKEN_TTL);

		$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		set_transient(self::key($token), ['layoutId' => $layoutId, 'userId' => $userId], self::TOKEN_TTL);

		return ['token' => $token, 'expiresAt' => gmdate('c', time() + self::TOKEN_TTL), 'ttl' => self::TOKEN_TTL, 'endpoint' => rest_url('clara/v1/figma/bundle')];
	}

	/**
	 * Jednorazowe użycie kodu: wpis jest usuwany przed zwróceniem paczki. Uprawnienia sprawdzamy
	 * ponownie (właściciel projektu nadal istnieje i ma dostęp do Clara).
	 */
	public static function redeem(string $token): array|WP_Error
	{
		$expired = new WP_Error('clara_export_token', __('Kod jest nieprawidłowy, wygasł lub został już użyty — wygeneruj nowy w Clara.', 'clara'), ['status' => 401]);

		if (! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
			return $expired;
		}

		$entry = get_transient(self::key($token));
		if (! is_array($entry)) {
			return $expired;
		}
		delete_transient(self::key($token));

		$doc = LayoutDocuments::find((int) $entry['layoutId']);
		$generation = $doc ? Generations::find((int) $doc['generation_id'], LayoutDocuments::OWNER_KINDS) : null;
		$section = $generation ? Sections::find((int) $generation['section_id']) : null;
		$project = $section ? Projects::find($section['project_id']) : null;
		$user = get_userdata((int) $entry['userId']);

		if (! $project || ! $user || (int) $project['owner_user_id'] !== (int) $entry['userId'] || ! \App\Clara\Access::canUse((int) $entry['userId'])) {
			return $expired;
		}

		return self::bundle($doc, $generation);
	}

	private static function key(string $token): string
	{
		return 'clara_figma_tok_' . hash_hmac('sha256', $token, wp_salt('auth'));
	}
}
