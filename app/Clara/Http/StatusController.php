<?php

namespace App\Clara\Http;

use App\Clara\Access;
use App\Clara\Database\Schema;
use WP_REST_Response;

class StatusController
{
	public const NAMESPACE = 'clara/v1';

	public function register(): void
	{
		register_rest_route(self::NAMESPACE, '/status', [
			'methods' => 'GET',
			'callback' => [$this, 'show'],
			'permission_callback' => [Access::class, 'restPermission'],
			'args' => [],
		]);
	}

	public function show(): WP_REST_Response
	{
		$user = wp_get_current_user();

		return new WP_REST_Response([
			'app' => 'clara',
			'schemaVersion' => get_option(Schema::OPTION) ?: null,
			'schemaCurrent' => Schema::isCurrent(),
			'tables' => Schema::status(),
			'user' => [
				'id' => $user->ID,
				'name' => $user->display_name,
			],
		]);
	}
}
