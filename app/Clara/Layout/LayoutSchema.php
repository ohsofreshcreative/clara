<?php

namespace App\Clara\Layout;

use WP_Error;

/**
 * Layout JSON v1 — kontrakt między analizą obrazu, podglądem HTML/CSS i pluginem Figmy.
 *
 * Współrzędne `frame` są bezwzględne w układzie płótna (px). Węzły tworzą drzewo przez `parentId`;
 * dokładnie jeden korzeń (frame na całe płótno). Tekst jest zawsze węzłem text/button z treścią —
 * nigdy częścią bitmapy. Fragmenty, których nie da się wiernie odtworzyć warstwami (zdjęcia,
 * złożone efekty), są węzłami image z `rasterized: true` i opisem w `rasterizedRegions`.
 * Rekonstrukcja jest best-effort (D02): `confidence` 0–1 mówi, na ile pewna jest pozycja/rola węzła.
 */
class LayoutSchema
{
	public const VERSION = 1;

	public const TYPES = ['frame', 'text', 'image', 'rectangle', 'button'];

	public const ROLES = ['root', 'background', 'overlay', 'visual', 'container', 'headline', 'subheadline', 'body', 'cta', 'decoration'];

	public const LAYOUT_MODES = ['none', 'horizontal', 'vertical'];

	public const TEXT_ALIGN = ['left', 'center', 'right'];

	public const MAX_BYTES = 512 * 1024;

	public const MAX_NODES = 200;

	public const MAX_DEPTH = 8;

	private const TOP_KEYS = ['schemaVersion', 'canvas', 'nodes', 'tokens', 'rasterizedRegions', 'warnings', 'source'];

	private const NODE_KEYS = ['id', 'type', 'role', 'name', 'parentId', 'order', 'frame', 'style', 'content', 'assetRef', 'layoutMode', 'confidence', 'rasterized'];

	private const STYLE_KEYS = ['fill', 'color', 'opacity', 'radius', 'fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textAlign', 'stroke', 'strokeWidth', 'padding', 'gap'];

	/**
	 * Bezpieczne parsowanie: limit rozmiaru i głębokości, tylko obiekt JSON.
	 */
	public static function parse(string $json): array|WP_Error
	{
		if (strlen($json) > self::MAX_BYTES) {
			return self::invalid(['layout' => sprintf('Layout JSON jest większy niż %d KB.', self::MAX_BYTES / 1024)]);
		}

		$data = json_decode($json, true, 32);

		if (! is_array($data) || array_is_list($data)) {
			return self::invalid(['layout' => 'Niepoprawny JSON — oczekiwano obiektu layoutu.']);
		}

		return $data;
	}

	/**
	 * Migracja do bieżącej wersji. v1 jest pierwszą wersją — przyszłe wersje dopiszą kroki tutaj;
	 * nieznane lub nowsze wersje są odrzucane czytelnym błędem zamiast zgadywania.
	 */
	public static function migrate(array $layout): array|WP_Error
	{
		$version = $layout['schemaVersion'] ?? null;

		if ($version === self::VERSION) {
			return $layout;
		}

		return self::invalid(['schemaVersion' => is_int($version) && $version > self::VERSION
			? "Wersja schematu {$version} jest nowsza niż obsługiwana (" . self::VERSION . ').'
			: 'Brak lub nieobsługiwana wersja schematu (oczekiwano ' . self::VERSION . ').']);
	}

