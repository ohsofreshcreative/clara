<?php

namespace App\Clara\Http;

use App\Clara\Access;
use App\Clara\Creative\ImageJobs;
use App\Clara\Database\Schema;
use App\Clara\DesignSystems\DesignSystems;
use App\Clara\Projects\Projects;
use WP_REST_Response;

/**
 * Dashboard: projekty użytkownika (miniatura, kolory stylu, liczba inspiracji) i ostatnie inspiracje.
 */
class DashboardController extends Controller
{
	private const PROJECTS = 12;

	private const RECENT = 8;

	public function register(): void
	{
		register_rest_route(self::NS, '/dashboard', [
			'methods' => 'GET',
			'callback' => [$this, 'show'],
			'permission_callback' => [Access::class, 'restPermission'],
			'args' => [],
		]);
	}

	public function show(): WP_REST_Response
	{
		global $wpdb;

		$userId = get_current_user_id();
		[$projects, $total] = Projects::forOwner($userId, 1, self::PROJECTS);
		$g = Schema::table('generations');
		$s = Schema::table('sections');
		$p = Schema::table('projects');
		$owned = "FROM {$g} g JOIN {$s} s ON s.id = g.section_id JOIN {$p} p ON p.id = s.project_id WHERE p.owner_user_id = %d AND g.kind = %s AND g.status = 'succeeded' AND g.result_asset_id > 0";

		$counts = [];
		foreach ($wpdb->get_results($wpdb->prepare("SELECT s.project_id AS id, COUNT(*) AS n, MAX(g.id) AS last {$owned} GROUP BY s.project_id", $userId, ImageJobs::KIND), ARRAY_A) as $row) {
			$counts[(int) $row['id']] = ['count' => (int) $row['n'], 'last' => (int) $row['last']];
		}
		$thumbs = [];
		$lastIds = array_column($counts, 'last');
		if ($lastIds) {
			$in = implode(',', array_map('intval', $lastIds));
			foreach ($wpdb->get_results("SELECT id, result_asset_id FROM {$g} WHERE id IN ({$in})", ARRAY_A) as $row) {
				$thumbs[(int) $row['id']] = (int) $row['result_asset_id'];
			}
		}

		$items = array_map(function (array $project) use ($counts, $thumbs) {
			$ds = DesignSystems::latest((int) $project['id']);
			$stats = $counts[$project['id']] ?? ['count' => 0, 'last' => 0];
			$asset = $thumbs[$stats['last']] ?? 0;
			$thumb = $project['brief']['direction']['imageUrl'] ?? ($asset ? wp_get_attachment_image_url($asset, 'medium_large') ?: wp_get_attachment_url($asset) : null);

			return [
				'id' => (int) $project['id'],
				'name' => $project['name'],
				'client_name' => $project['client_name'],
				'updated_at' => $project['updated_at'],
				'inspirations' => $stats['count'],
				'thumbnail' => $thumb ?: null,
				'colors' => array_slice(array_column($ds['tokens']['colors'] ?? [], 'value'), 0, 3),
				'fonts' => array_values(array_slice(array_unique(array_filter(array_column($ds['tokens']['typography'] ?? [], 'fontFamily'))), 0, 2)),
				'hasDirection' => ! empty($project['brief']['direction']),
			];
		}, $projects);

		$recent = [];
		$rows = $wpdb->get_results($wpdb->prepare("SELECT g.id, g.result_asset_id, g.prompt_json, g.created_at, s.project_id, p.name AS project_name {$owned} ORDER BY g.id DESC LIMIT %d", $userId, ImageJobs::KIND, self::RECENT), ARRAY_A);
		foreach ($rows as $row) {
			$url = wp_get_attachment_image_url((int) $row['result_asset_id'], 'medium_large') ?: wp_get_attachment_url((int) $row['result_asset_id']);
			if (! $url) {
				continue;
			}
			$meta = json_decode((string) $row['prompt_json'], true) ?: [];
			$recent[] = [
				'id' => (int) $row['id'],
				'projectId' => (int) $row['project_id'],
				'projectName' => $row['project_name'],
				'title' => (string) ($meta['title'] ?? ''),
				'imageUrl' => $url,
				'created_at' => mysql_to_rfc3339($row['created_at']),
			];
		}

		$all = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$owned}", $userId, ImageJobs::KIND));

		return new WP_REST_Response([
			'stats' => [
				'projects' => (int) $total,
				'inspirations' => $all,
				'styled' => count(array_filter($items, fn ($i) => $i['colors'] || $i['fonts'])),
			],
			'projects' => $items,
			'recent' => $recent,
		]);
	}
}
