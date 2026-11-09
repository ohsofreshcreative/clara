<?php

namespace App\Clara\DesignSystems;

use WP_Error;

/**
 * Figma REST — tylko to, co działa na planie bez Enterprise: style pliku (FILL, TEXT, EFFECT)
 * z `GET /v1/files/:key?depth=1` i wartości z `GET /v1/files/:key/nodes`.
 * Zmienne (Variables API) wymagają Enterprise — dla nich jest import JSON (W3C tokens).
 *
 * Token wyłącznie po stronie serwera: stała CLARA_FIGMA_TOKEN lub zmienna środowiskowa.
 * Host stały (api.figma.com), więc URL od użytkownika służy tylko do wyciągnięcia klucza pliku.
 */
class FigmaClient
{
	private const API = 'https://api.figma.com/v1/';

	private const MAX_STYLES = 200;

	public static function token(): string
	{
		$token = defined('CLARA_FIGMA_TOKEN') ? (string) CLARA_FIGMA_TOKEN : (string) getenv('CLARA_FIGMA_TOKEN');
		if (get_option('clara_test_offline')) {
			$token = '';
		}

		return (string) apply_filters('clara/figma_token', $token);
	}

	public static function configured(): bool
	{
		return self::token() !== '';
	}

	public static function fileKey(string $url): ?string
	{
		$path = (string) wp_parse_url($url, PHP_URL_PATH);

		return preg_match('#^/(?:design|file|proto|board)/([A-Za-z0-9]{10,64})#', $path, $m) ? $m[1] : null;
	}

	/**
	 * @return array{tokens: array, meta: array, warnings: string[]}|WP_Error
	 */
	public static function ingest(string $fileKey): array|WP_Error
	{
		$file = self::get('files/' . rawurlencode($fileKey) . '?depth=1');

		if (is_wp_error($file)) {
			return $file;
		}

		$styles = is_array($file['styles'] ?? null) ? $file['styles'] : [];
		$warnings = [];

		if (count($styles) > self::MAX_STYLES) {
			$warnings[] = sprintf('Plik ma %d stylów — pobrano pierwsze %d.', count($styles), self::MAX_STYLES);
			$styles = array_slice($styles, 0, self::MAX_STYLES, true);
		}

		$tokens = array_fill_keys(TokenSchema::GROUPS, []);

		if ($styles !== []) {
			$nodes = self::get('files/' . rawurlencode($fileKey) . '/nodes?ids=' . rawurlencode(implode(',', array_keys($styles))));

			if (is_wp_error($nodes)) {
				return $nodes;
			}

			foreach ($styles as $nodeId => $style) {
				$node = $nodes['nodes'][$nodeId]['document'] ?? null;
				$name = (string) ($style['name'] ?? $nodeId);

				if (! is_array($node)) {
					$warnings[] = "{$name}: brak węzła stylu w odpowiedzi, pominięto.";
					continue;
				}

				self::mapStyle((string) ($style['styleType'] ?? ''), $name, $node, $tokens, $warnings);
			}
		} else {
			$warnings[] = 'Plik nie ma lokalnych stylów. Zmienne Figmy nie są dostępne przez REST poza planem Enterprise — użyj importu JSON.';
		}

		return [
			'tokens' => $tokens,
			'warnings' => $warnings,
			'meta' => [
				'fileKey' => $fileKey,
				'fileName' => sanitize_text_field((string) ($file['name'] ?? '')),
				'lastModified' => sanitize_text_field((string) ($file['lastModified'] ?? '')),
				'figmaVersion' => sanitize_text_field((string) ($file['version'] ?? '')),
				'stylesFound' => count($styles),
				'endpoints' => ['GET /v1/files/:key?depth=1', 'GET /v1/files/:key/nodes'],
			],
		];
	}

