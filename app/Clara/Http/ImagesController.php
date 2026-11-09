<?php

namespace App\Clara\Http;

use App\Clara\Access;
use App\Clara\Creative\ArtDirectionSchema;
use App\Clara\Creative\ImageJobs;
use App\Clara\Support\Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * AI Visual Generation: zlecenie partii (do 4 obrazów z kierunków artystycznych), statusy, anulowanie.
 */
class ImagesController extends Controller
{
	public function register(): void
	{
		$auth = [Access::class, 'restPermission'];
		$id = ['id' => self::idArg()];

		register_rest_route(self::NS, '/sections/(?P<id>\d+)/images', [
			['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'index'], 'permission_callback' => $auth, 'args' => $id],
			['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'create'], 'permission_callback' => $auth, 'args' => $id],
		]);

		register_rest_route(self::NS, '/sections/(?P<id>\d+)/images/(?P<batch>[0-9a-f-]{36})/cancel', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [$this, 'cancel'],
			'permission_callback' => $auth,
			'args' => $id,
		]);

		register_rest_route(self::NS, '/images/status', [
			'methods' => WP_REST_Server::READABLE,
			'callback' => function () {
				$provider = ImageJobs::provider();
				return new WP_REST_Response([
					'configured' => (bool) $provider,
					'provider' => $provider?->id(),
					'model' => $provider?->model(),
					'mock' => $provider?->isMock() ?? false,
					'unitCost' => $provider?->estimateCost(),
					'maxVariants' => ImageJobs::MAX_VARIANTS,
					'maxBatchCost' => (float) apply_filters('clara/image_max_batch_cost', 2.0),
					'dailyBudget' => (float) apply_filters('clara/image_daily_budget', 10.0),
				]);
			},
			'permission_callback' => $auth,
		]);
	}

	public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$section = $this->ownedSection((int) $request['id']);

		return is_wp_error($section) ? $section : new WP_REST_Response(ImageJobs::batches($section['id']));
	}

	public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$section = $this->ownedSection((int) $request['id']);

		if (is_wp_error($section)) {
			return $section;
		}

		if ($section['status'] === 'archived') {
			return new WP_Error('clara_section_archived', __('Sekcja jest zarchiwizowana — przywróć ją, aby generować obrazy.', 'clara'), ['status' => 409]);
		}

		$body = $this->body($request);
		$v = new Validator();
		$runId = is_string($body['runId'] ?? null) ? strtolower(trim($body['runId'])) : '';

		if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $runId)) {
			$v->fail('runId', 'Wskaż zestaw kierunków artystycznych (runId).');
		}

		$keys = $body['keys'] ?? [];
		if (! is_array($keys) || ! array_is_list($keys)) {
			$v->fail('keys', 'Oczekiwano listy kluczy kierunków.');
			$keys = [];
		} elseif (count($keys) > ImageJobs::MAX_VARIANTS) {
			$v->fail('keys', sprintf('Maksymalnie %d warianty na żądanie.', ImageJobs::MAX_VARIANTS));
		} elseif (array_diff($keys, ArtDirectionSchema::KEYS) || count(array_unique($keys)) !== count($keys)) {
			$v->fail('keys', 'Dozwolone, niepowtarzalne klucze: ' . implode(', ', ArtDirectionSchema::KEYS) . '.');
		}

		if (! $v->ok()) {
			return $v->error();
		}

		$batch = ImageJobs::enqueue($this->ownedProject($section['project_id']), $section, $runId, $keys);

		return is_wp_error($batch) ? $batch : new WP_REST_Response($batch, 202);
	}

	public function cancel(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$section = $this->ownedSection((int) $request['id']);

		if (is_wp_error($section)) {
			return $section;
		}

		$batch = ImageJobs::cancel($section['id'], (string) $request['batch']);

		return is_wp_error($batch) ? $batch : new WP_REST_Response($batch);
	}
}
