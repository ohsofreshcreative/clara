<?php

namespace App\Clara\Layout;

use App\Clara\Database\Schema;

/**
 * Wersje Layout JSON dla generacji obrazu. Każda analiza lub edycja to nowy wiersz —
 * poprzednie wersje nie są nadpisywane.
 */
class LayoutDocuments
{
	/** Generacje, do których może należeć dokument (obraz wariantu). */
	public const OWNER_KINDS = ['image'];

	public static function create(int $generationId, array $layout, array $validation): array
	{
		global $wpdb;

		$wpdb->insert(Schema::table('layout_documents'), [
			'generation_id' => $generationId,
			'schema_version' => LayoutSchema::VERSION,
			'layout_json' => wp_json_encode($layout),
			'validation_json' => wp_json_encode($validation),
			'created_at' => current_time('mysql', true),
		]);

		return self::find((int) $wpdb->insert_id);
	}

	public static function find(int $id): ?array
	{
		global $wpdb;

		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('layout_documents') . ' WHERE id = %d', $id), ARRAY_A);

		return $row ? self::hydrate($row) : null;
	}

	/** Lista wersji (bez pełnego JSON), najnowsze pierwsze. */
	public static function forGeneration(int $generationId): array
	{
		global $wpdb;

		$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::table('layout_documents') . ' WHERE generation_id = %d ORDER BY id DESC LIMIT 50', $generationId), ARRAY_A);

		return array_map(function ($row) {
			$doc = self::hydrate($row);
			unset($doc['layout']);
			return $doc;
		}, $rows ?: []);
	}

	public static function deleteForGenerations(array $generationIds): void
	{
		global $wpdb;

		$ids = array_filter(array_map('intval', $generationIds));
		if ($ids) {
			$wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('layout_documents') . ' WHERE generation_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')', ...$ids));
		}
	}

	private static function hydrate(array $row): array
	{
		$validation = json_decode((string) $row['validation_json'], true) ?: [];

		return [
			'id' => (int) $row['id'],
			'generation_id' => (int) $row['generation_id'],
			'schema_version' => (int) $row['schema_version'],
			'layout' => json_decode((string) $row['layout_json'], true) ?: null,
			'validation' => $validation,
			'created_at' => mysql_to_rfc3339($row['created_at']),
		];
	}
}
