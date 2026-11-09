<?php

namespace App\Clara\Http;

use App\Clara\Projects\Projects;
use App\Clara\Projects\Sections;
use WP_Error;
use WP_REST_Request;

/**
 * Wspólne helpery kontrolerów: dostęp do projektu tylko dla właściciela (cudzy = 404).
 */
abstract class Controller
{
	protected const NS = StatusController::NAMESPACE;

	protected function body(WP_REST_Request $request): array
	{
		return $request->get_json_params() ?: $request->get_body_params() ?: [];
	}

	protected function ownedProject(int $id): array|WP_Error
	{
		$project = Projects::find($id);

		if (! $project || $project['owner_user_id'] !== get_current_user_id()) {
			return $this->notFound();
		}

		return $project;
	}

	protected function ownedSection(int $id): array|WP_Error
	{
		$section = Sections::find($id);

		if (! $section || is_wp_error($this->ownedProject($section['project_id']))) {
			return $this->notFound();
		}

		return $section;
	}

	protected function notFound(): WP_Error
	{
		return new WP_Error('clara_not_found', __('Nie znaleziono.', 'clara'), ['status' => 404]);
	}

	protected static function idArg(): array
	{
		return ['validate_callback' => fn ($v) => is_numeric($v) && (int) $v > 0];
	}

	protected static function filenameFrom(WP_REST_Request $request): string
	{
		$disposition = (string) $request->get_header('content_disposition');

		return preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $m) ? rawurldecode($m[1]) : '';
	}
}
