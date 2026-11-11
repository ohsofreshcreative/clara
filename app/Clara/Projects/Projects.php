<?php

namespace App\Clara\Projects;

use App\Clara\Database\Schema;
use App\Clara\Creative\Generations;
use App\Clara\DesignSystems\DesignSystems;
use App\Clara\Inspirations\Inspirations;

/**
 * Dostęp do clara_projects. Zawsze przez $wpdb->prepare; kontrola właściciela w kontrolerach.
 */
class Projects
{
	public static function find(int $id): ?array
	{
		global $wpdb;

		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('projects') . ' WHERE id = %d', $id), ARRAY_A);

		return $row ? self::hydrate($row) : null;
	}

	/**
	 * @return array{0: array<int, array>, 1: int}
	 */
	public static function forOwner(int $ownerId, int $page, int $perPage, string $search = ''): array
	{
		global $wpdb;

		$table = Schema::table('projects');
		$where = $wpdb->prepare('owner_user_id = %d', $ownerId);

		if ($search !== '') {
			$like = '%' . $wpdb->esc_like($search) . '%';
			$where .= $wpdb->prepare(' AND (name LIKE %s OR client_name LIKE %s)', $like, $like);
		}

		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
		$rows = $wpdb->get_results(
			$wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $perPage, ($page - 1) * $perPage),
			ARRAY_A
		);

		return [array_map([self::class, 'hydrate'], $rows ?: []), $total];
	}

	public static function create(array $data, int $ownerId): int
	{
		global $wpdb;

		$now = current_time('mysql', true);
		$brief = ($data['brief'] ?? []) + ['attachments' => []];

		$wpdb->insert(Schema::table('projects'), [
			'owner_user_id' => $ownerId,
			'name' => $data['name'],
			'client_name' => $data['client_name'] ?? '',
			'industry' => $data['industry'] ?? '',
			'website_url' => $data['website_url'] ?? '',
			'figma_url' => $data['figma_url'] ?? '',
			'brief_json' => wp_json_encode($brief),
			'created_at' => $now,
			'updated_at' => $now,
		]);

		return (int) $wpdb->insert_id;
	}

	public static function update(int $id, array $data): void
	{
		global $wpdb;

		$columns = array_intersect_key($data, array_flip(['name', 'client_name', 'industry', 'website_url', 'figma_url']));

		if (array_key_exists('brief', $data)) {
			// Załączniki i kierunek projektu zarządzane osobno — zachowujemy je przy edycji briefu.
			$current = self::find($id);
			$keep = ['attachments' => $current['brief']['attachments'] ?? []] + array_intersect_key($current['brief'] ?? [], array_flip(['direction', ...self::FIGMA_LINKS]));
			$columns['brief_json'] = wp_json_encode(array_diff_key($data['brief'], $keep) + $keep);
		}

		$columns['updated_at'] = current_time('mysql', true);
		$wpdb->update(Schema::table('projects'), $columns, ['id' => $id]);
	}

	/** Kierunek projektu (wybrany wariant, którego stylu trzymają się kolejne generowania); null = brak. */
	public const FIGMA_LINKS = ['colorsUrl', 'typographyUrl'];

	/** Link do ramki kolorów / typografii w Figmie (pola poza formularzem briefu). */
	public static function setFigmaLink(int $id, string $key, string $url): void
	{
		global $wpdb;

		$brief = self::find($id)['brief'] ?? [];
		$brief[$key] = $url;
		$wpdb->update(Schema::table('projects'), [
			'brief_json' => wp_json_encode($brief),
			'updated_at' => current_time('mysql', true),
		], ['id' => $id]);
	}

	public static function setDirection(int $id, ?array $direction): void
	{
		global $wpdb;

		$brief = self::find($id)['brief'] ?? [];
		if ($direction) {
			$brief['direction'] = $direction;
		} else {
			unset($brief['direction']);
		}

		$wpdb->update(Schema::table('projects'), [
			'brief_json' => wp_json_encode($brief),
			'updated_at' => current_time('mysql', true),
		], ['id' => $id]);
	}

	public static function setAttachments(int $id, array $attachments): void
	{
		global $wpdb;

		$project = self::find($id);
		$brief = ['attachments' => array_values($attachments)] + ($project['brief'] ?? []);

		$wpdb->update(Schema::table('projects'), [
			'brief_json' => wp_json_encode($brief),
			'updated_at' => current_time('mysql', true),
		], ['id' => $id]);
	}

	/**
	 * Usuwa projekt z sekcjami i załącznikami projektu (spójność relacji po stronie aplikacji).
	 */
	public static function delete(int $id): void
	{
		global $wpdb;

		// Wszystkie pliki projektu (załączniki briefu i screeny design systemu, także ze starszych wersji).
		$files = get_posts([
			'post_type' => 'attachment',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields' => 'ids',
			'meta_key' => Attachments::META,
			'meta_value' => $id,
		]);

		foreach ($files as $attachmentId) {
			Attachments::deleteFile((int) $attachmentId, $id);
		}

		DesignSystems::deleteForProject($id);
		Inspirations::deleteForProject($id);
		Generations::deleteForSections(array_column(Sections::forProject($id), 'id'));
		$wpdb->delete(Schema::table('sections'), ['project_id' => $id]);
		$wpdb->delete(Schema::table('projects'), ['id' => $id]);
	}

	private static function hydrate(array $row): array
	{
		$brief = json_decode((string) $row['brief_json'], true);

		return [
			'id' => (int) $row['id'],
			'owner_user_id' => (int) $row['owner_user_id'],
			'name' => $row['name'],
			'client_name' => $row['client_name'],
			'industry' => $row['industry'],
			'website_url' => $row['website_url'],
			'figma_url' => $row['figma_url'],
			'brief' => is_array($brief) ? $brief : ['attachments' => []],
			'created_at' => mysql_to_rfc3339($row['created_at']),
			'updated_at' => mysql_to_rfc3339($row['updated_at']),
		];
	}
}