	/**
	 * @param array{requiredTexts?: string[], assets?: int[]} $options
	 * @return array{errors: array<string, string>, warnings: string[], summary: array}
	 */
	public static function validate(mixed $layout, array $options = []): array
	{
		$e = [];
		$fail = function (string $path, string $message) use (&$e) {
			if (count($e) < 100 && ! isset($e[$path])) {
				$e[$path] = $message;
			}
		};

		if (! is_array($layout) || array_is_list($layout)) {
			return ['errors' => ['layout' => 'Oczekiwano obiektu layoutu.'], 'warnings' => [], 'summary' => []];
		}
		if (strlen((string) wp_json_encode($layout)) > self::MAX_BYTES) {
			$fail('layout', sprintf('Layout JSON jest większy niż %d KB.', self::MAX_BYTES / 1024));
		}
		foreach (array_diff(array_keys($layout), self::TOP_KEYS) as $key) {
			$fail((string) $key, 'Nieznane pole.');
		}
		if (($layout['schemaVersion'] ?? null) !== self::VERSION) {
			$fail('schemaVersion', 'Oczekiwano schemaVersion = ' . self::VERSION . '.');
		}

		$canvas = $layout['canvas'] ?? null;
		$cw = $ch = 0;
		if (! is_array($canvas)) {
			$fail('canvas', 'Wymagane płótno {width, height}.');
		} else {
			$cw = self::int($canvas['width'] ?? null, 320, 4096) ?? 0;
			$ch = self::int($canvas['height'] ?? null, 200, 4096) ?? 0;
			$cw ?: $fail('canvas.width', 'Szerokość płótna: liczba całkowita 320–4096.');
			$ch ?: $fail('canvas.height', 'Wysokość płótna: liczba całkowita 200–4096.');
			if (isset($canvas['background']) && ! self::color($canvas['background'])) {
				$fail('canvas.background', 'Kolor w formacie #rrggbb.');
			}
		}

		$nodes = $layout['nodes'] ?? null;
		if (! is_array($nodes) || ! array_is_list($nodes) || ! $nodes) {
			$fail('nodes', 'Wymagana niepusta lista węzłów.');
			$nodes = [];
		} elseif (count($nodes) > self::MAX_NODES) {
			$fail('nodes', sprintf('Maksymalnie %d węzłów.', self::MAX_NODES));
			$nodes = array_slice($nodes, 0, self::MAX_NODES);
		}

		$byId = [];
		foreach ($nodes as $i => $node) {
			$id = is_array($node) ? ($node['id'] ?? null) : null;
			if (is_string($id) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) {
				isset($byId[$id]) ? $fail("nodes.{$i}.id", "Powtórzony identyfikator „{$id}”.") : $byId[$id] = $node;
			}
		}

		$roots = 0;
		$texts = [];
		$rasterized = [];
		$confidence = [];
		$assets = array_map('intval', $options['assets'] ?? []);

		foreach ($nodes as $i => $node) {
			$p = "nodes.{$i}";
			if (! is_array($node) || array_is_list($node)) {
				$fail($p, 'Węzeł musi być obiektem.');
				continue;
			}
			foreach (array_diff(array_keys($node), self::NODE_KEYS) as $key) {
				$fail("{$p}.{$key}", 'Nieznane pole węzła.');
			}

			$id = $node['id'] ?? null;
			if (! is_string($id) || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) {
				$fail("{$p}.id", 'Identyfikator: małe litery, cyfry, - i _, do 64 znaków.');
			}
			$type = $node['type'] ?? null;
			if (! in_array($type, self::TYPES, true)) {
				$fail("{$p}.type", 'Typ: ' . implode(', ', self::TYPES) . '.');
				continue;
			}
			if (isset($node['role']) && ! in_array($node['role'], self::ROLES, true)) {
				$fail("{$p}.role", 'Rola: ' . implode(', ', self::ROLES) . '.');
			}
			if (isset($node['name']) && (! is_string($node['name']) || mb_strlen($node['name']) > 120)) {
				$fail("{$p}.name", 'Nazwa: tekst do 120 znaków.');
			}
			if (! is_int($node['order'] ?? null) || $node['order'] < 0) {
				$fail("{$p}.order", 'Kolejność: liczba całkowita ≥ 0.');
			}

			$parent = $node['parentId'] ?? null;
			if ($parent === null) {
				$roots++;
				if ($type !== 'frame') {
					$fail("{$p}.parentId", 'Korzeń layoutu musi być typu frame.');
				}
			} elseif (! is_string($parent) || ! isset($byId[$parent])) {
				$fail("{$p}.parentId", 'Rodzic nie istnieje.');
			} elseif (($byId[$parent]['type'] ?? '') !== 'frame') {
				$fail("{$p}.parentId", 'Rodzicem może być tylko frame.');
			} elseif (self::depth($id, $byId) === null) {
				$fail("{$p}.parentId", sprintf('Cykl w drzewie lub głębokość > %d.', self::MAX_DEPTH));
			}

			$f = $node['frame'] ?? null;
			$x = is_array($f) ? self::num($f['x'] ?? null) : null;
			$y = is_array($f) ? self::num($f['y'] ?? null) : null;
			$w = is_array($f) ? self::num($f['width'] ?? null) : null;
			$h = is_array($f) ? self::num($f['height'] ?? null) : null;
			if ($x === null || $y === null || $w === null || $h === null || $w <= 0 || $h <= 0) {
				$fail("{$p}.frame", 'Ramka {x, y, width, height} — liczby, wymiary > 0.');
			} elseif ($cw && $ch && ($x < -0.5 || $y < -0.5 || $x + $w > $cw + 0.5 || $y + $h > $ch + 0.5)) {
				$fail("{$p}.frame", 'Ramka wychodzi poza płótno.');
			} elseif ($parent === null && $cw && (abs($x) > 0.5 || abs($y) > 0.5 || abs($w - $cw) > 0.5 || abs($h - $ch) > 0.5)) {
				$fail("{$p}.frame", 'Korzeń musi pokrywać całe płótno.');
			}

			self::validateStyle($node['style'] ?? [], "{$p}.style", $type, $fail);

			$isText = in_array($type, ['text', 'button'], true);
			if ($isText) {
				$content = $node['content'] ?? null;
				if (! is_string($content) || trim($content) === '' || mb_strlen($content) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content)) {
					$fail("{$p}.content", 'Węzeł tekstowy wymaga treści (1–2000 znaków, bez znaków sterujących).');
				} else {
					$texts[] = $content;
				}
				if (! empty($node['rasterized'])) {
					$fail("{$p}.rasterized", 'Tekst musi pozostać tekstem — nie może być rasteryzowany.');
				}
			} elseif (array_key_exists('content', $node)) {
				$fail("{$p}.content", 'Treść mają tylko węzły text i button.');
			}

			if ($type === 'image') {
				$ref = $node['assetRef'] ?? null;
				$gen = is_array($ref) ? ($ref['generationId'] ?? null) : null;
				if (! is_int($gen) || $gen < 1) {
					$fail("{$p}.assetRef", 'Obraz wymaga assetRef.generationId.');
				} elseif ($assets && ! in_array($gen, $assets, true)) {
					$fail("{$p}.assetRef", 'Obraz spoza dozwolonych zasobów tej sekcji.');
				}
				$crop = is_array($ref) ? ($ref['crop'] ?? null) : null;
				if ($crop !== null && (! is_array($crop) || array_filter(['x', 'y', 'width', 'height'], fn ($k) => self::num($crop[$k] ?? null) === null || $crop[$k] < 0) || ($crop['width'] ?? 0) <= 0 || ($crop['height'] ?? 0) <= 0)) {
					$fail("{$p}.assetRef.crop", 'Kadr {x, y, width, height} — liczby ≥ 0, wymiary > 0.');
				}
			} elseif (array_key_exists('assetRef', $node)) {
				$fail("{$p}.assetRef", 'assetRef tylko dla węzłów image.');
			}

			$mode = $node['layoutMode'] ?? 'none';
			if (! in_array($mode, self::LAYOUT_MODES, true)) {
				$fail("{$p}.layoutMode", 'layoutMode: ' . implode(', ', self::LAYOUT_MODES) . '.');
			} elseif ($mode !== 'none' && $type !== 'frame') {
				$fail("{$p}.layoutMode", 'Auto Layout tylko dla frame.');
			}

			$c = self::num($node['confidence'] ?? null);
			if ($c === null || $c < 0 || $c > 1) {
				$fail("{$p}.confidence", 'confidence: liczba 0–1.');
			} else {
				$confidence[] = $c;
			}
			if (isset($node['rasterized']) && ! is_bool($node['rasterized'])) {
				$fail("{$p}.rasterized", 'rasterized: true/false.');
			} elseif (! empty($node['rasterized'])) {
				$rasterized[] = $id;
			}
		}

