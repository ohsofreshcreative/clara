<?php

/**
 * Eksport do pluginu Figmy: paczka (layout + osadzone obrazy), brak sekretów, jednorazowy kod
 * (hash w bazie, TTL, jedno użycie, limity), CORS tylko dla endpointu pluginu, uprawnienia.
 * Gdy ustawione CLARA_FIGMA_FIXTURE — zapisuje prawdziwą paczkę do testów importera pluginu.
 */

use App\Clara\Creative\ImageJobs;
use App\Clara\Database\Schema;
use App\Clara\Projects\Projects;

require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = 0;
$passes = 0;
$check = function (string $name, bool $ok, mixed $detail = '') use (&$failures, &$passes) {
	$ok ? $passes++ : $failures++;
	$detail = is_string($detail) ? $detail : wp_json_encode($detail, JSON_UNESCAPED_UNICODE);
	WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $name . (! $ok && $detail !== '' ? " — {$detail}" : ''));
};
$req = function (string $method, string $route, int $user, ?array $json = null, ?string $raw = null) {
	wp_set_current_user($user);
	$request = new WP_REST_Request($method, $route);
	if ($json !== null) {
		$request->set_header('content-type', 'application/json');
		$request->set_body(wp_json_encode($json));
	}
	if ($raw !== null) {
		$request->set_header('content-type', 'text/plain');
		$request->set_body($raw);
	}
	$response = rest_do_request($request);
	return [$response->get_status(), $response->get_data(), $response->get_headers()];
};

$noLlm = fn () => null;
$noKey = fn () => '';
$noSpawn = fn () => false;
add_filter('clara/llm_provider', $noLlm);
add_filter('clara/anthropic_api_key', $noKey);
add_filter('clara/image_spawn_cron', $noSpawn);
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(1, 250);

$suffix = wp_generate_password(6, false, false);
$users = [];
foreach (['a', 'b'] as $key) {
	$users[$key] = wp_insert_user(['user_login' => "clara_fig_{$key}_{$suffix}", 'user_pass' => wp_generate_password(24), 'user_email' => "clara_fig_{$key}_{$suffix}@example.invalid", 'role' => 'editor']);
}
global $wpdb;
$tokenOptions = fn () => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_clara\\_figma\\_tok\\_%' AND option_name NOT LIKE '%\\_rl\\_%'");
$redeem = fn (string $token) => $req('POST', '/clara/v1/figma/bundle', 0, null, wp_json_encode(['token' => $token]));

