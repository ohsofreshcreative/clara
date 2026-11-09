<?php

namespace App\Clara\Creative;

use WP_Error;

/**
 * OpenAI Images API (gpt-image-2). Klucz wyłącznie po stronie serwera: stała/zmienna
 * CLARA_OPENAI_API_KEY; model: CLARA_OPENAI_IMAGE_MODEL. Obrazy wracają jako base64 (JPEG).
 */
class OpenAiImageProvider implements ImageProvider
{
	public const ENDPOINT = 'https://api.openai.com/v1/images/generations';

	public const EDITS_ENDPOINT = 'https://api.openai.com/v1/images/edits';

	public const SIZE = '1536x1024';

	private const TIMEOUT = 240;

	public static function apiKey(): string
	{
		$key = defined('CLARA_OPENAI_API_KEY') ? (string) CLARA_OPENAI_API_KEY : (string) getenv('CLARA_OPENAI_API_KEY');
		// Testy automatyczne chwilowo wyłączają klucz z konfiguracji (bez kosztów); filtr nadal działa.
		if (get_option('clara_test_offline')) {
			$key = '';
		}

		return (string) apply_filters('clara/openai_api_key', $key);
	}

	public function id(): string
	{
		return 'openai';
	}

	public function model(): string
	{
		$model = defined('CLARA_OPENAI_IMAGE_MODEL') ? (string) CLARA_OPENAI_IMAGE_MODEL : 'gpt-image-2';

		return (string) apply_filters('clara/openai_image_model', $model);
	}

	public function isMock(): bool
	{
		return false;
	}

	/** Szacunek USD za obraz 1536×1024, jakość medium (filtr `clara/openai_image_cost` do aktualizacji cennika). */
	public function estimateCost(): ?float
	{
		return (float) apply_filters('clara/openai_image_cost', 0.06);
	}

	public function generate(string $prompt, array $direction): array|WP_Error
	{
		$response = wp_remote_post(self::ENDPOINT, [
			'timeout' => self::TIMEOUT,
			'redirection' => 0,
			'headers' => ['Authorization' => 'Bearer ' . self::apiKey(), 'Content-Type' => 'application/json'],
			'body' => self::body($this->model(), $prompt),
		]);

		return self::result($response, $this->estimateCost());
	}

	/**
	 * Generowanie z obrazem referencyjnym (kierunek projektu): endpoint edits przyjmuje obraz
	 * jako wzorzec stylu, a prompt opisuje nowy wariant.
	 */
	public function generateWithReference(string $prompt, string $imageBytes, string $mime): array|WP_Error
	{
		$boundary = 'clara' . wp_generate_password(24, false, false);
		$ext = $mime === 'image/png' ? 'png' : 'jpg';
		$fields = [
			'model' => $this->model(),
			'prompt' => $prompt,
			'n' => '1',
			'size' => self::SIZE,
			'quality' => (string) apply_filters('clara/openai_image_quality', 'medium'),
			'output_format' => 'jpeg',
		];
		$body = '';
		foreach ($fields as $name => $value) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"image[]\"; filename=\"reference.{$ext}\"\r\nContent-Type: {$mime}\r\n\r\n{$imageBytes}\r\n--{$boundary}--\r\n";

		$response = wp_remote_post(self::EDITS_ENDPOINT, [
			'timeout' => self::TIMEOUT,
			'redirection' => 0,
			'headers' => ['Authorization' => 'Bearer ' . self::apiKey(), 'Content-Type' => "multipart/form-data; boundary={$boundary}"],
			'body' => $body,
		]);

		return self::result($response, $this->estimateCost());
	}

	private static function result(mixed $response, ?float $estimate): array|WP_Error
	{
		if (is_wp_error($response)) {
			return new WP_Error('clara_image_network', __('Brak połączenia z OpenAI.', 'clara'), ['retryable' => true]);
		}

		return self::parse((int) wp_remote_retrieve_response_code($response), (string) wp_remote_retrieve_body($response), $estimate);
	}

	public static function body(string $model, string $prompt): string
	{
		return (string) wp_json_encode([
			'model' => $model,
			'prompt' => $prompt,
			'n' => 1,
			'size' => self::SIZE,
			'quality' => (string) apply_filters('clara/openai_image_quality', 'medium'),
			'output_format' => 'jpeg',
		]);
	}

	/** Odpowiedź API → wynik adaptera. Treść odpowiedzi nie trafia do komunikatów (bez echa danych dostawcy). */
	public static function parse(int $code, string $raw, ?float $estimate): array|WP_Error
	{
		$body = json_decode($raw, true);

		if ($code !== 200) {
			$apiCode = (string) ($body['error']['code'] ?? '');
			if ($apiCode === 'moderation_blocked' || $apiCode === 'content_policy_violation') {
				return new WP_Error('clara_image_moderation', __('OpenAI odrzucił prompt przez zasady treści — zmień opis.', 'clara'));
			}
			$messages = [
				400 => 'OpenAI odrzucił żądanie (błędne parametry lub prompt).',
				401 => 'Klucz API OpenAI jest nieprawidłowy.',
				403 => 'Klucz API OpenAI nie ma dostępu do modelu obrazów (wymagana weryfikacja organizacji?).',
				404 => 'Skonfigurowany model obrazów OpenAI nie istnieje.',
				429 => 'OpenAI ogranicza liczbę zapytań lub brak środków na koncie.',
			];
			$message = $messages[$code] ?? ($code >= 500 ? 'OpenAI jest chwilowo niedostępny.' : "OpenAI zwrócił błąd HTTP {$code}.");

			return new WP_Error('clara_image_http', __($message, 'clara'), ['providerStatus' => $code, 'retryable' => $code >= 500]);
		}

		$data = base64_decode((string) ($body['data'][0]['b64_json'] ?? ''), true);
		if (! $data) {
			return new WP_Error('clara_image_empty', __('OpenAI nie zwrócił obrazu.', 'clara'));
		}
		$mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);

		return [
			'body' => $data,
			'mime' => $mime,
			'cost' => null,
			'meta' => array_filter([
				'estimatedCost' => $estimate,
				'inputTokens' => $body['usage']['input_tokens'] ?? null,
				'outputTokens' => $body['usage']['output_tokens'] ?? null,
				'revisedPrompt' => isset($body['data'][0]['revised_prompt']) ? mb_substr((string) $body['data'][0]['revised_prompt'], 0, 1000) : null,
			], fn ($v) => $v !== null),
		];
	}
}