	private static function mapStyle(string $type, string $name, array $node, array &$tokens, array &$warnings): void
	{
		switch ($type) {
			case 'FILL':
				$paint = $node['fills'][0] ?? null;
				if (($paint['type'] ?? '') !== 'SOLID') {
					$warnings[] = "{$name}: wypełnienie inne niż jednolity kolor (np. gradient/obraz), pominięto.";
					return;
				}
				$tokens['colors'][] = ['name' => $name, 'value' => self::hex($paint['color'] ?? [], (float) ($paint['opacity'] ?? 1))];
				return;

			case 'TEXT':
				$s = $node['style'] ?? [];
				$tokens['typography'][] = [
					'name' => $name,
					'fontFamily' => (string) ($s['fontFamily'] ?? ''),
					'fontSize' => $s['fontSize'] ?? null,
					'fontWeight' => $s['fontWeight'] ?? 400,
					'lineHeight' => $s['lineHeightPx'] ?? null,
				];
				return;

			case 'EFFECT':
				$shadows = array_filter($node['effects'] ?? [], fn ($e) => in_array($e['type'] ?? '', ['DROP_SHADOW', 'INNER_SHADOW'], true) && ($e['visible'] ?? true));
				if (! $shadows) {
					$warnings[] = "{$name}: efekt bez cienia (np. blur), pominięto.";
					return;
				}
				$tokens['shadows'][] = ['name' => $name, 'value' => implode(', ', array_map(fn ($e) => trim(
					($e['type'] === 'INNER_SHADOW' ? 'inset ' : '') .
					round((float) ($e['offset']['x'] ?? 0), 2) . 'px ' . round((float) ($e['offset']['y'] ?? 0), 2) . 'px ' .
					round((float) ($e['radius'] ?? 0), 2) . 'px ' . round((float) ($e['spread'] ?? 0), 2) . 'px ' .
					self::hex($e['color'] ?? [], 1)
				), $shadows))];
				return;

			default:
				$warnings[] = "{$name}: styl typu „{$type}” (np. siatka) nieobsługiwany, pominięto.";
		}
	}

	private static function hex(array $c, float $opacity): string
	{
		$ch = fn ($v) => str_pad(dechex((int) round(max(0, min(1, (float) $v)) * 255)), 2, '0', STR_PAD_LEFT);
		$alpha = (float) ($c['a'] ?? 1) * $opacity;

		return '#' . $ch($c['r'] ?? 0) . $ch($c['g'] ?? 0) . $ch($c['b'] ?? 0) . ($alpha < 1 ? $ch($alpha) : '');
	}

	/**
	 * Błędy tłumaczone na czytelne komunikaty; treść odpowiedzi Figmy i token nie trafiają do klienta.
	 */
	private static function get(string $path): array|WP_Error
	{
		if (! self::configured()) {
			return new WP_Error('clara_figma_unconfigured', __('Integracja z Figmą nie jest skonfigurowana na serwerze (brak CLARA_FIGMA_TOKEN). Użyj importu JSON.', 'clara'), ['status' => 409]);
		}

		$response = wp_remote_get(self::API . $path, [
			'timeout' => 20,
			'redirection' => 0,
			'headers' => ['X-Figma-Token' => self::token(), 'Accept' => 'application/json'],
		]);

		if (is_wp_error($response)) {
			return new WP_Error('clara_figma_network', __('Nie udało się połączyć z Figmą. Spróbuj ponownie później.', 'clara'), ['status' => 502]);
		}

		$code = (int) wp_remote_retrieve_response_code($response);

		if ($code === 429) {
			$retry = (int) wp_remote_retrieve_header($response, 'retry-after');
			return new WP_Error('clara_figma_rate_limited', sprintf(__('Figma ogranicza liczbę zapytań. Spróbuj ponownie za %d s.', 'clara'), max(1, $retry)), ['status' => 429, 'retryAfter' => $retry]);
		}

		$messages = [
			400 => 'Figma odrzuciła zapytanie — sprawdź link do pliku.',
			401 => 'Token Figmy jest nieprawidłowy lub wygasł.',
			403 => 'Token Figmy nie ma dostępu do tego pliku (wymagany zakres file_content:read).',
			404 => 'Nie znaleziono pliku Figma — sprawdź link i uprawnienia.',
		];

		if ($code !== 200) {
			return new WP_Error('clara_figma_http', __($messages[$code] ?? "Figma zwróciła błąd HTTP {$code}.", 'clara'), ['status' => 502, 'figmaStatus' => $code]);
		}

		$data = json_decode((string) wp_remote_retrieve_body($response), true);

		if (! is_array($data)) {
			return new WP_Error('clara_figma_invalid', __('Figma zwróciła nieczytelną odpowiedź.', 'clara'), ['status' => 502]);
		}

		return $data;
	}
}
