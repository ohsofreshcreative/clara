<?php

namespace App\Clara;

use WP_Error;

/**
 * Preferencje wyglądu panelu (per użytkownik, user meta): akcent kolorystyczny, skala UI i efekty (szkło, tło WebGL).
 * Renderowane na <html> po stronie serwera, więc nie ma mignięcia domyślnego wyglądu.
 */
class Preferences
{
	public const META = 'clara_preferences';

	/** Klucze muszą odpowiadać wariantom `[data-accent]` w resources/css/clara.css. */
	public const ACCENTS = ['blue', 'violet', 'pink', 'teal', 'sunset'];

	/** Skala w procentach rozmiaru bazowego (rem). */
	public const SCALES = [75, 80, 90, 100, 110, 125];

	/** full = szkło + animowane tło WebGL; reduced = jednolite powierzchnie bez animacji. */
	public const EFFECTS = ['full', 'reduced'];

	public const DEFAULTS = ['accent' => 'blue', 'scale' => 100, 'effects' => 'full'];

	public static function get(int $userId): array
	{
		$stored = get_user_meta($userId, self::META, true);
		$stored = is_array($stored) ? $stored : [];

		return [
			'accent' => in_array($stored['accent'] ?? null, self::ACCENTS, true) ? $stored['accent'] : self::DEFAULTS['accent'],
			'scale' => in_array($stored['scale'] ?? null, self::SCALES, true) ? $stored['scale'] : self::DEFAULTS['scale'],
			'effects' => in_array($stored['effects'] ?? null, self::EFFECTS, true) ? $stored['effects'] : self::DEFAULTS['effects'],
		];
	}

	public static function update(int $userId, array $data): array|WP_Error
	{
		$errors = [];
		$next = self::get($userId);

		if (array_key_exists('accent', $data)) {
			in_array($data['accent'], self::ACCENTS, true) ? $next['accent'] = $data['accent'] : $errors['accent'] = __('Nieznany wariant akcentu.', 'clara');
		}

		if (array_key_exists('scale', $data)) {
			$scale = is_numeric($data['scale']) ? (int) $data['scale'] : null;
			in_array($scale, self::SCALES, true) ? $next['scale'] = $scale : $errors['scale'] = __('Nieobsługiwana skala interfejsu.', 'clara');
		}

		if (array_key_exists('effects', $data)) {
			in_array($data['effects'], self::EFFECTS, true) ? $next['effects'] = $data['effects'] : $errors['effects'] = __('Nieznany tryb efektów.', 'clara');
		}

		if ($errors) {
			return new WP_Error('clara_invalid', __('Nieprawidłowe ustawienia.', 'clara'), ['status' => 400, 'errors' => $errors]);
		}

		update_user_meta($userId, self::META, $next);

		return $next;
	}
}
