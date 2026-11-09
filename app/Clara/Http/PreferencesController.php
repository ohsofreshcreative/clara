<?php

namespace App\Clara\Http;

use App\Clara\Access;
use App\Clara\Preferences;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class PreferencesController extends Controller
{
	public function register(): void
	{
		register_rest_route(self::NS, '/preferences', [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'show'],
				'permission_callback' => [Access::class, 'restPermission'],
			],
			[
				'methods' => 'PATCH',
				'callback' => [$this, 'update'],
				'permission_callback' => [Access::class, 'restPermission'],
			],
		]);
	}

	public function show(): WP_REST_Response
	{
		return new WP_REST_Response(self::payload(Preferences::get(get_current_user_id())));
	}

	public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$saved = Preferences::update(get_current_user_id(), $this->body($request));

		return is_wp_error($saved) ? $saved : new WP_REST_Response(self::payload($saved));
	}

	private static function payload(array $preferences): array
	{
		return $preferences + ['options' => ['accents' => Preferences::ACCENTS, 'scales' => Preferences::SCALES, 'effects' => Preferences::EFFECTS]];
	}
}
