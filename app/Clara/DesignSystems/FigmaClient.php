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

	/** Podglądy głównych frame'ów (podstron) jako referencje stylu dla generatora inspiracji. */
	public const MAX_FRAMES = 4;

	private const MIN_FRAME_WIDTH = 600;

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

	/** `node-id=17304-77` z linku Figmy → `17304:77`. */
	public static function nodeId(string $url): ?string
	{
		parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
		$id = str_replace('-', ':', (string) ($query['node-id'] ?? ''));

		return preg_match('/^\d+:\d+$/', $id) ? $id : null;
	}

	/** Role kolorów rozpoznawane po podpisie lub nazwie warstwy (odcienie / „shades” pomijamy). */
	public const COLOR_ROLES = ['primary' => 'Primary', 'secondary' => 'Secondary', 'third' => 'Third', 'tertiary' => 'Third'];

	/**
	 * Kolory primary / secondary / third z ramki palety: warstwa z jednolitym wypełnieniem, której nazwa
	 * albo bezpośredni podpis tekstowy to rola (np. prostokąt „Primary” z napisem „primary”).
	 *
	 * @return array{colors: array<int, array{name: string, value: string}>, meta: array}|WP_Error
	 */
	public static function colors(string $fileKey, string $nodeId): array|WP_Error
	{
		$data = self::get('files/' . rawurlencode($fileKey) . '/nodes?ids=' . rawurlencode($nodeId));
		if (is_wp_error($data)) {
			return $data;
		}
		$root = $data['nodes'][$nodeId]['document'] ?? null;
		if (! is_array($root)) {
			return new WP_Error('clara_figma_node', __('Nie znaleziono wskazanej ramki w pliku Figma — sprawdź link (node-id).', 'clara'), ['status' => 404]);
		}

		$found = [];
		self::collectColors($root, $found);
		$colors = [];
		foreach (array_unique(array_values(self::COLOR_ROLES)) as $role) {
			if (isset($found[$role])) {
				$colors[] = ['name' => $role, 'value' => $found[$role]];
			}
		}

		return [
			'colors' => $colors,
			'meta' => ['fileKey' => $fileKey, 'nodeId' => $nodeId, 'nodeName' => sanitize_text_field((string) ($root['name'] ?? '')), 'fileName' => sanitize_text_field((string) ($data['name'] ?? ''))],
		];
	}

	/**
	 * Typografia z ramki: style faktycznie użyte we wzorcach tekstu. Font adnotacji (najczęstszy krój —
	 * etykiety tabel, tytuł ramki) i próbki nazw krojów są pomijane. Nagłówki H1…H6 wg rozmiaru, długie
	 * akapity jako Body.
	 *
	 * @return array{typography: array<int, array>, meta: array}|WP_Error
	 */
	public static function typography(string $fileKey, string $nodeId): array|WP_Error
	{
		$data = self::get('files/' . rawurlencode($fileKey) . '/nodes?ids=' . rawurlencode($nodeId));
		if (is_wp_error($data)) {
			return $data;
		}
		$root = $data['nodes'][$nodeId]['document'] ?? null;
		if (! is_array($root)) {
			return new WP_Error('clara_figma_node', __('Nie znaleziono wskazanej ramki w pliku Figma — sprawdź link (node-id).', 'clara'), ['status' => 404]);
		}

		$texts = [];
		$collect = function (array $node) use (&$collect, &$texts) {
			if (($node['type'] ?? '') === 'TEXT' && ! empty($node['style']['fontFamily']) && ($node['visible'] ?? true)) {
				$texts[] = $node;
			}
			foreach ($node['children'] ?? [] as $child) {
				is_array($child) && $collect($child);
			}
		};
		$collect($root);

		$families = array_count_values(array_map(fn ($t) => (string) $t['style']['fontFamily'], $texts));
		arsort($families);
		$annotation = count($families) > 1 ? (string) array_key_first($families) : null;

		$styles = [];
		foreach ($texts as $t) {
			$s = $t['style'];
			$text = trim((string) ($t['characters'] ?? ''));
			if ($s['fontFamily'] === $annotation || mb_strtolower($text) === mb_strtolower((string) $s['fontFamily'])) {
				continue;
			}
			$key = implode('|', [$s['fontFamily'], $s['fontWeight'] ?? 400, $s['fontSize'] ?? 0, round((float) ($s['lineHeightPx'] ?? 0))]);
			$styles[$key] ??= [
				'label' => mb_strtolower(trim((string) ($t['name'] ?? ''))),
				'body' => mb_strlen($text) > 80,
				'fontFamily' => (string) $s['fontFamily'],
				'fontSize' => isset($s['fontSize']) ? round((float) $s['fontSize'], 2) : null,
				'fontWeight' => (int) ($s['fontWeight'] ?? 400),
				'lineHeight' => isset($s['lineHeightPx']) ? round((float) $s['lineHeightPx'], 2) : null,
			];
		}
		usort($styles, fn ($a, $b) => [$a['body'], -$a['fontSize']] <=> [$b['body'], -$b['fontSize']]);

		$typography = [];
		$heading = 0;
		$body = 0;
		foreach ($styles as $style) {
			if (preg_match('/^(h[1-6]|body|caption|small|lead)\b/', $style['label'], $m)) {
				$name = ucfirst($m[1]);
			} elseif ($style['body']) {
				$name = ['Body', 'Body small', 'Caption'][$body++] ?? 'Body ' . $body;
			} else {
				$name = ++$heading <= 6 ? "H{$heading}" : "Text {$heading}";
			}
			$typography[] = ['name' => $name] + array_diff_key($style, ['label' => 1, 'body' => 1]);
		}

		return [
			'typography' => array_slice($typography, 0, 12),
			'meta' => ['fileKey' => $fileKey, 'nodeId' => $nodeId, 'nodeName' => sanitize_text_field((string) ($root['name'] ?? '')), 'fileName' => sanitize_text_field((string) ($data['name'] ?? '')), 'annotationFont' => $annotation],
		];
	}

	private static function collectColors(array $node, array &$found): void
	{
		$fill = null;
		foreach ($node['fills'] ?? [] as $paint) {
			if (($paint['type'] ?? '') === 'SOLID' && ($paint['visible'] ?? true)) {
				$fill = self::hex($paint['color'] ?? [], (float) ($paint['opacity'] ?? 1));
			}
		}
		if ($fill && ($node['type'] ?? '') !== 'TEXT') {
			$labels = [mb_strtolower(trim((string) ($node['name'] ?? '')))];
			foreach ($node['children'] ?? [] as $child) {
				if (($child['type'] ?? '') === 'TEXT') {
					$labels[] = mb_strtolower(trim((string) ($child['characters'] ?? $child['name'] ?? '')));
				}
			}
			foreach ($labels as $label) {
				$role = self::COLOR_ROLES[$label] ?? null;
				if ($role && ! isset($found[$role])) {
					$found[$role] = $fill;
					break;
				}
			}
		}
		foreach ($node['children'] ?? [] as $child) {
			if (is_array($child)) {
				self::collectColors($child, $found);
			}
		}
	}

	/**
	 * @return array{tokens: array, meta: array, warnings: string[]}|WP_Error
	 */
	public static function ingest(string $fileKey): array|WP_Error
	{
		// depth=2: strony + ich frame'y najwyższego poziomu (bez całego drzewa).
		$file = self::get('files/' . rawurlencode($fileKey) . '?depth=2');

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

		$frames = self::frames($fileKey, $file, $warnings);

		return [
			'tokens' => $tokens,
			'frames' => $frames,
			'warnings' => $warnings,
			'meta' => [
				'fileKey' => $fileKey,
				'fileName' => sanitize_text_field((string) ($file['name'] ?? '')),
				'lastModified' => sanitize_text_field((string) ($file['lastModified'] ?? '')),
				'figmaVersion' => sanitize_text_field((string) ($file['version'] ?? '')),
				'stylesFound' => count($styles),
				'framesFound' => count($frames),
				'endpoints' => ['GET /v1/files/:key?depth=2', 'GET /v1/files/:key/nodes', 'GET /v1/images/:key'],
			],
		];
	}

	/**
	 * Renderuje do MAX_FRAMES szerokich frame'ów (JPG, skala 0.5) i pobiera je. Błąd podglądów nie przerywa
	 * importu stylów — trafia do ostrzeżeń.
	 *
	 * @return array<int, array{name: string, body: string}>
	 */
	private static function frames(string $fileKey, array $file, array &$warnings): array
	{
		$picked = [];
		foreach ($file['document']['children'] ?? [] as $page) {
			foreach ($page['children'] ?? [] as $node) {
				$width = (float) ($node['absoluteBoundingBox']['width'] ?? 0);
				if (in_array($node['type'] ?? '', ['FRAME', 'COMPONENT', 'SECTION'], true) && $width >= self::MIN_FRAME_WIDTH && ($node['visible'] ?? true)) {
					$picked[(string) $node['id']] = sanitize_text_field((string) ($node['name'] ?? 'Frame'));
				}
				if (count($picked) >= self::MAX_FRAMES) {
					break 2;
				}
			}
		}
		if (! $picked) {
			return [];
		}

		$images = self::get('images/' . rawurlencode($fileKey) . '?ids=' . rawurlencode(implode(',', array_keys($picked))) . '&format=jpg&scale=0.5');
		if (is_wp_error($images)) {
			$warnings[] = 'Nie udało się pobrać podglądów frame’ów: ' . $images->get_error_message();
			return [];
		}

		$frames = [];
		foreach ($picked as $id => $name) {
			$url = (string) ($images['images'][$id] ?? '');
			// Figma zwraca tymczasowe linki do własnego magazynu (https) — pobieramy bez tokenu.
			$response = $url !== '' && str_starts_with($url, 'https://') ? wp_safe_remote_get($url, ['timeout' => 20]) : null;
			$body = $response && ! is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200 ? (string) wp_remote_retrieve_body($response) : '';
			if ($body === '') {
				$warnings[] = "{$name}: nie udało się pobrać podglądu frame’a.";
				continue;
			}
			$frames[] = ['name' => $name, 'body' => $body];
		}

		return $frames;
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
