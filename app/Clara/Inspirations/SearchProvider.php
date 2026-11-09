<?php

namespace App\Clara\Inspirations;

use WP_Error;

/**
 * Adapter wyszukiwarki inspiracji. Tylko oficjalne API dostawców — bez scrapowania i obchodzenia blokad.
 *
 * Znormalizowany wynik:
 * providerId, title, sourceUrl, thumbnailUrl, creator, creatorUrl, sourceName, indexedAt, width, height,
 * license: null | {code, version, name, url} — WYŁĄCZNIE gdy dostawca ją deklaruje; Clara licencji nie zgaduje.
 */
interface SearchProvider
{
	public function id(): string;

	public function label(): string;

	public function isMock(): bool;

	/**
	 * @return array{items: array<int, array>, total: int}|WP_Error
	 */
	public function search(string $query, int $page, int $perPage, array $filters): array|WP_Error;
}
