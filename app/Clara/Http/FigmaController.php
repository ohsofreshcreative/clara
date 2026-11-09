<?php

namespace App\Clara\Http;

use App\Clara\Access;
use App\Clara\Creative\Generations;
use App\Clara\Layout\FigmaExport;
use App\Clara\Layout\LayoutDocuments;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Eksport do pluginu Figmy: paczka do pobrania (sesja właściciela) i jednorazowy kod dla pluginu.
 * `/figma/bundle` jest jedynym endpointem bez sesji WP — dostęp daje wyłącznie ważny kod.
 */
class FigmaController extends Controller
{
	private const REDEEM_ROUTE = '/' . self::NS . '/figma/bundle';

	private const REDEEM_PER_MINUTE = 30;

	public function register(): void
	{
		$auth = [Access::class, 'restPermission'];
		$id = ['id' => self::idArg()];

		register_rest_route(self::NS, '/layouts/(?P<id>\d+)/figma-bundle', [
			'methods' => WP_REST_Server::READABLE,
			'callback' => [$this, 'download'],
			'permission_callback' => $auth,
			'args' => $id,
		]);

		register_rest_route(self::NS, '/layouts/(?P<id>\d+)/figma-token', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [$this, 'token'],
			'permission_callback' => $auth,
			'args' => $id,
		]);

		register_rest_route(self::NS, '/figma/bundle', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [$this, 'redeem'],
			// Celowo bez sesji: plugin Figmy działa poza przeglądarką użytkownika. Autoryzacja = jednorazowy kod.
			'permission_callback' => '__return_true',
		]);

		add_filter('rest_pre_serve_request', [$this, 'cors'], 20, 4);
	}

	public function download(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		[$doc, $generation] = $this->ownedDocument((int) $request['id']);

		if (! $doc) {
			return $this->notFound();
		}

		$bundle = FigmaExport::bundle($doc, $generation);
		if (is_wp_error($bundle)) {
			return $bundle;
		}

		$response = new WP_REST_Response($bundle);
		$response->header('Content-Disposition', 'attachment; filename="clara-figma-layout-' . $doc['id'] . '.json"');
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	public function token(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		[$doc] = $this->ownedDocument((int) $request['id']);

		if (! $doc) {
			return $this->notFound();
		}

		$token = FigmaExport::createToken($doc['id'], get_current_user_id());
		if (is_wp_error($token)) {
			return $token;
		}

		$response = new WP_REST_Response($token, 201);
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	public function redeem(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$ip = 'clara_figma_ip_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
		$hits = (int) get_transient($ip);
		if ($hits >= self::REDEEM_PER_MINUTE) {
			return new WP_Error('clara_export_rate', __('Za dużo prób — odczekaj minutę.', 'clara'), ['status' => 429]);
		}
		set_transient($ip, $hits + 1, MINUTE_IN_SECONDS);

		// Plugin wysyła text/plain (bez preflight CORS) — treść parsujemy sami.
		$body = json_decode((string) $request->get_body(), true);
		$token = is_array($body) && is_string($body['token'] ?? null) ? trim($body['token']) : '';

		$bundle = FigmaExport::redeem($token);
		if (is_wp_error($bundle)) {
			return $bundle;
		}

		$response = new WP_REST_Response($bundle);
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	/**
	 * CORS tylko dla `/figma/bundle`: UI pluginu ma origin „null”, więc zezwalamy na dowolne
	 * źródło BEZ poświadczeń (cookies nie są używane — dostęp daje wyłącznie kod).
	 */
	public function cors(bool $served, $result, WP_REST_Request $request): bool
	{
		if ($request->get_route() === self::REDEEM_ROUTE && ! headers_sent()) {
			header_remove('Access-Control-Allow-Credentials');
			header('Access-Control-Allow-Origin: *');
			header('Access-Control-Allow-Methods: POST, OPTIONS');
			header('Access-Control-Allow-Headers: Content-Type');
		}

		return $served;
	}

	/**
	 * @return array{0: ?array, 1: ?array}
	 */
	private function ownedDocument(int $id): array
	{
		$doc = LayoutDocuments::find($id);
		$generation = $doc ? Generations::find((int) $doc['generation_id'], LayoutDocuments::OWNER_KINDS) : null;

		return $generation && ! is_wp_error($this->ownedSection((int) $generation['section_id'])) ? [$doc, $generation] : [null, null];
	}
}
