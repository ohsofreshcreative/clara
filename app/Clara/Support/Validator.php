<?php

namespace App\Clara\Support;

use WP_Error;

/**
 * Zbiera błędy pól i zwraca jeden WP_Error 400 z mapą `errors` (pole => komunikat).
 */
class Validator
{
	/** @var array<string, string> */
	private array $errors = [];

	public function fail(string $field, string $message): void
	{
		$this->errors[$field] ??= $message;
	}

	public function ok(): bool
	{
		return $this->errors === [];
	}

	public function error(): WP_Error
	{
		return new WP_Error('clara_invalid', __('Niepoprawne dane.', 'clara'), ['status' => 400, 'errors' => $this->errors]);
	}

	public function text(string $field, mixed $value, int $max, bool $required = false, bool $multiline = false): string
	{
		if (! is_scalar($value ?? '')) {
			$this->fail($field, 'Oczekiwano tekstu.');
			return '';
		}

		$text = trim($multiline ? sanitize_textarea_field((string) $value) : sanitize_text_field((string) $value));

		if ($required && $text === '') {
			$this->fail($field, 'Pole jest wymagane.');
		} elseif (mb_strlen($text) > $max) {
			$this->fail($field, "Maksymalnie {$max} znaków.");
		}

		return $text;
	}

	/**
	 * @return string[]
	 */
	public function textList(string $field, mixed $value, int $maxItems = 20, int $maxLength = 300): array
	{
		if ($value === null || $value === '') {
			return [];
		}

		if (! is_array($value) || ! array_is_list($value)) {
			$this->fail($field, 'Oczekiwano listy.');
			return [];
		}

		if (count($value) > $maxItems) {
			$this->fail($field, "Maksymalnie {$maxItems} pozycji.");
			return [];
		}

		$items = [];

		foreach ($value as $i => $item) {
			$text = $this->text("{$field}.{$i}", $item, $maxLength);
			if ($text !== '') {
				$items[] = $text;
			}
		}

		return $items;
	}

	public function enum(string $field, mixed $value, array $allowed, ?string $default = null): string
	{
		$value = is_string($value) ? $value : $default;

		if (! in_array($value, $allowed, true)) {
			$this->fail($field, 'Niedozwolona wartość.');
			return (string) $default;
		}

		return $value;
	}

	/**
	 * Walidacja formatu URL (bez pobierania adresu): http/https, host z domeną, bez danych logowania.
	 *
	 * @param string[] $hosts dozwolone hosty (puste = dowolny)
	 */
	public function url(string $field, mixed $value, array $hosts = [], bool $httpsOnly = false): string
	{
		$url = is_string($value) ? trim($value) : '';

		if ($url === '') {
			return '';
		}

		$parts = wp_parse_url($url);
		$scheme = strtolower($parts['scheme'] ?? '');
		$host = strtolower($parts['host'] ?? '');

		$valid = strlen($url) <= 2048
			&& ! preg_match('/[\s\x00-\x1f\x7f]/', $url)
			&& in_array($scheme, $httpsOnly ? ['https'] : ['http', 'https'], true)
			&& $host !== ''
			&& str_contains($host, '.')
			&& ! isset($parts['user'])
			&& ! isset($parts['pass'])
			&& esc_url_raw($url, ['http', 'https']) === $url;

		if (! $valid) {
			$this->fail($field, 'Niepoprawny adres URL (wymagany pełny adres http/https).');
			return '';
		}

		if ($hosts !== [] && ! in_array($host, $hosts, true)) {
			$this->fail($field, 'Adres musi prowadzić do: ' . implode(', ', $hosts) . '.');
			return '';
		}

		return $url;
	}
}
