<?php

namespace App\Clara\Projects;

use App\Clara\Creative\Generations;
use App\Clara\Database\Schema;
use App\Clara\Inspirations\Inspirations;

class Sections
{
	public static function find(int $id): ?array
	{
		global $wpdb;

		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('sections') . ' WHERE id = %d', $id), ARRAY_A);

		return $row ? self::hydrate($row) : null;
	}

	/**
	 * @return array<int, array>
	 */
	public static function forProject(int $projectId): array
	{
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare('SELECT * FROM ' . Schema::table('sections') . ' WHERE project_id = %d ORDER BY id ASC', $projectId),
			ARRAY_A
		);

		return array_map([self::class, 'hydrate'], $rows ?: []);
	}

	public static function create(int $projectId, array $data): int
	{
		global $wpdb;

		$wpdb->insert(Schema::table('sections'), [
			'project_id' => $projectId,
			'type' => $data['type'],
			'name' => $data['name'] ?? SectionInput::TYPES[$data['type']],
			'requirements_json' => wp_json_encode($data['requirements'] ?? []),
			'status' => $data['status'] ?? 'draft',
			'created_at' => current_time('mysql', true),
		]);

		return (int) $wpdb->insert_id;
	}

	public static function update(int $id, array $data): void
	{
		global $wpdb;

		$columns = array_intersect_key($data, array_flip(['name', 'status']));

		if (array_key_exists('requirements', $data)) {
			$columns['requirements_json'] = wp_json_encode($data['requirements']);
		}

		if ($columns) {
			$wpdb->update(Schema::table('sections'), $columns, ['id' => $id]);
		}
	}

	/** Wybrany wariant sekcji. Wybór nie zmienia samych generacji. */
	public static function select(int $id, ?int $generationId): void
	{
		global $wpdb;

		$wpdb->update(Schema::table('sections'), ['selected_generation_id' => $generationId], ['id' => $id]);
	}

	public static function delete(int $id): void
	{
		global $wpdb;

		Generations::deleteForSections([$id]);
		Inspirations::detachSection($id);
		$wpdb->delete(Schema::table('sections'), ['id' => $id]);
	}

	private static function hydrate(array $row): array
	{
		$requirements = json_decode((string) $row['requirements_json'], true);

		return [
			'id' => (int) $row['id'],
			'project_id' => (int) $row['project_id'],
			'type' => $row['type'],
			'type_label' => SectionInput::TYPES[$row['type']] ?? $row['type'],
			'name' => $row['name'],
			'status' => $row['status'],
			'requirements' => is_array($requirements) ? $requirements : [],
			'selected_generation_id' => empty($row['selected_generation_id']) ? null : (int) $row['selected_generation_id'],
			'created_at' => mysql_to_rfc3339($row['created_at']),
		];
	}
}
