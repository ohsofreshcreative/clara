<?php

namespace App\Clara\DesignSystems;

use App\Clara\Support\Validator;
use WP_Error;

/**
 * Format tokenów Clara (v1) — znormalizowany profil wizualny niezależny od źródła:
 * colors[name,value#hex], typography[name,fontFamily,fontSize,fontWeight,lineHeight],
 * spacing[name,value], radii[name,value], shadows[name,value].
 */
class TokenSchema
{
	public const GROUPS = ['colors', 'typography', 'spacing', 'radii', 'shadows'];

	private const MAX_ITEMS = 200;

	/**
	 * @return array<string, array>|WP_Error
	 */
	public static function validate(mixed $tokens): array|WP_Error
	{
		$v = new Validator();

		if (! is_array($tokens) || ($tokens !== [] && array_is_list($tokens))) {
			$v->fail('tokens', 'Oczekiwano obiektu z grupami: ' . implode(', ', self::GROUPS) . '.');
			return $v->error();
		}

		$unknown = array_diff(array_keys($tokens), self::GROUPS);
		if ($unknown) {
			$v->fail('tokens', 'Nieznane grupy tokenów: ' . implode(', ', $unknown) . '.');
		}

		$out = [];

		foreach (self::GROUPS as $group) {
			$items = $tokens[$group] ?? [];

			if (! is_array($items) || ($items !== [] && ! array_is_list($items))) {
				$v->fail($group, 'Oczekiwano listy tokenów.');
				$out[$group] = [];
				continue;
			}

			if (count($items) > self::MAX_ITEMS) {
				$v->fail($group, 'Maksymalnie ' . self::MAX_ITEMS . ' tokenów w grupie.');
				$out[$group] = [];
				continue;
			}

			$names = [];
			$out[$group] = [];

			foreach ($items as $i => $item) {
				$path = "{$group}[{$i}]";

				if (! is_array($item)) {
					$v->fail($path, 'Oczekiwano obiektu tokenu.');
					continue;
				}

				$name = $v->text("{$path}.name", $item['name'] ?? '', 100, true);

				if ($name !== '' && isset($names[strtolower($name)])) {
					$v->fail("{$path}.name", "Zduplikowana nazwa „{$name}”.");
				}
				$names[strtolower($name)] = true;

				$out[$group][] = ['name' => $name] + self::item($v, $group, $path, $item);
			}
		}

		return $v->ok() ? $out : $v->error();
	}

	private static function item(Validator $v, string $group, string $path, array $item): array
	{
		return match ($group) {
			'colors' => ['value' => self::color($v, "{$path}.value", $item['value'] ?? null)],
			'typography' => [
				'fontFamily' => $v->text("{$path}.fontFamily", $item['fontFamily'] ?? '', 100, true),
				'fontSize' => self::number($v, "{$path}.fontSize", $item['fontSize'] ?? null, 1, 400, true),
				'fontWeight' => (int) self::number($v, "{$path}.fontWeight", $item['fontWeight'] ?? 400, 100, 1000, true),
				'lineHeight' => self::number($v, "{$path}.lineHeight", $item['lineHeight'] ?? null, 0, 800, false),
			],
			'spacing', 'radii' => ['value' => self::number($v, "{$path}.value", $item['value'] ?? null, 0, 2000, true)],
			'shadows' => ['value' => self::shadow($v, "{$path}.value", $item['value'] ?? null)],
		};
	}

	public static function color(Validator $v, string $path, mixed $value): string
	{
		$hex = is_string($value) ? strtolower(trim($value)) : '';

		if (preg_match('/^#([0-9a-f]{3})$/', $hex, $m)) {
			$hex = '#' . preg_replace('/(.)/', '$1$1', $m[1]);
		}

		if (! preg_match('/^#[0-9a-f]{6}([0-9a-f]{2})?$/', $hex)) {
			$v->fail($path, sprintf('Niepoprawny kolor „%s” — oczekiwano #RRGGBB lub #RRGGBBAA.', is_scalar($value) ? (string) $value : gettype($value)));
			return '';
		}

		return $hex;
	}

	private static function number(Validator $v, string $path, mixed $value, float $min, float $max, bool $required): ?float
	{
		if ($value === null || $value === '') {
			if ($required) {
				$v->fail($path, 'Wartość jest wymagana.');
			}
			return null;
		}

		if (is_string($value) && preg_match('/^(-?\d+(?:\.\d+)?)px$/', trim($value), $m)) {
			$value = $m[1];
		}

		if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
			$v->fail($path, sprintf('Oczekiwano liczby od %s do %s (px).', $min, $max));
			return null;
		}

		return round((float) $value, 2);
	}

	private static function shadow(Validator $v, string $path, mixed $value): string
	{
		$text = is_string($value) ? trim($value) : '';

		// Tylko składnia box-shadow: liczby/px, kolory hex/rgba, przecinki — bez url(), ; itp.
		if ($text === '' || strlen($text) > 300 || ! preg_match('/^[#0-9a-zA-Z.,()%\s-]+$/', $text) || preg_match('/url|expression|;/i', $text)) {
			$v->fail($path, 'Niepoprawny cień — oczekiwano wartości CSS box-shadow, np. „0 4px 12px #00000033”.');
			return '';
		}

		return $text;
	}
}
