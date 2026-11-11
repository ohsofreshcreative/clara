<?php

namespace App\Clara\Http;

use App\Clara\Access;
use App\Clara\Creative\ImageJobs;
use App\Clara\Creative\InspirationGenerator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Dashboard: POST /generate (4 szkice w kolejce) → POST /generate/{id}/render (jeden wariant,
 * klient woła równolegle), POST /generate/{id}/refine (szkic → wyższa jakość) oraz kierunek projektu: PUT/DELETE /projects/{id}/direction.
 */
class GenerateController extends Controller
{
	public function register(): void
	{
		register_rest_route(self::NS, '/generate', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [$this, 'start'],
			'permission_callback' => [Access::class, 'restPermission'],
		]);

		register_rest_route(self::NS, '/generate/(?P<id>\d+)/render', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [$this, 'render'],
			'permission_callback' => [Access::class, 'restPermission'],
			'args' => ['id' => self::idArg()],
		]);

		register_rest_route(self::NS, '/generate/(?P<id>\d+)/refine', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => function (WP_REST_Request $request) {
				$result = InspirationGenerator::refine((int) $request['id'], get_current_user_id());
				return is_wp_error($result) ? $result : new WP_REST_Response($result, 201);
			},
			'permission_callback' => [Access::class, 'restPermission'],
			'args' => ['id' => self::idArg()],
		]);

		register_rest_route(self::NS, '/generate/status', [
			'methods' => WP_REST_Server::READABLE,
			'callback' => function () {
				$provider = ImageJobs::provider();
				return new WP_REST_Response(['configured' => $provider && ! $provider->isMock(), 'provider' => $provider?->id(), 'model' => $provider?->model()]);
			},
			'permission_callback' => [Access::class, 'restPermission'],
		]);

		register_rest_route(self::NS, '/projects/(?P<id>\d+)/direction', [
			[
				'methods' => 'PUT',
				'callback' => [$this, 'setDirection'],
				'permission_callback' => [Access::class, 'restPermission'],
				'args' => ['id' => self::idArg()],
			],
			[
				'methods' => WP_REST_Server::DELETABLE,
				'callback' => [$this, 'clearDirection'],
				'permission_callback' => [Access::class, 'restPermission'],
				'args' => ['id' => self::idArg()],
			],
		]);
	}

	public function start(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$result = InspirationGenerator::start($this->body($request), get_current_user_id());

		return is_wp_error($result) ? $result : new WP_REST_Response($result, 201);
	}

	public function render(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		// Jeden obraz u dostawcy trwa zwykle 20–90 s.
		if (function_exists('set_time_limit')) {
			@set_time_limit(300);
		}
		// Zmniejszanie screenów referencyjnych (GD/Imagick) i dekodowanie obrazu z API potrzebują pamięci
		// jak przy obróbce obrazów w WP — domyślny limit dla żądań REST (WP_MEMORY_LIMIT) bywa za mały.
		wp_raise_memory_limit('image');

		$result = InspirationGenerator::render((int) $request['id'], get_current_user_id());

		return is_wp_error($result) ? $result : new WP_REST_Response($result);
	}

	public function setDirection(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);
		$generationId = (int) ($this->body($request)['generationId'] ?? 0);
		if (is_wp_error($project)) {
			return $project;
		}
		if ($generationId < 1) {
			return new WP_Error('clara_invalid', __('Wskaż wariant (generationId).', 'clara'), ['status' => 400]);
		}
		$result = InspirationGenerator::setDirection($project, $generationId);

		return is_wp_error($result) ? $result : new WP_REST_Response($result);
	}

	public function clearDirection(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		return is_wp_error($project) ? $project : new WP_REST_Response(InspirationGenerator::setDirection($project, null));
	}
}
