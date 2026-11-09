<?php

namespace App\Clara\Inspirations;

use WP_Error;

/**
 * Openverse (WordPress.org) — oficjalne, publiczne API obrazów na licencjach CC i domenie publicznej.
 * Dostęp anonimowy z limitem dostawcy; licencję, autora i link do źródła zwraca samo API.
 * Data w wyniku to data indeksacji w Openverse, nie publikacji.
 */
class OpenverseProvider implements SearchProvider
{
	private const ENDPOINT = 'https://api.openverse.org/v1/images/';

	private const LICENSES = [
		'by' => 'CC BY', 'by-sa' => 'CC BY-SA', 'by-nd' => 'CC BY-ND', 'by-nc' => 'CC BY-NC',
		'by-nc-sa' => 'CC BY-NC-SA', 'by-nc-nd' => 'CC BY-NC-ND', 'cc0' => 'CC0', 'pdm' => 'Public Domain Mark',
		'sampling+' => 'CC Sampling+', 'nc-sampling+' => 'CC NC-Sampling+',
	];

	public function id(): string
	{
		return 'openverse';
	}

	public function label(): string
	{
		return 'Openverse';
	}

	public function isMock(): bool
	{
		return false;
	}

	public function search(string $query, int $page, int $perPage, array $filters): array|WP_Error
	{
		$args = ['q' => $query, 'page' => $page, 'page_size' => $perPage, 'mature' => 'false'];
		if (! empty($filters['commercial'])) {
			$args['license_type'] = 'commercial';
		}

		$response = wp_remote_get(add_query_arg(array_map('rawurlencode', $args), self::ENDPOINT), [
			'timeout' => 15,
			'redirection' => 0,
			'user-agent' => 'Clara-PoC/0.1 (OhSoFresh; ' . home_url() . ')',
			'headers' => ['Accept' => 'application/json'],
		]);

		if (is_wp_error($response)) {
			return new WP_Error('clara_search_network', __('Nie udało się połączyć z wyszukiwarką Openverse.', 'clara'), ['status' => 502]);
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code === 429) {
			return new WP_Error('clara_search_rate_limited', __('Openverse ogranicza liczbę zapytań — spróbuj za chwilę.', 'clara'), ['status' => 429]);
		}
		if ($code === 400) {
			// Openverse zwraca 400 m.in. dla stron poza zakresem wyników.
			return ['items' => [], 'total' => 0];
		}
		if ($code !== 200) {
			return new WP_Error('clara_search_http', sprintf(__('Openverse zwrócił błąd HTTP %d.', 'clara'), $code), ['status' => 502]);
		}

		$data = json_decode((string) wp_remote_retrieve_body($response), true);
		if (! is_array($data) || ! is_array($data['results'] ?? null)) {
			return new WP_Error('clara_search_invalid', __('Openverse zwrócił nieczytelną odpowiedź.', 'clara'), ['status' => 502]);
		}

		$items = [];
		foreach ($data['results'] as $r) {
			$code = strtolower((string) ($r['license'] ?? ''));
			$licenseUrl = (string) ($r['license_url'] ?? '');
			$items[] = [
				'providerId' => (string) ($r['id'] ?? ''),
				'title' => (string) ($r['title'] ?? ''),
				'sourceUrl' => (string) ($r['foreign_landing_url'] ?? ''),
				'thumbnailUrl' => (string) ($r['thumbnail'] ?? ''),
				'creator' => (string) ($r['creator'] ?? ''),
				'creatorUrl' => (string) ($r['creator_url'] ?? ''),
				'sourceName' => (string) ($r['source'] ?? $r['provider'] ?? ''),
				'indexedAt' => (string) ($r['indexed_on'] ?? ''),
				'width' => isset($r['width']) ? (int) $r['width'] : null,
				'height' => isset($r['height']) ? (int) $r['height'] : null,
				'license' => $code !== '' ? [
					'code' => $code,
					'version' => (string) ($r['license_version'] ?? ''),
					'name' => trim((self::LICENSES[$code] ?? strtoupper($code)) . ' ' . ($r['license_version'] ?? '')),
					'url' => $licenseUrl,
				] : null,
			];
		}

		return ['items' => $items, 'total' => (int) ($data['result_count'] ?? count($items))];
	}
}