		if ($nodes && $roots !== 1) {
			$fail('nodes', 'Wymagany dokładnie jeden korzeń (parentId = null).');
		}

		$regions = $layout['rasterizedRegions'] ?? [];
		$covered = [];
		if (! is_array($regions) || ! array_is_list($regions)) {
			$fail('rasterizedRegions', 'Oczekiwano listy.');
		} else {
			foreach ($regions as $i => $r) {
				if (! is_array($r) || ! in_array($r['nodeId'] ?? null, $rasterized, true)) {
					$fail("rasterizedRegions.{$i}.nodeId", 'Region musi wskazywać węzeł z rasterized = true.');
					continue;
				}
				if (! is_string($r['reason'] ?? null) || trim($r['reason']) === '' || mb_strlen($r['reason']) > 300) {
					$fail("rasterizedRegions.{$i}.reason", 'Podaj powód rasteryzacji (do 300 znaków).');
				}
				$covered[] = $r['nodeId'];
			}
		}
		foreach (array_diff($rasterized, $covered) as $id) {
			$fail('rasterizedRegions', "Węzeł „{$id}” jest rasteryzowany bez opisu w rasterizedRegions.");
		}

		$tokens = $layout['tokens'] ?? [];
		if (! is_array($tokens) || array_diff(array_keys($tokens), ['colors', 'typography'])) {
			$fail('tokens', 'Tokeny: {colors, typography}.');
		} else {
			foreach (array_slice((array) ($tokens['colors'] ?? []), 0, 51) as $i => $t) {
				if ($i >= 50 || ! is_array($t) || ! is_string($t['name'] ?? null) || ! self::color($t['value'] ?? null)) {
					$fail("tokens.colors.{$i}", 'Kolor {name, value #rrggbb}, maks. 50.');
				}
			}
		}

