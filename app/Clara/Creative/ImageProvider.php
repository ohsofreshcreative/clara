<?php

namespace App\Clara\Creative;

use WP_Error;

/**
 * Adapter generatora obrazów (po stronie serwera, niezależny od dostawcy). Dostawcę wybiera
 * filtr `clara/image_provider`; klucze, jeśli są, czyta wyłącznie implementacja na serwerze.
 */
interface ImageProvider
{
	public function id(): string;

	public function model(): string;

	/** true = obrazy poglądowe bez AI (UI musi to jasno oznaczyć). */
	public function isMock(): bool;

	/** Szacowany koszt jednego obrazu w USD lub null, gdy nieznany. */
	public function estimateCost(): ?float;

	/**
	 * @return array{body: string, mime: string, cost: float|null, meta: array}|WP_Error
	 *         WP_Error z data.retryable=true oznacza błąd przejściowy (limit, sieć).
	 */
	public function generate(string $prompt, array $direction): array|WP_Error;
}
