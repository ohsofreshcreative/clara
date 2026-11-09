<?php

namespace App\Clara\Creative;

use WP_Error;

/**
 * Brief Analyzer → 4 Art Directions. Ścieżka: LLM (structured output) → walidacja kontraktu →
 * przy błędzie dostawcy lub niezgodnym JSON deterministyczny fallback z zapisanym powodem.
 */
class ArtDirector
{
	public static function provider(): ?LlmProvider
	{
		$default = AnthropicProvider::apiKey() !== '' ? new AnthropicProvider() : null;
		$provider = apply_filters('clara/llm_provider', $default);

		return $provider instanceof LlmProvider ? $provider : null;
	}

	public static function run(array $project, array $section, string $prompt): array|WP_Error
	{
		$context = CreativeContext::build($project, $section, $prompt);
		$provider = self::provider();
		$fallbackReason = null;
		$usage = null;
		$cost = null;
		$result = null;

		if ($provider) {
			$response = $provider->complete(CreativeContext::system(), CreativeContext::user($context), ArtDirectionSchema::jsonSchema());

			if (is_wp_error($response)) {
				$fallbackReason = self::reason($response);
			} else {
				$usage = $response['usage'];
				$cost = $response['cost'];
				$result = ArtDirectionSchema::validate($response['data'], $context);

				if (is_wp_error($result)) {
					$fallbackReason = self::reason($result);
					$result = null;
				}
			}
		} else {
			$fallbackReason = ['code' => 'clara_llm_unconfigured', 'message' => 'Dostawca AI nie jest skonfigurowany na serwerze.'];
		}

		if (! $result) {
			$result = ArtDirectionSchema::validate(ArtDirectionFallback::generate($context), $context);

			// Fallback musi zawsze spełniać kontrakt — inaczej to błąd programu, nie danych.
			if (is_wp_error($result)) {
				return new WP_Error('clara_art_fallback_failed', __('Nie udało się przygotować kierunków.', 'clara'), ['status' => 500, 'errors' => $result->get_error_data()['errors'] ?? []]);
			}
		}

		$llmUsed = $fallbackReason === null;

		$runId = Generations::insertRun($section['id'], $result['directions'], [
			'source' => $llmUsed ? 'llm' : 'fallback',
			'fallbackReason' => $fallbackReason,
			'prompt' => $prompt,
			'interpretation' => $result['interpretation'],
			'context' => [
				'brief' => $context['brief'],
				'section' => $context['section'],
				'requiredContent' => $context['requiredContent'],
				'hardConstraints' => $context['hardConstraints'],
				'softConstraints' => $context['softConstraints'],
				'designSystem' => $context['designSystem'] ? ['id' => $context['designSystem']['id'], 'version' => $context['designSystem']['version']] : null,
			],
			'usage' => $usage,
			// Koszt próby LLM zapisujemy także wtedy, gdy wynik odrzucono i użyto fallbacku.
			'attemptCost' => $cost,
			'attemptedProvider' => $provider?->id(),
			'createdBy' => get_current_user_id(),
		], $llmUsed ? $provider->id() : 'fallback', $llmUsed ? $provider->model() : ArtDirectionFallback::MODEL, $llmUsed ? $cost : null);

		foreach (Generations::runs($section['id'], 1) as $run) {
			if ($run['runId'] === $runId) {
				return $run;
			}
		}

		return new WP_Error('clara_art_store_failed', __('Nie udało się zapisać kierunków.', 'clara'), ['status' => 500]);
	}

	private static function reason(WP_Error $error): array
	{
		return [
			'code' => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'errors' => array_slice($error->get_error_data()['errors'] ?? [], 0, 20, true),
		];
	}
}