		$warnings = $layout['warnings'] ?? [];
		if (! is_array($warnings) || ! array_is_list($warnings) || count($warnings) > 50 || array_filter($warnings, fn ($w) => ! is_string($w) || mb_strlen($w) > 300)) {
			$fail('warnings', 'Ostrzeżenia: lista do 50 tekstów po 300 znaków.');
			$warnings = [];
		}

		$normalized = array_map(fn ($t) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $t))), $texts);
		foreach (array_values($options['requiredTexts'] ?? []) as $i => $required) {
			if (! in_array(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $required))), $normalized, true)) {
				$fail("requiredTexts.{$i}", "Wymagana treść „{$required}” nie jest węzłem tekstowym layoutu.");
			}
		}

		return [
			'errors' => $e,
			'warnings' => $warnings,
			'summary' => [
				'nodes' => count($nodes),
				'textNodes' => count($texts),
				'rasterizedNodes' => count($rasterized),
				'editableNodes' => count($nodes) - count($rasterized),
				'avgConfidence' => $confidence ? round(array_sum($confidence) / count($confidence), 2) : null,
				'lowConfidence' => count(array_filter($confidence, fn ($c) => $c < 0.5)),
			],
		];
	}

	public static function invalid(array $errors): WP_Error
	{
		return new WP_Error('clara_layout_invalid', __('Layout JSON nie przeszedł walidacji.', 'clara'), ['status' => 400, 'errors' => $errors]);
	}

	private static function validateStyle(mixed $style, string $p, string $type, callable $fail): void
	{
		if (! is_array($style) || ($style && array_is_list($style))) {
			$fail($p, 'Styl musi być obiektem.');
			return;
		}
		foreach (array_diff(array_keys($style), self::STYLE_KEYS) as $key) {
			$fail("{$p}.{$key}", 'Nieznana właściwość stylu.');
		}
		foreach (['fill', 'color', 'stroke'] as $key) {
			if (isset($style[$key]) && ! self::color($style[$key])) {
				$fail("{$p}.{$key}", 'Kolor w formacie #rrggbb lub #rrggbbaa.');
			}
		}
		$ranges = ['opacity' => [0, 1], 'radius' => [0, 2000], 'fontSize' => [6, 400], 'lineHeight' => [0.5, 4], 'letterSpacing' => [-20, 100], 'strokeWidth' => [0, 100], 'padding' => [0, 1000], 'gap' => [0, 1000]];
		foreach ($ranges as $key => [$min, $max]) {
			if (isset($style[$key]) && (($n = self::num($style[$key])) === null || $n < $min || $n > $max)) {
				$fail("{$p}.{$key}", "Wartość {$min}–{$max}.");
			}
		}
		if (isset($style['fontWeight']) && (! is_int($style['fontWeight']) || $style['fontWeight'] < 100 || $style['fontWeight'] > 900 || $style['fontWeight'] % 100)) {
			$fail("{$p}.fontWeight", 'Grubość 100–900 co 100.');
		}
		if (isset($style['textAlign']) && ! in_array($style['textAlign'], self::TEXT_ALIGN, true)) {
			$fail("{$p}.textAlign", 'Wyrównanie: left, center, right.');
		}
		if (isset($style['fontFamily']) && (! is_string($style['fontFamily']) || ! preg_match('/^[\p{L}\p{N} ._\'-]{1,80}$/u', $style['fontFamily']))) {
			$fail("{$p}.fontFamily", 'Nazwa kroju: litery, cyfry, spacje, do 80 znaków.');
		}
		if (in_array($type, ['text', 'button'], true) && ! isset($style['fontSize'])) {
			$fail("{$p}.fontSize", 'Tekst wymaga rozmiaru fontu.');
		}
	}

	private static function depth(?string $id, array $byId): ?int
	{
		$seen = [];
		for ($depth = 0; $id !== null; $depth++) {
			if (isset($seen[$id]) || $depth > self::MAX_DEPTH || ! isset($byId[$id])) {
				return null;
			}
			$seen[$id] = true;
			$id = $byId[$id]['parentId'] ?? null;
		}

		return $depth;
	}

	private static function num(mixed $v): ?float
	{
		return (is_int($v) || is_float($v)) && is_finite((float) $v) ? (float) $v : null;
	}

	private static function int(mixed $v, int $min, int $max): ?int
	{
		return is_int($v) && $v >= $min && $v <= $max ? $v : null;
	}

	private static function color(mixed $v): bool
	{
		return is_string($v) && (bool) preg_match('/^#[0-9a-f]{6}([0-9a-f]{2})?$/i', $v);
	}
}
