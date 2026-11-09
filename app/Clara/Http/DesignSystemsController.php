<?php

namespace App\Clara\Http;

use App\Clara\Access;
use App\Clara\DesignSystems\DesignSystems;
use App\Clara\DesignSystems\FigmaClient;
use App\Clara\DesignSystems\JsonImporter;
use App\Clara\DesignSystems\TokenSchema;
use App\Clara\Projects\Attachments;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Design System Manager: ręczne tokeny, import JSON, Figma REST (style), screeny referencyjne,
 * historia wersji i przywracanie. Każda operacja zapisu tworzy nową wersję.
 */
class DesignSystemsController extends Controller
{
	private const FIGMA_COOLDOWN = 30;

	private const MAX_ASSETS = 12;

	public function register(): void
	{
		$auth = [Access::class, 'restPermission'];
		$id = self::idArg();
		$route = fn (string $path, string $methods, string $callback) => register_rest_route(self::NS, $path, [
			'methods' => $methods,
			'callback' => [$this, $callback],
			'permission_callback' => $auth,
			'args' => ['id' => $id],
		]);

		$route('/projects/(?P<id>\d+)/design-systems', WP_REST_Server::READABLE, 'versions');
		$route('/projects/(?P<id>\d+)/design-systems', WP_REST_Server::CREATABLE, 'saveManual');
		$route('/projects/(?P<id>\d+)/design-systems/latest', WP_REST_Server::READABLE, 'latest');
		$route('/projects/(?P<id>\d+)/design-systems/import', WP_REST_Server::CREATABLE, 'importJson');
		$route('/projects/(?P<id>\d+)/design-systems/figma', WP_REST_Server::CREATABLE, 'ingestFigma');
		$route('/projects/(?P<id>\d+)/design-systems/screens', WP_REST_Server::CREATABLE, 'addScreen');
		$route('/design-systems/(?P<id>\d+)', WP_REST_Server::READABLE, 'show');
		$route('/design-systems/(?P<id>\d+)/restore', WP_REST_Server::CREATABLE, 'restore');

		register_rest_route(self::NS, '/projects/(?P<id>\d+)/design-systems/screens/(?P<asset>\d+)', [
			'methods' => WP_REST_Server::DELETABLE,
			'callback' => [$this, 'removeScreen'],
			'permission_callback' => $auth,
			'args' => ['id' => $id, 'asset' => $id],
		]);

		register_rest_route(self::NS, '/figma/status', [
			'methods' => WP_REST_Server::READABLE,
			'callback' => fn () => new WP_REST_Response([
				'configured' => FigmaClient::configured(),
				'capabilities' => ['styles' => true, 'variables' => false],
				'note' => 'Zmienne Figmy przez REST wymagają planu Enterprise — użyj importu JSON (W3C Design Tokens).',
			]),
			'permission_callback' => $auth,
		]);
	}

	public function versions(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		return is_wp_error($project) ? $project : new WP_REST_Response(DesignSystems::versions($project['id']));
	}

	public function latest(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		return new WP_REST_Response(DesignSystems::latest($project['id']));
	}

	public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$ds = $this->ownedVersion((int) $request['id']);

