<?php

namespace App\Clara\Creative;

use App\Clara\Database\Schema;
use App\Clara\Inspirations\Inspirations;
use App\Clara\Projects\Sections;
use WP_Error;

/**
 * Galeria wariantów sekcji, wybór, historia wersji i Remix.
 *
 * Remix zawsze tworzy NOWĄ generację (parent_id = obraz źródłowy) z nowym jobem; rodzic i jego
 * plik pozostają nietknięte. Referencje z kolekcji trafiają do `generation_sources` (role=reference)
 * oraz jako migawka do prompt_json — historia zostaje czytelna nawet po usunięciu inspiracji.
 * Do dostawcy obrazu przekazujemy tylko opis referencji (tytuł, autor, źródło), nigdy cudzy plik.
 */
class Gallery
{
	public const MAX_REFERENCES = 5;

	public const MAX_VERSION = 20;

	public static function find(int $id): ?array
	{
		global $wpdb;

		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('generations') . ' WHERE id = %d AND kind = %s', $id, ImageJobs::KIND), ARRAY_A);

		return $row ?: null;
	}

	/**
	 * Wszystkie warianty obrazów sekcji (oryginały i remiksy), najnowsze pierwsze.
	 */
	public static function forSection(array $section): array
	{
		$items = [];
		foreach (ImageJobs::batches($section['id'], 50) as $batch) {
			foreach ($batch['items'] as $item) {
				$items[] = $item + ['batchId' => $batch['batchId'], 'mock' => $batch['mock'], 'provider' => $batch['provider'], 'model' => $batch['model']];
			}
		}
		usort($items, fn ($a, $b) => $b['id'] <=> $a['id']);

		$referenced = [];
		foreach ($items as $item) {
			array_push($referenced, ...array_column($item['sources'], 'inspirationId'));
		}
		$available = self::availableSources($referenced);
		foreach ($items as &$item) {
			$item['sources'] = array_map(fn ($s) => $s + ['available' => isset($available[$s['inspirationId']])], $item['sources']);
		}

		$selected = $section['selected_generation_id'] ?? null;

		return [
			'selectedId' => $selected && in_array($selected, array_column($items, 'id'), true) ? $selected : null,
			'items' => $items,
		];
	}

	/**
	 * Linia wersji: od oryginału (root) przez wszystkie remiksy, z kierunkiem artystycznym źródła.
	 */
	public static function lineage(array $section, int $id): array|WP_Error
	{
		$all = self::forSection($section)['items'];
		$byId = array_column($all, null, 'id');

		if (! isset($byId[$id])) {
			return new WP_Error('clara_not_found', __('Nie znaleziono.', 'clara'), ['status' => 404]);
		}

		$rootId = $byId[$id]['rootId'];
		$versions = array_values(array_filter($all, fn ($i) => $i['rootId'] === $rootId));
		usort($versions, fn ($a, $b) => $a['id'] <=> $b['id']);

		$ancestors = [];
		for ($cursor = $byId[$id]['remixOf']; $cursor && isset($byId[$cursor]); $cursor = $byId[$cursor]['remixOf']) {
			array_unshift($ancestors, $cursor);
		}

		$root = $byId[$rootId] ?? $versions[0];
		global $wpdb;
		$direction = $wpdb->get_row($wpdb->prepare('SELECT id, art_direction_json, prompt_json FROM ' . Schema::table('generations') . " WHERE id = %d AND kind = 'art_direction'", $root['parent_id']), ARRAY_A);

		return [
			'id' => $id,
			'rootId' => $rootId,
			'path' => [...$ancestors, $id],
			'versions' => $versions,
			'direction' => $direction ? ['id' => (int) $direction['id']] + (json_decode((string) $direction['art_direction_json'], true) ?: []) : null,
		];
	}

	/**
	 * @param int[] $references id inspiracji z kolekcji projektu
	 */
	public static function remix(array $project, array $section, array $source, string $instruction, array $references): array|WP_Error
	{
		$provider = ImageJobs::provider();
		if (! $provider) {
			return new WP_Error('clara_image_unconfigured', __('Generator obrazów nie jest skonfigurowany na serwerze.', 'clara'), ['status' => 409]);
		}
		if ($source['status'] !== 'succeeded') {
			return new WP_Error('clara_remix_source', __('Remiks jest możliwy tylko dla gotowego obrazu.', 'clara'), ['status' => 409]);
		}

		$meta = json_decode((string) $source['prompt_json'], true) ?: [];
		$version = (int) ($meta['version'] ?? 1) + 1;
		if ($version > self::MAX_VERSION) {
			return new WP_Error('clara_remix_depth', sprintf(__('Osiągnięto limit %d wersji w jednej linii — zacznij od wcześniejszej wersji.', 'clara'), self::MAX_VERSION), ['status' => 409]);
		}

		$snapshots = [];
		foreach ($references as $inspirationId) {
			$inspiration = Inspirations::find($inspirationId);
			if (! $inspiration || $inspiration['project_id'] !== $project['id']) {
				return new WP_Error('clara_invalid', __('Nieprawidłowe dane.', 'clara'), ['status' => 400, 'errors' => ['references' => 'Referencje muszą pochodzić z kolekcji tego projektu.']]);
			}
			$snapshots[] = [
				'inspirationId' => $inspiration['id'],
				'role' => 'reference',
				'title' => $inspiration['attribution']['title'] ?? '',
				'creator' => $inspiration['attribution']['creator'] ?? '',
				'sourceUrl' => $inspiration['source_url'],
				'thumbnailUrl' => $inspiration['thumbnail_url'],
				'license' => $inspiration['rights']['license']['name'] ?? null,
			];
		}

		if ($error = ImageJobs::guard($provider, $section['id'], 1)) {
			return $error;
		}

		$direction = (json_decode((string) $source['art_direction_json'], true) ?: []);
		$direction['remixVersion'] = $version;
		$batchId = wp_generate_uuid4();

		$id = ImageJobs::insertQueued($provider, $section['id'], (int) $source['id'], $direction, [
			'batchId' => $batchId,
			// Remiks nie należy do partii kierunków (artRunId), żeby nie zastąpić galerii 4 wariantów.
			'artRunId' => null,
			'sourceRunId' => $meta['artRunId'] ?? $meta['sourceRunId'] ?? null,
			'directionKey' => $meta['directionKey'] ?? '',
			'title' => $meta['title'] ?? '',
			'remixOf' => (int) $source['id'],
			'rootId' => (int) ($meta['rootId'] ?? $source['id']),
			'version' => $version,
			'remixPrompt' => $instruction,
			'parentPrompt' => $meta['prompt'] ?? '',
			'prompt' => ImagePrompt::remix((string) ($meta['prompt'] ?? ''), $instruction, $snapshots),
			'sources' => $snapshots,
			'designSystem' => $meta['designSystem'] ?? null,
			'projectId' => $project['id'],
			'createdBy' => get_current_user_id(),
			'mock' => $provider->isMock(),
		], current_time('mysql', true));

		global $wpdb;
		foreach ($snapshots as $snapshot) {
			$wpdb->insert(Schema::table('generation_sources'), ['generation_id' => $id, 'inspiration_id' => $snapshot['inspirationId'], 'role' => 'reference']);
		}

		ImageJobs::schedule();

		return ImageJobs::batch($section['id'], $batchId);
	}

	public static function select(array $section, ?int $generationId): array|WP_Error
	{
		if ($generationId !== null) {
			$generation = self::find($generationId);
			if (! $generation || (int) $generation['section_id'] !== $section['id']) {
				return new WP_Error('clara_invalid', __('Nieprawidłowe dane.', 'clara'), ['status' => 400, 'errors' => ['generationId' => 'Wariant nie należy do tej sekcji.']]);
			}
			if ($generation['status'] !== 'succeeded') {
				return new WP_Error('clara_select_status', __('Wybrać można tylko gotowy obraz.', 'clara'), ['status' => 409]);
			}
		}

		Sections::select($section['id'], $generationId);

		return ['selectedId' => $generationId];
	}

	/** Usuwa powiązania źródeł dla generacji (przy kasowaniu sekcji/projektu). */
	public static function deleteSources(array $generationIds): void
	{
		global $wpdb;

		$ids = array_filter(array_map('intval', $generationIds));
		if ($ids) {
			$wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('generation_sources') . ' WHERE generation_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')', ...$ids));
		}
	}

	/** @return array<int, true> */
	private static function availableSources(array $ids): array
	{
		global $wpdb;

		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if (! $ids) {
			return [];
		}

		$found = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::table('inspirations') . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')', ...$ids));

		return array_fill_keys(array_map('intval', $found), true);
	}
}
