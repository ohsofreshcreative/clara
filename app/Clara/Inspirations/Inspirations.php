<?php

namespace App\Clara\Inspirations;

use App\Clara\Database\Schema;

/**
 * Kolekcja inspiracji projektu (opcjonalnie przypisanych do sekcji). Zapisujemy link, miniaturę
 * dostawcy i atrybucję — bez pobierania i kopiowania cudzych plików (local_asset_id pozostaje puste).
 * Deduplikacja po znormalizowanym adresie źródła w obrębie projektu.
 */
class Inspirations
{
	private const TRACKING = ['/^utm_/', '/^fbclid$/', '/^gclid$/', '/^mc_(cid|eid)$/', '/^ref$/'];

	public static function normalizeUrl(string $url): string
	{
		$p = wp_parse_url($url);
		$scheme = strtolower($p['scheme'] ?? 'https');
		$host = strtolower($p['host'] ?? '');
		$port = isset($p['port']) ? ':' . $p['port'] : '';
		$path = $p['path'] ?? '/';
		$raw = $p['query'] ?? '';

		// Ścieżki nie zmieniamy (link ma działać); query przebudowujemy tylko po usunięciu parametrów śledzących.
		$pairs = $raw === '' ? [] : explode('&', $raw);
		$kept = array_filter($pairs, fn ($pair) => ! array_filter(self::TRACKING, fn ($re) => preg_match($re, urldecode(explode('=', $pair)[0]))));
		$query = implode('&', $kept);

		return "{$scheme}://{$host}{$port}{$path}" . ($query !== '' ? "?{$query}" : '');
	}

	public static function findByUrl(int $projectId, string $url): ?array
	{
		global $wpdb;

		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('inspirations') . ' WHERE project_id = %d AND source_url = %s LIMIT 1', $projectId, self::normalizeUrl($url)), ARRAY_A);

		return $row ? self::hydrate($row) : null;
	}

	public static function find(int $id): ?array
	{
		global $wpdb;

		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('inspirations') . ' WHERE id = %d', $id), ARRAY_A);

		return $row ? self::hydrate($row) : null;
	}

	/**
	 * @return array{0: array, 1: bool} [inspiracja, czy duplikat]
	 */
	public static function save(int $projectId, ?int $sectionId, string $sourceUrl, string $thumbnailUrl, array $attribution, array $rights, array $metadata): array
	{
		global $wpdb;

		if ($existing = self::findByUrl($projectId, $sourceUrl)) {
			return [$existing, true];
		}

		$wpdb->insert(Schema::table('inspirations'), [
			'project_id' => $projectId,
			'section_id' => $sectionId,
			'source_url' => self::normalizeUrl($sourceUrl),
			'thumbnail_url' => $thumbnailUrl,
			'attribution_json' => wp_json_encode($attribution),
			'rights_json' => wp_json_encode($rights),
			'metadata_json' => wp_json_encode($metadata + ['addedBy' => get_current_user_id()]),
			'created_at' => current_time('mysql', true),
		]);

		return [self::find((int) $wpdb->insert_id), false];
	}

	public static function forProject(int $projectId, ?int $sectionId = null): array
	{
		global $wpdb;

		$table = Schema::table('inspirations');
		$sql = $sectionId
			? $wpdb->prepare("SELECT * FROM {$table} WHERE project_id = %d AND section_id = %d ORDER BY id DESC LIMIT 200", $projectId, $sectionId)
			: $wpdb->prepare("SELECT * FROM {$table} WHERE project_id = %d ORDER BY id DESC LIMIT 200", $projectId);

		return array_map([self::class, 'hydrate'], $wpdb->get_results($sql, ARRAY_A) ?: []);
	}

	/**
	 * @return string[] znormalizowane adresy zapisane w projekcie (do oznaczania wyników wyszukiwania)
	 */
	public static function savedUrls(int $projectId): array
	{
		global $wpdb;

		return $wpdb->get_col($wpdb->prepare('SELECT source_url FROM ' . Schema::table('inspirations') . ' WHERE project_id = %d', $projectId)) ?: [];
	}

	public static function delete(int $id): void
	{
		global $wpdb;

		$wpdb->delete(Schema::table('inspirations'), ['id' => $id]);
	}

	public static function deleteForProject(int $projectId): void
	{
		global $wpdb;

		$wpdb->delete(Schema::table('inspirations'), ['project_id' => $projectId]);
	}

	/** Po usunięciu sekcji inspiracje zostają w kolekcji projektu, bez przypisania do sekcji. */
	public static function detachSection(int $sectionId): void
	{
		global $wpdb;

		$wpdb->query($wpdb->prepare('UPDATE ' . Schema::table('inspirations') . ' SET section_id = NULL WHERE section_id = %d', $sectionId));
	}

	private static function hydrate(array $row): array
	{
		$decode = fn ($json) => is_array($d = json_decode((string) $json, true)) ? $d : [];

		return [
			'id' => (int) $row['id'],
			'project_id' => (int) $row['project_id'],
			'section_id' => $row['section_id'] === null ? null : (int) $row['section_id'],
			'source_url' => $row['source_url'],
			'thumbnail_url' => $row['thumbnail_url'],
			'attribution' => $decode($row['attribution_json']),
			'rights' => $decode($row['rights_json']),
			'metadata' => $decode($row['metadata_json']),
			'created_at' => mysql_to_rfc3339($row['created_at']),
		];
	}
}
