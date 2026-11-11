<?php

namespace App\Clara\DesignSystems;

use App\Clara\Database\Schema;

/**
 * Wersje design systemu projektu. Wersje są niezmienne: każda zmiana (ręczna, import, Figma,
 * screeny, przywrócenie) tworzy nową wersję z provenance w source_meta_json.
 */
class DesignSystems
{
	public const SOURCES = ['manual', 'json_import', 'figma_rest', 'figma_colors', 'reference_screens', 'restore'];

	public static function find(int $id): ?array
	{
		global $wpdb;

		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('design_systems') . ' WHERE id = %d', $id), ARRAY_A);

		return $row ? self::hydrate($row) : null;
	}

	public static function latest(int $projectId): ?array
	{
		global $wpdb;

		$row = $wpdb->get_row($wpdb->prepare(
			'SELECT * FROM ' . Schema::table('design_systems') . ' WHERE project_id = %d ORDER BY version DESC LIMIT 1',
			$projectId
		), ARRAY_A);

		return $row ? self::hydrate($row) : null;
	}

	/**
	 * Lista wersji bez pełnych tokenów (tylko liczniki).
	 */
	public static function versions(int $projectId): array
	{
		global $wpdb;

		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM ' . Schema::table('design_systems') . ' WHERE project_id = %d ORDER BY version DESC LIMIT 100',
			$projectId
		), ARRAY_A);

		return array_map(function ($row) {
			$ds = self::hydrate($row);
			$ds['counts'] = array_map('count', $ds['tokens']);
			unset($ds['tokens']);
			return $ds;
		}, $rows ?: []);
	}

	/**
	 * @return array<int, int> project_id => najnowsza wersja
	 */
	public static function latestVersions(array $projectIds): array
	{
		global $wpdb;

		$ids = array_filter(array_map('intval', $projectIds));

		if (! $ids) {
			return [];
		}

		$placeholders = implode(',', array_fill(0, count($ids), '%d'));
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT project_id, MAX(version) AS version FROM ' . Schema::table('design_systems') . " WHERE project_id IN ({$placeholders}) GROUP BY project_id",
			...$ids
		), ARRAY_A);

		return array_column(array_map(fn ($r) => ['p' => (int) $r['project_id'], 'v' => (int) $r['version']], $rows ?: []), 'v', 'p');
	}

	/**
	 * Tworzy kolejną wersję. Unikalność (project_id, version) chroni przed wyścigiem — przy kolizji ponawiamy.
	 */
	public static function createVersion(int $projectId, array $tokens, array $assets, array $source): array
	{
		global $wpdb;

		$table = Schema::table('design_systems');

		for ($attempt = 0; $attempt < 3; $attempt++) {
			$next = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(version), 0) + 1 FROM {$table} WHERE project_id = %d", $projectId));

			$source['createdBy'] = get_current_user_id();
			$source['parentVersion'] = $next > 1 ? $next - 1 : null;

			$suppress = $wpdb->suppress_errors(true);
			$ok = $wpdb->insert($table, [
				'project_id' => $projectId,
				'version' => $next,
				'tokens_json' => wp_json_encode($tokens),
				'assets_json' => wp_json_encode(array_values($assets)),
				'source_meta_json' => wp_json_encode($source),
				'created_at' => current_time('mysql', true),
			]);
			$wpdb->suppress_errors($suppress);

			if ($ok) {
				return self::find((int) $wpdb->insert_id);
			}
		}

		throw new \RuntimeException('Nie udało się zapisać wersji design systemu.');
	}

	public static function deleteForProject(int $projectId): void
	{
		global $wpdb;

		$wpdb->delete(Schema::table('design_systems'), ['project_id' => $projectId]);
	}

	private static function hydrate(array $row): array
	{
		$decode = fn ($json) => is_array($d = json_decode((string) $json, true)) ? $d : [];
		$tokens = $decode($row['tokens_json']) + array_fill_keys(TokenSchema::GROUPS, []);

		return [
			'id' => (int) $row['id'],
			'project_id' => (int) $row['project_id'],
			'version' => (int) $row['version'],
			'tokens' => $tokens,
			'assets' => $decode($row['assets_json']),
			'source' => $decode($row['source_meta_json']),
			'created_at' => mysql_to_rfc3339($row['created_at']),
		];
	}
}
