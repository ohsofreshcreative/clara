<?php

namespace App\Clara\Http;

use App\Clara\Access;
use App\Clara\Creative\Gallery;
use App\Clara\Support\Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Galeria wariantów, wybór, historia wersji i Remix. Generacje są tylko odczytywane lub
 * dopisywane — żaden endpoint nie zmienia ani nie usuwa istniejącej generacji.
 */
class GalleryController extends Controller
{
	public function register(): void
	{
		$auth = [Access::class, 'restPermission'];
		$id = ['id' => self::idArg()];

		register_rest_route(self::NS, '/sections/(?P<id>\d+)/gallery', [
			'methods' => WP_REST_Server::READABLE,
			'callback' => [$this, 'index'],
			'permission_callback' => $auth,
			'args' => $id,
		]);

		register_rest_route(self::NS, '/sections/(?P<id>\d+)/selection', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [$this, 'select'],
			'permission_callback' => $auth,
			'args' => $id,
		]);

		register_rest_route(self::NS, '/images/(?P<id>\d+)/lineage', [
			'methods' => WP_REST_Server::READABLE,
			'callback' => [$this, 'lineage'],
			'permission_callback' => $auth,
			'args' => $id,
		]);

		register_rest_route(self::NS, '/images/(?P<id>\d+)/remix', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [$this, 'remix'],
			'permission_callback' => $auth,
			'args' => $id,
		]);
	}

	public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$section = $this->ownedSection((int) $request['id']);

		return is_wp_error($section) ? $section : new WP_REST_Response(Gallery::forSection($section));
	}

	public function select(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$section = $this->ownedSection((int) $request['id']);

		if (is_wp_error($section)) {
			return $section;
		}

		$body = $this->body($request);
		$value = $body['generationId'] ?? null;

		if ($value !== null && (! is_int($value) || $value < 1)) {
			$v = new Validator();
			$v->fail('generationId', 'Podaj identyfikator wariantu albo null, aby wyczyścić wybór.');
			return $v->error();
		}

		$result = Gallery::select($section, $value);

		return is_wp_error($result) ? $result : new WP_REST_Response($result);
	}

	public function lineage(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		[$generation, $section] = $this->ownedImage((int) $request['id']);

		if (! $generation) {
			return $this->notFound();
		}

		$lineage = Gallery::lineage($section, (int) $generation['id']);

		return is_wp_error($lineage) ? $lineage : new WP_REST_Response($lineage);
	}

	public function remix(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		[$generation, $section] = $this->ownedImage((int) $request['id']);

		if (! $generation) {
			return $this->notFound();
		}

		if ($section['status'] === 'archived') {
			return new WP_Error('clara_section_archived', __('Sekcja jest zarchiwizowana — przywróć ją, aby remiksować.', 'clara'), ['status' => 409]);
		}

		$body = $this->body($request);
		$v = new Validator();
		$prompt = $v->text('prompt', $body['prompt'] ?? '', 1000, true);
		if ($prompt !== '' && mb_strlen($prompt) < 3) {
			$v->fail('prompt', 'Opisz zmianę — minimum 3 znaki.');
		}

		$references = $body['references'] ?? [];
		if (! is_array($references) || ! array_is_list($references) || array_filter($references, fn ($r) => ! is_int($r) || $r < 1)) {
			$v->fail('references', 'Oczekiwano listy identyfikatorów inspiracji.');
			$references = [];
		} elseif (count($references) > Gallery::MAX_REFERENCES) {
			$v->fail('references', sprintf('Maksymalnie %d referencji.', Gallery::MAX_REFERENCES));
		} elseif (count(array_unique($references)) !== count($references)) {
			$v->fail('references', 'Referencje nie mogą się powtarzać.');
		}

		if (! $v->ok()) {
			return $v->error();
		}

		$batch = Gallery::remix($this->ownedProject($section['project_id']), $section, $generation, $prompt, $references);

		return is_wp_error($batch) ? $batch : new WP_REST_Response($batch, 202);
	}

	/**
	 * @return array{0: ?array, 1: ?array} generacja obrazu i jej sekcja — tylko dla właściciela projektu
	 */
	private function ownedImage(int $id): array
	{
		$generation = Gallery::find($id);
		$section = $generation ? $this->ownedSection((int) $generation['section_id']) : null;

		return $generation && ! is_wp_error($section) ? [$generation, $section] : [null, null];
	}
}
