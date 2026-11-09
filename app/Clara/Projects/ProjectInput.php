<?php

namespace App\Clara\Projects;

use App\Clara\Support\Validator;
use WP_Error;

/**
 * Walidacja danych projektu i briefu. `partial` = PATCH (tylko przesłane pola).
 */
class ProjectInput
{
	public const FIGMA_HOSTS = ['figma.com', 'www.figma.com'];

	private const FIGMA_PATHS = '#^/(design|file|proto|board)/[A-Za-z0-9]+#';

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function validate(array $params, bool $partial = false): array|WP_Error
	{
		$v = new Validator();
		$data = [];
		$has = fn (string $key) => ! $partial || array_key_exists($key, $params);

		if ($has('name')) {
			$data['name'] = $v->text('name', $params['name'] ?? '', 191, true);
		}

		foreach (['client_name', 'industry'] as $field) {
			if ($has($field)) {
				$data[$field] = $v->text($field, $params[$field] ?? '', 191);
			}
		}

		if ($has('website_url')) {
			$data['website_url'] = $v->url('website_url', $params['website_url'] ?? '');
		}

		if ($has('figma_url')) {
			$figma = $v->url('figma_url', $params['figma_url'] ?? '', self::FIGMA_HOSTS, true);
			if ($figma !== '' && ! preg_match(self::FIGMA_PATHS, (string) wp_parse_url($figma, PHP_URL_PATH))) {
				$v->fail('figma_url', 'Oczekiwano linku do pliku Figma (/design/…, /file/…, /proto/… lub /board/…).');
			}
			$data['figma_url'] = $figma;
		}

		if ($has('brief')) {
			$data['brief'] = self::brief($v, $params['brief'] ?? []);
		}

		return $v->ok() ? $data : $v->error();
	}

	/**
	 * Załączniki nie są przyjmowane z briefu — zarządza nimi wyłącznie endpoint uploadu.
	 */
	private static function brief(Validator $v, mixed $brief): array
	{
		if (! is_array($brief) || ($brief !== [] && array_is_list($brief))) {
			$v->fail('brief', 'Oczekiwano obiektu briefu.');
			return [];
		}

		return [
			'summary' => $v->text('brief.summary', $brief['summary'] ?? '', 4000, false, true),
			'audience' => $v->text('brief.audience', $brief['audience'] ?? '', 1000, false, true),
			'goals' => $v->textList('brief.goals', $brief['goals'] ?? []),
			'tone' => $v->textList('brief.tone', $brief['tone'] ?? []),
			'mustHave' => $v->textList('brief.mustHave', $brief['mustHave'] ?? []),
			'avoid' => $v->textList('brief.avoid', $brief['avoid'] ?? []),
		];
	}
}
