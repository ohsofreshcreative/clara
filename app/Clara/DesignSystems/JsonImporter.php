<?php

namespace App\Clara\DesignSystems;

use WP_Error;

/**
 * Fallback importu tokenów (D06): plik JSON w formacie Clara v1 albo W3C Design Tokens
 * (DTCG: `$type`/`$value`, eksportowany np. przez pluginy Figmy dla zmiennych).
 * Zwraca tokeny przed walidacją schematu + ostrzeżenia o pominiętych pozycjach.
 */
class JsonImporter
{
	public const MAX_BYTES = 1048576;

	private const MAX_DEPTH = 12;

	/**
	 * @return array{format: string, tokens: array, warnings: string[]}|WP_Error
	 */
	public static function parse(string $content): array|WP_Error
	{
		if (trim($content) === '') {
			return self::error('Plik jest pusty.');
		}

		if (strlen($content) > self::MAX_BYTES) {
			return self::error('Plik JSON jest za duży (limit 1 MB).', 413);
		}

		$data = json_decode($content, true, 64);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return self::error('Niepoprawny JSON: ' . json_last_error_msg() . '. Sprawdź przecinki, cudzysłowy i nawiasy.');
		}

		if (! is_array($data) || array_is_list($data)) {
			return self::error('Oczekiwano obiektu JSON z tokenami, a nie listy ani pojedynczej wartości.');
		}

		if (array_intersect(array_keys($data), TokenSchema::GROUPS)) {
			return ['format' => 'clara-v1', 'tokens' => $data, 'warnings' => []];
		}

		$warnings = [];
		$flat = [];
		self::flatten($data, [], $flat, $warnings, 0);

		if ($flat === []) {
			return self::error('Nie rozpoznano formatu. Oczekiwano tokenów Clara (colors, typography, spacing, radii, shadows) lub W3C Design Tokens ($type/$value).');
		}

		return ['format' => 'w3c-dtcg', 'tokens' => self::fromDtcg($flat, $warnings), 'warnings' => $warnings];
	}

	private static function error(string $message, int $status = 400): WP_Error
	{
		return new WP_Error('clara_import_invalid', $message, ['status' => $status]);
	}

	/**
	 * Spłaszcza drzewo DTCG do ścieżka => [type, value]; `$type` dziedziczony z grup.
	 */
	private static function flatten(array $node, array $path, array &$flat, array &$warnings, int $depth, ?string $type = null): void
	{
		if ($depth > self::MAX_DEPTH) {
			$warnings[] = implode('.', $path) . ': zbyt głęboko zagnieżdżone, pominięto.';
			return;
		}

		$type = isset($node['$type']) && is_string($node['$type']) ? $node['$type'] : $type;

		if (array_key_exists('$value', $node)) {
			$flat[implode('.', $path)] = ['type' => $type, 'value' => $node['$value']];
			return;
		}

		foreach ($node as $key => $child) {
			if (is_string($key) && str_starts_with($key, '$')) {
				continue;
			}
			if (is_array($child)) {
				self::flatten($child, [...$path, (string) $key], $flat, $warnings, $depth + 1, $type);
			}
		}
	}

	private static function resolve(mixed $value, array $flat, int $depth = 0): mixed
	{
		if (is_string($value) && preg_match('/^\{([^{}]+)\}$/', $value, $m)) {
			if ($depth > 8 || ! isset($flat[$m[1]])) {
				return null;
			}
			return self::resolve($flat[$m[1]]['value'], $flat, $depth + 1);
		}

		return $value;
	}

	private static function fromDtcg(array $flat, array &$warnings): array
	{
		$tokens = array_fill_keys(TokenSchema::GROUPS, []);

		foreach ($flat as $path => $token) {
			$value = self::resolve($token['value'], $flat);

			if ($value === null) {
				$warnings[] = "{$path}: nierozwiązany alias, pominięto.";
				continue;
			}

			switch ($token['type']) {
				case 'color':
					// DTCG 2025: kolor może być obiektem {colorSpace, components, hex}.
					$tokens['colors'][] = ['name' => $path, 'value' => is_array($value) ? ($value['hex'] ?? null) : $value];
					break;

				case 'dimension':
				case 'number':
					$group = preg_match('/radius|radii|rounded|corner/i', $path) ? 'radii' : 'spacing';
					$tokens[$group][] = ['name' => $path, 'value' => self::dimension($value)];
					break;

				case 'typography':
					if (! is_array($value)) {
						$warnings[] = "{$path}: typografia musi być obiektem, pominięto.";
						break;
					}
					$family = self::resolve($value['fontFamily'] ?? '', $flat);
					$tokens['typography'][] = [
						'name' => $path,
						'fontFamily' => is_array($family) ? (string) ($family[0] ?? '') : $family,
						'fontSize' => self::dimension(self::resolve($value['fontSize'] ?? null, $flat)),
						'fontWeight' => self::resolve($value['fontWeight'] ?? 400, $flat),
						'lineHeight' => self::lineHeight(self::resolve($value['lineHeight'] ?? null, $flat), self::dimension(self::resolve($value['fontSize'] ?? null, $flat))),
					];
					break;

				case 'shadow':
					$tokens['shadows'][] = ['name' => $path, 'value' => self::shadow($value, $flat)];
					break;

				default:
					$warnings[] = "{$path}: typ „" . ($token['type'] ?? 'brak') . '” nieobsługiwany, pominięto.';
			}
		}

		return $tokens;
	}

	private static function dimension(mixed $value): mixed
	{
		if (is_array($value)) {
			$unit = $value['unit'] ?? 'px';
			return $unit === 'rem' ? (float) ($value['value'] ?? 0) * 16 : ($value['value'] ?? null);
		}

		if (is_string($value) && preg_match('/^([\d.]+)(px|rem)$/', trim($value), $m)) {
			return $m[2] === 'rem' ? (float) $m[1] * 16 : (float) $m[1];
		}

		return $value;
	}

	/**
	 * DTCG lineHeight bywa mnożnikiem (1.4) — przeliczamy na px względem rozmiaru fontu.
	 */
	private static function lineHeight(mixed $value, mixed $fontSize): mixed
	{
		$value = self::dimension($value);

		if (is_numeric($value) && (float) $value <= 4 && is_numeric($fontSize)) {
			return round((float) $value * (float) $fontSize, 2);
		}

		return $value;
	}

	private static function shadow(mixed $value, array $flat): mixed
	{
		$layers = is_array($value) && array_is_list($value) ? $value : [$value];
		$css = [];

		foreach ($layers as $layer) {
			if (is_string($layer)) {
				$css[] = $layer;
				continue;
			}
			if (! is_array($layer)) {
				return null;
			}
			$px = fn ($k) => (string) (self::dimension(self::resolve($layer[$k] ?? 0, $flat)) ?? 0) . (is_numeric(self::dimension(self::resolve($layer[$k] ?? 0, $flat))) ? 'px' : '');
			$css[] = trim(($layer['inset'] ?? false ? 'inset ' : '') . "{$px('offsetX')} {$px('offsetY')} {$px('blur')} {$px('spread')} " . self::resolve($layer['color'] ?? '#000000', $flat));
		}

		return implode(', ', $css);
	}
}