		return is_wp_error($ds) ? $ds : new WP_REST_Response($ds);
	}

	public function saveManual(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		$body = $this->body($request);
		$tokens = TokenSchema::validate($body['tokens'] ?? null);

		if (is_wp_error($tokens)) {
			return $tokens;
		}

		return $this->store($project['id'], $tokens, null, [
			'type' => 'manual',
			'note' => sanitize_text_field(mb_substr((string) ($body['note'] ?? ''), 0, 200)),
		]);
	}

	public function importJson(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		$body = $this->body($request);
		$parsed = JsonImporter::parse(is_string($body['content'] ?? null) ? $body['content'] : '');

		if (is_wp_error($parsed)) {
			return $parsed;
		}

		$tokens = TokenSchema::validate($parsed['tokens']);

		if (is_wp_error($tokens)) {
			// Czytelny błąd: ścieżka tokenu → co jest nie tak.
			return new WP_Error('clara_import_invalid', __('Plik ma błędne tokeny — popraw wskazane pozycje i zaimportuj ponownie.', 'clara'), $tokens->get_error_data());
		}

		if (! array_filter($tokens)) {
			return new WP_Error('clara_import_empty', __('Plik nie zawiera żadnych obsługiwanych tokenów.', 'clara'), ['status' => 400, 'warnings' => $parsed['warnings']]);
		}

		return $this->store($project['id'], $tokens, null, [
			'type' => 'json_import',
			'format' => $parsed['format'],
			'filename' => sanitize_file_name((string) ($body['filename'] ?? '')),
			'warnings' => array_slice($parsed['warnings'], 0, 50),
		]);
	}

	public function ingestFigma(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		$fileKey = $project['figma_url'] !== '' ? FigmaClient::fileKey($project['figma_url']) : null;

		if (! $fileKey) {
			return new WP_Error('clara_figma_no_file', __('Projekt nie ma poprawnego linku do pliku Figma. Uzupełnij go w danych projektu.', 'clara'), ['status' => 400]);
		}

		// Limit Figmy na planie Pro to 15 zapytań/min (Tier 1) — chronimy go lokalnym odstępem.
		$lock = 'clara_figma_' . $project['id'];
		if (get_transient($lock)) {
			return new WP_Error('clara_figma_cooldown', sprintf(__('Odczekaj %d s przed kolejnym pobraniem z Figmy.', 'clara'), self::FIGMA_COOLDOWN), ['status' => 429]);
		}
		set_transient($lock, 1, self::FIGMA_COOLDOWN);

		$result = FigmaClient::ingest($fileKey);

		if (is_wp_error($result)) {
			return $result;
		}

		$tokens = TokenSchema::validate($result['tokens']);

		if (is_wp_error($tokens)) {
			return new WP_Error('clara_figma_invalid', __('Style z Figmy zawierają wartości, których nie da się zapisać.', 'clara'), $tokens->get_error_data());
		}

		return $this->store($project['id'], $tokens, null, ['type' => 'figma_rest', 'warnings' => array_slice($result['warnings'], 0, 50)] + $result['meta']);
	}

	public function addScreen(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		$latest = DesignSystems::latest($project['id']);
		$assets = $latest['assets'] ?? [];

		if (count($assets) >= self::MAX_ASSETS) {
			return new WP_Error('clara_upload_limit', sprintf(__('Maksymalnie %d screenów referencyjnych.', 'clara'), self::MAX_ASSETS), ['status' => 400]);
		}

		$stored = Attachments::store($project['id'], get_current_user_id(), (string) $request->get_body(), self::filenameFrom($request));

		if (is_wp_error($stored)) {
			return $stored;
		}

		if ($stored['mime'] === 'application/pdf') {
			Attachments::deleteFile($stored['id'], $project['id']);
			return new WP_Error('clara_upload_type', __('Screen referencyjny musi być obrazem (JPG, PNG, WebP).', 'clara'), ['status' => 415]);
		}

		$assets[] = ['role' => 'reference_screen'] + $stored;

		return $this->store($project['id'], $latest['tokens'] ?? array_fill_keys(TokenSchema::GROUPS, []), $assets, [
			'type' => 'reference_screens',
			'note' => 'Dodano screen: ' . $stored['name'],
		]);
	}

	/**
	 * Usunięcie screena tworzy nową wersję bez niego; plik zostaje dla historii starszych wersji.
	 */
	public function removeScreen(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		$latest = DesignSystems::latest($project['id']);
		$assetId = (int) $request['asset'];
		$assets = array_filter($latest['assets'] ?? [], fn ($a) => (int) ($a['id'] ?? 0) !== $assetId);

		if (! $latest || count($assets) === count($latest['assets'])) {
			return $this->notFound();
		}

		return $this->store($project['id'], $latest['tokens'], $assets, ['type' => 'reference_screens', 'note' => "Usunięto screen #{$assetId}"]);
	}

	public function restore(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$ds = $this->ownedVersion((int) $request['id']);

		if (is_wp_error($ds)) {
			return $ds;
		}

		return $this->store($ds['project_id'], $ds['tokens'], $ds['assets'], [
			'type' => 'restore',
			'restoredFrom' => $ds['version'],
			'note' => "Przywrócono wersję v{$ds['version']}",
		]);
	}

	/**
	 * @param array|null $assets null = przenieś screeny z poprzedniej wersji
	 */
	private function store(int $projectId, array $tokens, ?array $assets, array $source): WP_REST_Response
	{
		$assets ??= DesignSystems::latest($projectId)['assets'] ?? [];

		return new WP_REST_Response(DesignSystems::createVersion($projectId, $tokens, $assets, $source), 201);
	}

	private function ownedVersion(int $id): array|WP_Error
	{
		$ds = DesignSystems::find($id);

		if (! $ds || is_wp_error($this->ownedProject($ds['project_id']))) {
			return $this->notFound();
		}

		return $ds;
	}
}
