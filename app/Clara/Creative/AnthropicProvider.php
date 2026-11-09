<?php

namespace App\Clara\Creative;

use WP_Error;

/**
 * Claude przez Messages API (wp_remote_post — spójnie z resztą integracji i testowalne przez
 * pre_http_request). Klucz wyłącznie po stronie serwera: stała/zmienna CLARA_ANTHROPIC_API_KEY.
 * Model konfigurowalny (CLARA_LLM_MODEL lub filtr `clara/llm_model`).
 */
class AnthropicProvider implements LlmProvider
{
	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/** USD za 1M tokenów [wejście, wyjście]; filtr `clara/llm_prices` pozwala zaktualizować cennik. */
	private const PRICES = [
		'claude-opus-5-5' => [4.0, 20.0],
		'claude-sonnet-5-5' => [2.0, 10.0],
		'claude-haiku-5-5' => [0.10, 0.50],
	];

	/**
	 * Parametry per funkcja (np. projektant sekcji generuje dłuższe odpowiedzi innym modelem);
	 * null = konfiguracja globalna.
	 */
	public function __construct(
		private ?string $modelOverride = null,
		private string $effort = 'medium',
		private int $maxTokens = 16000,
		private int $timeout = 120,
	) {
	}

	public static function apiKey(): string
	{
		$key = defined('CLARA_ANTHROPIC_API_KEY') ? (string) CLARA_ANTHROPIC_API_KEY : (string) getenv('CLARA_ANTHROPIC_API_KEY');
		// Testy automatyczne chwilowo wyłączają klucz z konfiguracji (bez kosztów); filtr nadal działa.
		if (get_option('clara_test_offline')) {
			$key = '';
		}

		return (string) apply_filters('clara/anthropic_api_key', $key);
	}

	public function id(): string
	{
		return 'anthropic';
	}

	public function model(): string
	{
		if ($this->modelOverride) {
			return $this->modelOverride;
		}

		$model = defined('CLARA_LLM_MODEL') ? (string) CLARA_LLM_MODEL : (string) getenv('CLARA_LLM_MODEL');

		return (string) apply_filters('clara/llm_model', $model !== '' ? $model : 'claude-opus-5-5');
	}

	public function complete(string $system, string $user, array $schema): array|WP_Error
	{
		return $this->send($system, $user, $schema);
	}

	/** Wywołanie z obrazem (analiza wizualna). Obraz przesyłany jako base64 — bez publicznego URL. */
	public function completeWithImage(string $system, string $user, array $schema, string $mime, string $base64): array|WP_Error
	{
		return $this->send($system, [
			['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
			['type' => 'text', 'text' => $user],
		], $schema);
	}

	private function send(string $system, string|array $content, array $schema): array|WP_Error
	{
		$response = wp_remote_post(self::ENDPOINT, [
			'timeout' => $this->timeout,
			'redirection' => 0,
			'headers' => [
				'x-api-key' => self::apiKey(),
				'anthropic-version' => '2026-10-21',
				'content-type' => 'application/json',
			],
			'body' => wp_json_encode([
				'model' => $this->model(),
				'max_tokens' => $this->maxTokens,
				'system' => $system,
				'messages' => [['role' => 'user', 'content' => $content]],
				'output_config' => ['effort' => $this->effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
			]),
		]);

		if (is_wp_error($response)) {
			return new WP_Error('clara_llm_network', __('Brak połączenia z dostawcą AI.', 'clara'), ['status' => 502]);
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode((string) wp_remote_retrieve_body($response), true);

		if ($code !== 200) {
			$messages = [
				401 => 'Klucz API dostawcy AI jest nieprawidłowy.',
				403 => 'Klucz API nie ma uprawnień do tego modelu.',
				404 => 'Skonfigurowany model AI nie istnieje.',
				429 => 'Dostawca AI ogranicza liczbę zapytań — spróbuj później.',
				529 => 'Dostawca AI jest przeciążony — spróbuj później.',
			];
			return new WP_Error('clara_llm_http', __($messages[$code] ?? "Dostawca AI zwrócił błąd HTTP {$code}.", 'clara'), ['status' => 502, 'providerStatus' => $code]);
		}

		$stop = $body['stop_reason'] ?? '';
		if ($stop === 'refusal' || $stop === 'max_tokens') {
			return new WP_Error('clara_llm_incomplete', $stop === 'refusal' ? __('Model odmówił odpowiedzi.', 'clara') : __('Odpowiedź modelu została ucięta.', 'clara'), ['status' => 502]);
		}

		$text = implode('', array_map(fn ($b) => ($b['type'] ?? '') === 'text' ? (string) $b['text'] : '', $body['content'] ?? []));
		$data = json_decode($text, true);

		if (! is_array($data)) {
			return new WP_Error('clara_llm_invalid_json', __('Model zwrócił niepoprawny JSON.', 'clara'), ['status' => 502]);
		}

		$usage = ['input' => (int) ($body['usage']['input_tokens'] ?? 0), 'output' => (int) ($body['usage']['output_tokens'] ?? 0)];
		$prices = apply_filters('clara/llm_prices', self::PRICES)[$this->model()] ?? null;

		return [
			'data' => $data,
			'usage' => $usage,
			'cost' => $prices ? round(($usage['input'] * $prices[0] + $usage['output'] * $prices[1]) / 1_000_000, 4) : null,
		];
	}
}
