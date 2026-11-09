<?php

namespace App\Clara\Creative;

use App\Clara\Database\Schema;
use App\Clara\Layout\LayoutDocuments;
use App\Clara\Projects\Attachments;

/**
 * Generacje w tabeli `generations`. Jedno uruchomienie Art Directora = 4 wiersze kind=art_direction
 * powiązane wspólnym runId w prompt_json (tam też migawka briefu, DS i promptu — lineage).
 */
class Generations
{
	public const KIND_ART = 'art_direction';

	public static function insertRun(int $sectionId, array $directions, array $meta, string $provider, string $model, ?float $cost): string
	{
		global $wpdb;

		$runId = wp_generate_uuid4();
		$now = current_time('mysql', true);

		foreach ($directions as $direction) {
			$wpdb->insert(Schema::table('generations'), [
				'section_id' => $sectionId,
				'kind' => self::KIND_ART,
				'art_direction_json' => wp_json_encode($direction),
				'prompt_json' => wp_json_encode(['runId' => $runId] + $meta),
				'model_id' => $model,
				'provider' => $provider,
				'status' => 'succeeded',
				'estimated_cost' => $cost === null ? null : round($cost / count($directions), 4),
				'created_at' => $now,
			]);
		}

		return $runId;
	}

	/**
	 * Uruchomienia sekcji, najnowsze pierwsze.
	 */
	public static function runs(int $sectionId, int $limit = 10): array
	{
		global $wpdb;

		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM ' . Schema::table('generations') . ' WHERE section_id = %d AND kind = %s ORDER BY id DESC LIMIT %d',
			$sectionId,
			self::KIND_ART,
			$limit * ArtDirectionSchema::COUNT
		), ARRAY_A);

		$runs = [];
		foreach ($rows ?: [] as $row) {
			$meta = json_decode((string) $row['prompt_json'], true) ?: [];
			$runId = $meta['runId'] ?? 'legacy-' . $row['id'];

			$runs[$runId] ??= [
				'runId' => $runId,
				'section_id' => (int) $row['section_id'],
				'provider' => $row['provider'],
				'model' => $row['model_id'],
				'cost' => null,
				'created_at' => mysql_to_rfc3339($row['created_at']),
				'directions' => [],
			] + $meta;

			if ($row['estimated_cost'] !== null) {
				$runs[$runId]['cost'] = round(($runs[$runId]['cost'] ?? 0) + (float) $row['estimated_cost'], 4);
			}

			$runs[$runId]['directions'][] = ['id' => (int) $row['id']] + (json_decode((string) $row['art_direction_json'], true) ?: []);
		}

		foreach ($runs as &$run) {
			usort($run['directions'], fn ($a, $b) => strcmp($a['key'] ?? '', $b['key'] ?? ''));
		}

		return array_values($runs);
	}

	/**
	 * Generacja dowolnego z podanych rodzajów (np. image).
	 *
	 * @param string[] $kinds
	 */
	public static function find(int $id, array $kinds): ?array
	{
		global $wpdb;

		if (! $kinds) {
			return null;
		}

		$in = implode(',', array_fill(0, count($kinds), '%s'));
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('generations') . " WHERE id = %d AND kind IN ({$in})", $id, ...$kinds), ARRAY_A);

		return $row ?: null;
	}

	public static function deleteForSections(array $sectionIds): void
	{
		global $wpdb;

		$ids = array_filter(array_map('intval', $sectionIds));

		if (! $ids) {
			return;
		}

		$in = implode(',', array_fill(0, count($ids), '%d'));
		$generations = Schema::table('generations');

		// Obrazy wygenerowane dla sekcji i ich joby (Projects::delete kasuje pliki projektu osobno).
		$images = $wpdb->get_results($wpdb->prepare("SELECT id, result_asset_id FROM {$generations} WHERE kind = 'image' AND section_id IN ({$in})", ...$ids), ARRAY_A);
		foreach ($images ?: [] as $image) {
			if ($image['result_asset_id'] && get_post_meta((int) $image['result_asset_id'], Attachments::META, true)) {
				wp_delete_attachment((int) $image['result_asset_id'], true);
			}
		}
		if ($images) {
			$gen = array_map('intval', array_column($images, 'id'));
			$wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('jobs') . " WHERE job_type = 'image_generation' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.generationId')) AS UNSIGNED) IN (" . implode(',', array_fill(0, count($gen), '%d')) . ')', ...$gen));
		}

		$all = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$generations} WHERE section_id IN ({$in})", ...$ids)) ?: []);
		Gallery::deleteSources($all);
		LayoutDocuments::deleteForGenerations($all);
		$wpdb->query($wpdb->prepare("DELETE FROM {$generations} WHERE section_id IN ({$in})", ...$ids));
	}
}
