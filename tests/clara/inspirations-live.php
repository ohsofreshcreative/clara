<?php

/**
 * Test live Openverse (publiczne API, bez klucza i kosztów): 1 zapytanie + sprawdzenie linków źródłowych.
 * Uruchamiany tylko jawnie (CLARA_LIVE=1 bash tests/clara/run.sh) — zależy od sieci i limitów dostawcy.
 */

use App\Clara\Inspirations\OpenverseProvider;

$failures = 0;
$passes = 0;
$check = function (string $name, bool $ok, mixed $detail = '') use (&$failures, &$passes) {
	$ok ? $passes++ : $failures++;
	$detail = is_string($detail) ? $detail : wp_json_encode($detail, JSON_UNESCAPED_UNICODE);
	WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " — {$detail}" : ''));
};

$user = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1;
wp_set_current_user((int) $user);
$use = fn () => new OpenverseProvider();
add_filter('clara/search_provider', $use);

$request = new WP_REST_Request('GET', '/clara/v1/search');
$request->set_query_params(['q' => 'swimming pool', 'per_page' => 5]);
$response = rest_do_request($request);
$data = $response->get_data();

$check('Openverse live: wyniki', $response->get_status() === 200 && count($data['items'] ?? []) > 0, [$response->get_status(), $data['total'] ?? $data['message'] ?? null]);
$licensed = array_filter($data['items'] ?? [], fn ($i) => $i['license'] !== null);
$check('licencje pochodzą z API (nazwa + link CC)', count($licensed) === count($data['items'] ?? []) && ! array_filter($licensed, fn ($i) => ! str_starts_with($i['license']['url'], 'https://creativecommons.org/')), array_map(fn ($i) => $i['license']['name'] ?? null, $data['items'] ?? []));

foreach (array_slice($data['items'] ?? [], 0, 3) as $item) {
	$head = wp_remote_head($item['sourceUrl'], ['timeout' => 15, 'redirection' => 3, 'user-agent' => 'Clara-PoC/0.1 (link check)']);
	$code = is_wp_error($head) ? $head->get_error_message() : wp_remote_retrieve_response_code($head);
	$check("link źródłowy działa: {$item['sourceUrl']}", is_int($code) && $code < 400, (string) $code);
}

$empty = new WP_REST_Request('GET', '/clara/v1/search');
$empty->set_query_params(['q' => 'zzqqxxnonexistentqqzz']);
$r = rest_do_request($empty);
$check('Openverse live: puste wyniki obsłużone', $r->get_status() === 200 && $r->get_data()['items'] === [] && $r->get_data()['total'] === 0);

remove_filter('clara/search_provider', $use);
WP_CLI::log("Inspiracje live: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
