<?php

namespace App\Clara\Creative;

use WP_Error;

/**
 * Adapter LLM niezależny od dostawcy. Dostawcę i model wybiera konfiguracja serwera
 * (filtr `clara/llm_provider`), nie kod Creative Engine.
 */
interface LlmProvider
{
	public function id(): string;

	public function model(): string;

	/**
	 * @param array $schema JSON Schema oczekiwanej odpowiedzi (structured output)
	 * @return array{data: array, usage: array{input: int, output: int}, cost: float|null}|WP_Error
	 */
	public function complete(string $system, string $user, array $schema): array|WP_Error;
}
