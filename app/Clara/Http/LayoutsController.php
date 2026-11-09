<?php

namespace App\Clara\Http;

use App\Clara\Access;
use App\Clara\Creative\Gallery;
use App\Clara\Creative\Generations;
use App\Clara\Layout\HeuristicLayoutAnalyzer;
use App\Clara\Layout\LayoutDocuments;
use App\Clara\Layout\LayoutInterpreter;
use App\Clara\Layout\LayoutSchema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Visual-to-Layout JSON: analiza wariantu, wersje dokumentów, ręczne poprawki (nowa wersja), walidator.
 */
class LayoutsController extends Controller
{
	private const COOLDOWN = 20;

	public function register(): void
	{
		$auth = [Access::class, 'restPermission'];
		$id = ['id' => self::idArg()];

		register_rest_route(self::NS, '/images/(?P<id>\d+)/layouts', [
			['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'index'], 'permission_callback' => $auth, 'args' => $id],
			['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'create'], 'permission_callback' => $auth, 'args' => $id],
		]);

		register_rest_route(self::NS, '/layouts/(?P<id>\d+)', [
			'methods' => WP_REST_Server::READABLE,
			'callback' => [$this, 'show'],
			'permission_callback' => $auth,
			'args' => $id,
		]);

		register_rest_route(self::NS, '/layouts/validate', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [$this, 'validate'],
			'permission_callback' => $auth,
		]);

		register_rest_route(self::NS, '/layouts/status', [
			'methods' => WP_REST_Server::READABLE,
			'callback' => function () {
				$analyzer = LayoutInterpreter::analyzer();
				return new WP_REST_Response(['analyzer' => $analyzer->id(), 'model' => $analyzer->model(), 'heuristic' => $analyzer instanceof HeuristicLayoutAnalyzer, 'schemaVersion' => LayoutSchema::VERSION]);
			},
			'permission_callback' => $auth,
		]);
	}

	public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$generation = $this->ownedImage((int) $request['id']);

		return $generation ? new WP_REST_Response(LayoutDocuments::forGeneration((int) $generation['id'])) : $this->notFound();
	}

	public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$generation = $this->ownedImage((int) $request['id']);

		if (! $generation) {
			return $this->notFound();
		}

		$body = $this->body($request);

		if (array_key_exists('layout', $body)) {
			$basedOn = isset($body['basedOn']) && is_int($body['basedOn']) ? $body['basedOn'] : null;
			if ($basedOn && (LayoutDocuments::find($basedOn)['generation_id'] ?? null) !== (int) $generation['id']) {
				return LayoutSchema::invalid(['basedOn' => 'Wersja bazowa nie należy do tego wariantu.']);
			}
			$doc = LayoutInterpreter::saveManual($generation, $body['layout'], $basedOn);
		} else {
			// Limit tylko dla płatnego analizatora; heurystyka jest lokalna i darmowa.
			$paid = ! LayoutInterpreter::analyzer() instanceof HeuristicLayoutAnalyzer;
			$key = 'clara_layout_cd_' . get_current_user_id();
			if ($paid && get_transient($key)) {
				return new WP_Error('clara_layout_cooldown', sprintf(__('Odczekaj %d s przed kolejną analizą.', 'clara'), self::COOLDOWN), ['status' => 429]);
			}
			if ($paid) {
				set_transient($key, 1, self::COOLDOWN);
			}
			$doc = LayoutInterpreter::analyze($generation);
		}

		if (is_wp_error($doc)) {
			return $doc;
		}

		return new WP_REST_Response($doc + ['assets' => LayoutInterpreter::assets($generation, $doc['layout'])], 201);
	}

	public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$doc = LayoutDocuments::find((int) $request['id']);
		$generation = $doc ? Generations::find((int) $doc['generation_id'], LayoutDocuments::OWNER_KINDS) : null;
		$owned = $generation && ! is_wp_error($this->ownedSection((int) $generation['section_id']));

		return $owned ? new WP_REST_Response($doc + ['assets' => LayoutInterpreter::assets($generation, $doc['layout'])]) : $this->notFound();
	}

	/** Walidacja bez zapisu (np. przed importem do Figmy lub po ręcznej edycji). */
	public function validate(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$layout = $this->body($request)['layout'] ?? null;

		if (is_string($layout)) {
			$layout = LayoutSchema::parse($layout);
			if (is_wp_error($layout)) {
				return new WP_REST_Response(['valid' => false, 'errors' => $layout->get_error_data()['errors'], 'warnings' => [], 'summary' => []]);
			}
		}

		$check = LayoutSchema::validate($layout);

		return new WP_REST_Response(['valid' => ! $check['errors']] + $check);
	}

	private function ownedImage(int $id): ?array
	{
		$generation = Gallery::find($id);

		return $generation && ! is_wp_error($this->ownedSection((int) $generation['section_id'])) ? $generation : null;
	}
}
