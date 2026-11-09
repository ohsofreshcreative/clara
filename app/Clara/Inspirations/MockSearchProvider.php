<?php

namespace App\Clara\Inspirations;

use WP_Error;

/**
 * Mock wyszukiwarki do testów: deterministyczne, jawnie oznaczone wyniki prowadzące do lokalnej strony.
 * Zapytania zaczynające się od „zz” zwracają pusty wynik, „blad” — błąd dostawcy.
 */
class MockSearchProvider implements SearchProvider
{
	public function id(): string
	{
		return 'mock';
	}

	public function label(): string
	{
		return 'Mock (dane testowe)';
	}

	public function isMock(): bool
	{
		return true;
	}

	public function search(string $query, int $page, int $perPage, array $filters): array|WP_Error
	{
		if (str_starts_with($query, 'blad')) {
			return new WP_Error('clara_search_http', __('Wyszukiwarka testowa zwróciła błąd.', 'clara'), ['status' => 502]);
		}

		$total = str_starts_with($query, 'zz') ? 0 : 6;
		$items = [];

		for ($i = ($page - 1) * $perPage; $i < min($total, $page * $perPage); $i++) {
			// Co druga pozycja bez licencji — sprawdza, że UI nie dopisuje jej sam.
			$licensed = $i % 2 === 0;
			if (! empty($filters['commercial']) && ! $licensed) {
				continue;
			}
			$items[] = [
				'providerId' => 'mock-' . md5($query) . "-{$i}",
				'title' => "Inspiracja testowa {$i} — {$query}",
				'sourceUrl' => home_url("/?clara-mock-inspiration={$i}"),
				'thumbnailUrl' => '',
				'creator' => $licensed ? "Autor testowy {$i}" : '',
				'creatorUrl' => '',
				'sourceName' => 'mock',
				'indexedAt' => '2026-01-0' . ($i + 1) . 'T10:00:00Z',
				'width' => 1200,
				'height' => 800,
				'license' => $licensed ? ['code' => 'by', 'version' => '4.0', 'name' => 'CC BY 4.0', 'url' => 'https://creativecommons.org/licenses/by/4.0/'] : null,
			];
		}

		return ['items' => $items, 'total' => $total];
	}
}
