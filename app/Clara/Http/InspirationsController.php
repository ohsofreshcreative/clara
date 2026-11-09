<?php

namespace App\Clara\Http;

use App\Clara\Access;
use App\Clara\Inspirations\InspirationSearch;
use App\Clara\Inspirations\Inspirations;
use App\Clara\Support\Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Inspiration Finder: wyszukiwanie przez adapter i kolekcje inspiracji w projektach.
 */
class InspirationsController extends Controller
{
	public function register(): void
	{
		$auth = [Access::class, 'restPermission'];
		$id = ['id' => self::idArg()];

		register_rest_route(self::NS, '/search', [
			'methods' => WP_REST_Server::READABLE,
			'callback' => [$this, 'search'],
			'permission_callback' => $auth,
		]);

		register_rest_route(self::NS, '/search/status', [
			'methods' => WP_REST_Server::READABLE,
			'callback' => function () {
				$provider = InspirationSearch::provider();
				return new WP_REST_Response(['configured' => (bool) $provider, 'provider' => $provider?->id(), 'label' => $provider?->label(), 'mock' => $provider?->isMock() ?? false]);
			},
			'permission_callback' => $auth,
		]);

		register_rest_route(self::NS, '/projects/(?P<id>\d+)/inspirations', [
			['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'index'], 'permission_callback' => $auth, 'args' => $id],
			['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'store'], 'permission_callback' => $auth, 'args' => $id],
		]);

		register_rest_route(self::NS, '/inspirations/(?P<id>\d+)', [
			'methods' => WP_REST_Server::DELETABLE,
			'callback' => [$this, 'destroy'],
			'permission_callback' => $auth,
			'args' => $id,
		]);
	}

	public function search(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$result = InspirationSearch::search($request->get_query_params(), get_current_user_id());

		if (is_wp_error($result)) {
			return $result;
		}

		// Oznaczenie wyników już zapisanych w wybranym projekcie (tylko własnym).
		$projectId = (int) $request->get_param('project');
		if ($projectId && ! is_wp_error($this->ownedProject($projectId))) {
			$saved = array_flip(Inspirations::savedUrls($projectId));
			foreach ($result['items'] as &$item) {
				$item['saved'] = isset($saved[Inspirations::normalizeUrl($item['sourceUrl'])]);
			}
		}

		return new WP_REST_Response($result);
	}

	public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		$section = (int) $request->get_param('section');

		return new WP_REST_Response(Inspirations::forProject($project['id'], $section ?: null));
	}

	/**
	 * Zapis z wyniku wyszukiwania (`token`) lub ręcznie dodany link (`url`).
	 * Licencję zapisujemy tylko wtedy, gdy zadeklarował ją dostawca; ręczny link zawsze ma licencję nieznaną.
	 */
	public function store(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$project = $this->ownedProject((int) $request['id']);

		if (is_wp_error($project)) {
			return $project;
		}

		$body = $this->body($request);
		$v = new Validator();
		$sectionId = (int) ($body['section_id'] ?? 0) ?: null;

		if ($sectionId) {
			$section = $this->ownedSection($sectionId);
			if (is_wp_error($section) || $section['project_id'] !== $project['id']) {
				$v->fail('section_id', 'Sekcja nie należy do tego projektu.');
			}
		}

		$note = $v->text('note', $body['note'] ?? '', 500, false, true);

		if (! empty($body['token'])) {
			$entry = InspirationSearch::resolve((string) $body['token'], get_current_user_id());

			if (! $entry) {
				return new WP_Error('clara_search_expired', __('Wynik wyszukiwania wygasł — wyszukaj ponownie.', 'clara'), ['status' => 410]);
			}
			if (! $v->ok()) {
				return $v->error();
			}

			$item = $entry['item'];
			[$inspiration, $duplicate] = Inspirations::save($project['id'], $sectionId, $item['sourceUrl'], $item['thumbnailUrl'], [
				'title' => $item['title'],
				'creator' => $item['creator'],
				'creatorUrl' => $item['creatorUrl'],
				'sourceName' => $item['sourceName'],
				'provider' => $entry['provider'],
			], [
				'license' => $item['license'],
				'declaredBy' => $item['license'] ? $entry['provider'] : null,
			], [
				'origin' => 'search',
				'query' => $entry['query'],
				'providerId' => $item['providerId'],
				'indexedAt' => $item['indexedAt'],
				'width' => $item['width'],
				'height' => $item['height'],
				'note' => $note,
			]);
		} else {
			$url = $v->url('url', $body['url'] ?? '');
			if ($url === '' && $v->ok()) {
				$v->fail('url', 'Podaj adres inspiracji albo wybierz wynik wyszukiwania.');
			}
			if ($url !== '' && self::isPrivateHost((string) wp_parse_url($url, PHP_URL_HOST))) {
				$v->fail('url', 'Adres musi prowadzić do publicznej strony.');
			}
			$title = $v->text('title', $body['title'] ?? '', 300);

			if (! $v->ok()) {
				return $v->error();
			}

			[$inspiration, $duplicate] = Inspirations::save($project['id'], $sectionId, $url, '', [
				'title' => $title,
				'creator' => '',
				'creatorUrl' => '',
				'sourceName' => (string) wp_parse_url($url, PHP_URL_HOST),
				'provider' => 'manual',
			], [
				'license' => null,
				'declaredBy' => null,
			], ['origin' => 'manual', 'note' => $note]);
		}

		return new WP_REST_Response(['inspiration' => $inspiration, 'duplicate' => $duplicate], $duplicate ? 200 : 201);
	}

	public function destroy(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$inspiration = Inspirations::find((int) $request['id']);

		if (! $inspiration || is_wp_error($this->ownedProject($inspiration['project_id']))) {
			return $this->notFound();
		}

		Inspirations::delete($inspiration['id']);

		return new WP_REST_Response(['deleted' => true]);
	}

	private static function isPrivateHost(string $host): bool
	{
		$host = strtolower(trim($host, '[]'));

		if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
			return true;
		}

		return filter_var($host, FILTER_VALIDATE_IP) !== false
			&& filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
	}
}
