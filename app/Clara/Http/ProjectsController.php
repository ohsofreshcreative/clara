<?php

namespace App\Clara\Http;

use App\Clara\Access;
use App\Clara\DesignSystems\DesignSystems;
use App\Clara\Projects\Attachments;
use App\Clara\Projects\ProjectInput;
use App\Clara\Projects\Projects;
use App\Clara\Projects\SectionInput;
use App\Clara\Projects\Sections;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Projekty, sekcje i załączniki. Dostęp tylko dla właściciela projektu; cudzy projekt = 404
 * (nie ujawniamy, że istnieje).
 */
class ProjectsController extends Controller
{
	public function register(): void
	{
		$auth = [Access::class, 'restPermission'];
		$id = self::idArg();

		register_rest_route(self::NS, '/projects', [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'index'],
				'permission_callback' => $auth,
				'args' => [
					'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
					'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 50],
					'search' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
				],
			],
			['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'create'], 'permission_callback' => $auth],
		]);

		register_rest_route(self::NS, '/projects/(?P<id>\d+)', [
			['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'show'], 'permission_callback' => $auth, 'args' => ['id' => $id]],
			['methods' => 'PATCH, PUT', 'callback' => [$this, 'update'], 'permission_callback' => $auth, 'args' => ['id' => $id]],
			['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'destroy'], 'permission_callback' => $auth, 'args' => ['id' => $id]],
		]);

		register_rest_route(self::NS, '/projects/(?P<id>\d+)/sections', [
			['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'sections'], 'permission_callback' => $auth, 'args' => ['id' => $id]],
			['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'createSection'], 'permission_callback' => $auth, 'args' => ['id' => $id]],
		]);

		register_rest_route(self::NS, '/sections/(?P<id>\d+)', [
			['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'showSection'], 'permission_callback' => $auth, 'args' => ['id' => $id]],
			['methods' => 'PATCH, PUT', 'callback' => [$this, 'updateSection'], 'permission_callback' => $auth, 'args' => ['id' => $id]],
			['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'destroySection'], 'permission_callback' => $auth, 'args' => ['id' => $id]],
		]);

		register_rest_route(self::NS, '/section-types', [
			'methods' => WP_REST_Server::READABLE,
			'callback' => fn () => new WP_REST_Response(array_map(
				fn ($value, $label) => ['value' => $value, 'label' => $label],
				array_keys(SectionInput::TYPES),
				SectionInput::TYPES
			)),
			'permission_callback' => $auth,
		]);

		register_rest_route(self::NS, '/projects/(?P<id>\d+)/attachments', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [$this, 'upload'],
			'permission_callback' => $auth,
			'args' => ['id' => $id],
		]);

		register_rest_route(self::NS, '/projects/(?P<id>\d+)/attachments/(?P<attachment>\d+)', [
			'methods' => WP_REST_Server::DELETABLE,
			'callback' => [$this, 'destroyAttachment'],
			'permission_callback' => $auth,
			'args' => ['id' => $id, 'attachment' => $id],
		]);
	}

	/*--- Projekty ---*/

	public function index(WP_REST_Request $request): WP_REST_Response
	{
		$page = (int) $request['page'];
		$perPage = (int) $request['per_page'];
		[$items, $total] = Projects::forOwner(get_current_user_id(), $page, $perPage, (string) $request['search']);

		$versions = DesignSystems::latestVersions(array_column($items, 'id'));
		$items = array_map(fn ($p) => $p + ['design_system_version' => $versions[$p['id']] ?? null], $items);

		$response = new WP_REST_Response($items);
		$response->header('X-WP-Total', (string) $total);
		$response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $perPage)));

		return $response;
	}

	public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$data = ProjectInput::validate($this->body($request));

		if (is_wp_error($data)) {
			return $data;
		}

		$project = Projects::find(Projects::create($data, get_current_user_id()));

		return new WP_REST_Response($project, 201);
	}

	public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		return is_wp_error($project) ? $project : new WP_REST_Response($project + ['sections' => Sections::forProject($project['id'])]);
	}

	public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		$data = ProjectInput::validate($this->body($request), true);

		if (is_wp_error($data)) {
			return $data;
		}

		Projects::update($project['id'], $data);

		return new WP_REST_Response(Projects::find($project['id']));
	}

	public function destroy(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		Projects::delete($project['id']);

		return new WP_REST_Response(['deleted' => true, 'id' => $project['id']]);
	}

	/*--- Sekcje ---*/

	public function sections(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		return is_wp_error($project) ? $project : new WP_REST_Response(Sections::forProject($project['id']));
	}

	public function createSection(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		$data = SectionInput::validate($this->body($request));

		if (is_wp_error($data)) {
			return $data;
		}

		return new WP_REST_Response(Sections::find(Sections::create($project['id'], $data)), 201);
	}

	public function showSection(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$section = $this->ownedSection((int) $request['id']);

		return is_wp_error($section) ? $section : new WP_REST_Response($section);
	}

	public function updateSection(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$section = $this->ownedSection((int) $request['id']);

		if (is_wp_error($section)) {
			return $section;
		}

		$data = SectionInput::validate($this->body($request), true);

		if (is_wp_error($data)) {
			return $data;
		}

		Sections::update($section['id'], $data);

		return new WP_REST_Response(Sections::find($section['id']));
	}

	public function destroySection(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$section = $this->ownedSection((int) $request['id']);

		if (is_wp_error($section)) {
			return $section;
		}

		Sections::delete($section['id']);

		return new WP_REST_Response(['deleted' => true, 'id' => $section['id']]);
	}

	/*--- Załączniki ---*/

	public function upload(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		$attachments = $project['brief']['attachments'] ?? [];

		if (count($attachments) >= 10) {
			return new WP_Error('clara_upload_limit', __('Projekt może mieć maksymalnie 10 załączników.', 'clara'), ['status' => 400]);
		}

		$stored = Attachments::store($project['id'], get_current_user_id(), (string) $request->get_body(), self::filenameFrom($request));

		if (is_wp_error($stored)) {
			return $stored;
		}

		$attachments[] = $stored;
		Projects::setAttachments($project['id'], $attachments);

		return new WP_REST_Response($stored, 201);
	}

	public function destroyAttachment(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		$attachmentId = (int) $request['attachment'];
		$attachments = $project['brief']['attachments'] ?? [];
		$remaining = array_filter($attachments, fn ($a) => (int) $a['id'] !== $attachmentId);

		if (count($remaining) === count($attachments)) {
			return $this->notFound();
		}

		Attachments::deleteFile($attachmentId, $project['id']);
		Projects::setAttachments($project['id'], $remaining);

		return new WP_REST_Response(['deleted' => true, 'id' => $attachmentId]);
	}

	/*--- Pomocnicze ---*/

}
