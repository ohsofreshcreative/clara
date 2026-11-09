<?php

namespace App\Clara\Http;

use App\Clara\Access;
use App\Clara\Creative\ArtDirectionSchema;
use App\Clara\Creative\ArtDirector;
use App\Clara\Creative\Generations;
use App\Clara\Support\Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Creative Brief + Art Direction: 4 kierunki dla sekcji, historia uruchomień, status dostawcy LLM.
 */
class ArtDirectionsController extends Controller
{
	/** Odstęp między płatnymi wywołaniami LLM jednego użytkownika (limit kosztów PoC). */
	private const COOLDOWN = 20;

	public function register(): void
	{
		$auth = [Access::class, 'restPermission'];

		register_rest_route(self::NS, '/sections/(?P<id>\d+)/art-directions', [
			['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'index'], 'permission_callback' => $auth, 'args' => ['id' => self::idArg()]],
			['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'create'], 'permission_callback' => $auth, 'args' => ['id' => self::idArg()]],
		]);

		register_rest_route(self::NS, '/llm/status', [
			'methods' => WP_REST_Server::READABLE,
			'callback' => function () {
				$provider = ArtDirector::provider();
				return new WP_REST_Response([
					'configured' => (bool) $provider,
					'provider' => $provider?->id(),
					'model' => $provider?->model(),
					'layouts' => ArtDirectionSchema::LAYOUTS,
				]);
			},
			'permission_callback' => $auth,
		]);
	}

	public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$section = $this->ownedSection((int) $request['id']);

		return is_wp_error($section) ? $section : new WP_REST_Response(Generations::runs($section['id']));
	}

	public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$section = $this->ownedSection((int) $request['id']);

		if (is_wp_error($section)) {
			return $section;
		}

		if ($section['status'] === 'archived') {
			return new WP_Error('clara_section_archived', __('Sekcja jest zarchiwizowana — przywróć ją, aby generować kierunki.', 'clara'), ['status' => 409]);
		}

		$v = new Validator();
		$prompt = $v->text('prompt', $this->body($request)['prompt'] ?? '', 2000, false, true);

		if (! $v->ok()) {
			return $v->error();
		}

		if (ArtDirector::provider()) {
			$lock = 'clara_llm_' . get_current_user_id();
			if (get_transient($lock)) {
				return new WP_Error('clara_llm_cooldown', sprintf(__('Odczekaj %d s przed kolejnym generowaniem.', 'clara'), self::COOLDOWN), ['status' => 429]);
			}
			set_transient($lock, 1, self::COOLDOWN);
		}

		$run = ArtDirector::run($this->ownedProject($section['project_id']), $section, $prompt);

		return is_wp_error($run) ? $run : new WP_REST_Response($run, 201);
	}
}