try {
	$a = $users['a'];
	$b = $users['b'];
	[, $project] = $req('POST', '/clara/v1/projects', $a, ['name' => 'H2Otwock Figma', 'brief' => ['summary' => 'Kryty basen w Otwocku.']]);
	$pid = (int) $project['id'];
	$req('POST', "/clara/v1/projects/{$pid}/design-systems", $a, ['tokens' => [
		'colors' => [['name' => 'Granat', 'value' => '#0b1b3a'], ['name' => 'Biel', 'value' => '#ffffff'], ['name' => 'Woda', 'value' => '#00a6df']],
		'typography' => [['name' => 'H1', 'fontFamily' => 'Poppins', 'fontSize' => 56, 'fontWeight' => 700], ['name' => 'Body', 'fontFamily' => 'Inter', 'fontSize' => 18, 'fontWeight' => 400]],
	]]);
	[, $section] = $req('POST', "/clara/v1/projects/{$pid}/sections", $a, ['type' => 'hero', 'requirements' => ['content' => ['Basen H2Otwock', 'Otwarte codziennie 6:00–22:00']]]);
	$sid = (int) $section['id'];
	[, $run] = $req('POST', "/clara/v1/sections/{$sid}/art-directions", $a, []);
	$req('POST', "/clara/v1/sections/{$sid}/images", $a, ['runId' => $run['runId']]);
	ImageJobs::process(10);
	[, $gallery] = $req('GET', "/clara/v1/sections/{$sid}/gallery", $a);
	$img = array_column($gallery['items'], null, 'directionKey')['A'];
	$req('POST', "/clara/v1/sections/{$sid}/selection", $a, ['generationId' => $img['id']]);
	[, $doc] = $req('POST', "/clara/v1/images/{$img['id']}/layouts", $a, []);
	$lid = (int) $doc['id'];

	/*--- Paczka do pobrania ---*/
	[$code, $bundle, $headers] = $req('GET', "/clara/v1/layouts/{$lid}/figma-bundle", $a);
	$file = get_attached_file($img['image']['id']);
	$asset = $bundle['assets'][(string) $img['id']] ?? [];
	$check('paczka: format v1, layout = dokument, tytuł wariantu', $code === 200 && $bundle['format'] === 'clara-figma-bundle' && $bundle['version'] === 1 && $bundle['layoutId'] === $lid && $bundle['layout'] === $doc['layout'] && $bundle['title'] === 'Clara Hero — wariant A · v1', [$code, $bundle['title'] ?? $bundle]);
	$check('paczka: obraz osadzony (PNG, identyczny z plikiem wariantu)', ($asset['mime'] ?? '') === 'image/png' && md5(base64_decode($asset['data'] ?? '')) === md5_file($file) && $asset['width'] === 1200);
	$check('paczka: nagłówki pobrania i no-store', str_contains($headers['Content-Disposition'] ?? '', "clara-figma-layout-{$lid}.json") && ($headers['Cache-Control'] ?? '') === 'no-store');
	$json = wp_json_encode($bundle);
	$user = get_userdata($a);
	$leaks = array_filter([
		'adres e-mail' => str_contains($json, $user->user_email),
		'login' => str_contains($json, $user->user_login),
		'ścieżka uploads' => str_contains($json, 'wp-content/uploads') || str_contains($json, wp_upload_dir()['basedir']),
		'sól WP' => str_contains($json, wp_salt('auth')),
		'klucz API' => (bool) preg_match('/x-api-key|sk-ant-|api_key|figd_/i', $json),
		'id użytkownika' => str_contains($json, '"createdBy"') || str_contains($json, '"owner_user_id"'),
	]);
	$check('paczka: brak sekretów i danych osobowych', ! $leaks, array_keys($leaks));
	[$code] = $req('GET', "/clara/v1/layouts/{$lid}/figma-bundle", $b);
	$check('paczka: inny użytkownik → 404', $code === 404, $code);
	[$code] = $req('GET', "/clara/v1/layouts/{$lid}/figma-bundle", 0);
	$check('paczka: niezalogowany → 401', $code === 401, $code);

	/*--- Jednorazowy kod ---*/
	$before = $tokenOptions();
	[$code, $tok, $headers] = $req('POST', "/clara/v1/layouts/{$lid}/figma-token", $a);
	$token = $tok['token'] ?? '';
	$check('kod: 201, 43 znaki (256 bitów), TTL 10 min, endpoint pluginu', $code === 201 && preg_match('/^[A-Za-z0-9_-]{43}$/', $token) && $tok['ttl'] === 600 && str_ends_with($tok['endpoint'], '/clara/v1/figma/bundle') && ($headers['Cache-Control'] ?? '') === 'no-store', [$code, $tok]);
	$check('kod: w bazie tylko skrót HMAC (brak jawnego kodu)', $tokenOptions() === $before + 1 && ! (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_value LIKE %s", '%' . $wpdb->esc_like($token) . '%', '%' . $wpdb->esc_like($token) . '%')));
	[$code, $redeemed] = $redeem($token);
	$check('kod: plugin (bez sesji) pobiera tę samą paczkę', $code === 200 && $redeemed === $bundle, $code);
	[$code, $data] = $redeem($token);
	$check('kod: drugie użycie → 401 (jednorazowy)', $code === 401 && str_contains($data['message'], 'już użyty'), $code);
	foreach (['' => 'pusty', 'abc' => 'za krótki', str_repeat('A', 43) => 'nieznany', "{$token}' OR 1=1" => 'wstrzyknięcie'] as $bad => $label) {
		[$code] = $redeem($bad);
		$check("kod: {$label} → 401", $code === 401, $code);
	}
	[$code] = $req('POST', '/clara/v1/figma/bundle', 0, null, 'to nie jest JSON');
	$check('kod: treść bez JSON → 401', $code === 401, $code);
	[$code] = $req('POST', "/clara/v1/layouts/{$lid}/figma-token", $b);
	$check('kod: inny użytkownik nie wygeneruje kodu → 404', $code === 404, $code);
	[$code] = $req('POST', "/clara/v1/layouts/{$lid}/figma-token", 0);
	$check('kod: niezalogowany → 401', $code === 401, $code);

	[, $tok2] = $req('POST', "/clara/v1/layouts/{$lid}/figma-token", $a);
	delete_transient('clara_figma_tok_' . hash_hmac('sha256', $tok2['token'], wp_salt('auth')));
	[$code] = $redeem($tok2['token']);
	$check('kod: wygasły → 401', $code === 401, $code);

	[, $tok3] = $req('POST', "/clara/v1/layouts/{$lid}/figma-token", $a);
	(new WP_User($a))->set_role('subscriber');
	[$code] = $redeem($tok3['token']);
	(new WP_User($a))->set_role('editor');
	$check('kod: właściciel bez uprawnień do Clara → 401', $code === 401, $code);

	$hit = null;
	for ($i = 0; $i < 12 && $hit === null; $i++) {
		[$code] = $req('POST', "/clara/v1/layouts/{$lid}/figma-token", $a);
		$hit = $code === 429 ? $i : null;
	}
	$check('kod: limit 10 kodów / 10 min → 429', $hit !== null, $hit);
	delete_transient("clara_figma_tok_rl_{$a}");

	$hit = null;
	for ($i = 0; $i < 40 && $hit === null; $i++) {
		[$code] = $redeem(str_repeat('B', 43));
		$hit = $code === 429 ? $i : null;
	}
	$check('endpoint pluginu: limit prób z jednego IP → 429', $hit !== null && $hit <= 30, $hit);
	delete_transient('clara_figma_ip_' . md5($_SERVER['REMOTE_ADDR']));

	/*--- CORS tylko dla endpointu pluginu (prawdziwe żądania HTTP) ---*/
	$cors = wp_remote_post(rest_url('clara/v1/figma/bundle'), ['timeout' => 15, 'headers' => ['Origin' => 'null', 'Content-Type' => 'text/plain'], 'body' => wp_json_encode(['token' => str_repeat('C', 43)])]);
	$other = wp_remote_get(rest_url('clara/v1/status'), ['timeout' => 15, 'headers' => ['Origin' => 'null']]);
	$check('CORS: plugin (origin null) bez poświadczeń; inne trasy bez *', ! is_wp_error($cors) && wp_remote_retrieve_header($cors, 'access-control-allow-origin') === '*' && wp_remote_retrieve_header($cors, 'access-control-allow-credentials') === '' && wp_remote_retrieve_response_code($cors) === 401 && ! is_wp_error($other) && wp_remote_retrieve_header($other, 'access-control-allow-origin') !== '*', is_wp_error($cors) ? $cors->get_error_message() : [wp_remote_retrieve_header($cors, 'access-control-allow-origin'), wp_remote_retrieve_header($cors, 'access-control-allow-credentials')]);

	if ($fixture = getenv('CLARA_FIGMA_FIXTURE')) {
		file_put_contents($fixture, wp_json_encode($bundle));
	}

	/*--- Błędne źródła i kaskady ---*/
	[, $tok4] = $req('POST', "/clara/v1/layouts/{$lid}/figma-token", $a);
	wp_delete_attachment($img['image']['id'], true);
	[$code] = $req('GET', "/clara/v1/layouts/{$lid}/figma-bundle", $a);
	$check('paczka: brak pliku obrazu → 409', $code === 409, $code);
	$req('DELETE', "/clara/v1/projects/{$pid}", $a);
	[$code] = $redeem($tok4['token']);
	$check('kod: po usunięciu projektu → 401', $code === 401, $code);
} finally {
	remove_filter('clara/llm_provider', $noLlm);
	remove_filter('clara/anthropic_api_key', $noKey);
	remove_filter('clara/image_spawn_cron', $noSpawn);
	foreach ($users as $userId) {
		delete_transient("clara_figma_tok_rl_{$userId}");
		foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::table('projects') . ' WHERE owner_user_id = %d', $userId)) as $id) {
			Projects::delete((int) $id);
		}
		wp_delete_user($userId);
	}
	$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%clara\\_figma\\_%'");
	wp_clear_scheduled_hook(ImageJobs::HOOK);
	wp_set_current_user(0);
}

WP_CLI::log("Eksport Figma: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
