<?php

namespace App\Clara\Layout;

use WP_Error;

/**
 * Adapter analizy obrazu → szkic Layout JSON v1 (provider-agnostic).
 *
 * Wejście: generationId, imagePath, imageWidth, imageHeight, mime, mock, direction (kierunek
 * artystyczny), texts [{role, content}] — dokładne treści, które MUSZĄ zostać tekstem, palette,
 * fonts {heading, body}. Wynik: {layout, cost, usage} lub WP_Error. Wynik jest zawsze walidowany
 * przez LayoutSchema, a przy błędzie zastępowany deterministyczną heurystyką.
 */
interface LayoutAnalyzer
{
	public function id(): string;

	public function model(): string;

	public function analyze(array $input): array|WP_Error;
}
