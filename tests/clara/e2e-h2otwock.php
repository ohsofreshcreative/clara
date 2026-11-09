<?php

/**
 * Odbiór PoC: pionowy slice H2Otwock przez REST (jak UI), na providerach mock —
 * brief → design system → 4 kierunki → 4 obrazy (1 żądanie) → wybór → remix → Layout JSON →
 * paczka Figmy + kod jednorazowy. Dodatkowo: przegląd uprawnień WSZYSTKICH tras clara/v1
 * (niezalogowany, inny użytkownik), koszty i brak sekretów w odpowiedziach.
 * To NIE jest test live providerów AI — te są zablokowane brakiem kluczy/budżetu.
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
$responses = '';
$req = function (string $method, string $route, int $user, ?array $json = null) use (&$responses) {
	wp_set_current_user($user);
	$request = new WP_REST_Request($method, $route);
	if ($json !== null) {
		$request->set_header('content-type', 'application/json');
		$request->set_body(wp_json_encode($json));
	}
	$response = rest_do_request($request);
	$responses .= wp_json_encode($response->get_data());
	return [$response->get_status(), $response->get_data()];
};

$noLlm = fn () => null;
$noKey = fn () => '';
$noSpawn = fn () => false;
add_filter('clara/llm_provider', $noLlm);
add_filter('clara/anthropic_api_key', $noKey);
add_filter('clara/image_spawn_cron', $noSpawn);
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand(1, 250);

$suffix = wp_generate_password(6, false, false);
$users = [];
foreach (['a' => 'editor', 'b' => 'editor', 's' => 'subscriber'] as $key => $role) {
	$users[$key] = wp_insert_user(['user_login' => "clara_e2e_{$key}_{$suffix}", 'user_pass' => wp_generate_password(24), 'user_email' => "clara_e2e_{$key}_{$suffix}@example.invalid", 'role' => $role]);
}

try {
	[$a, $b, $s] = [$users['a'], $users['b'], $users['s']];

	/*--- 1. Projekt i brief H2Otwock ---*/
	$brief = ['summary' => 'Kryty basen miejski w Otwocku — rodziny, nauka pływania, sauna.', 'audience' => 'Rodziny z dziećmi, seniorzy, kluby pływackie', 'goals' => ['Sprzedaż biletów online'], 'tone' => ['świeży', 'przyjazny'], 'avoid' => ['stockowych uśmiechów'], 'mustHave' => ['Logo H2Otwock']];
	[$code, $project] = $req('POST', '/clara/v1/projects', $a, ['name' => 'H2Otwock E2E', 'client_name' => 'H2Otwock', 'industry' => 'Rekreacja', 'website_url' => 'https://example.com/h2otwock', 'brief' => $brief]);
	$pid = (int) ($project['id'] ?? 0);
	[, $reread] = $req('GET', "/clara/v1/projects/{$pid}", $a);
	$check('1. projekt i brief zapisane i odczytane ponownie', $code === 201 && $reread['brief']['summary'] === $brief['summary'] && $reread['brief']['mustHave'] === ['Logo H2Otwock'], [$code, $reread['brief'] ?? $reread]);

	[$code, $ds] = $req('POST', "/clara/v1/projects/{$pid}/design-systems", $a, ['tokens' => [
		'colors' => [['name' => 'Granat', 'value' => '#0b1b3a'], ['name' => 'Woda', 'value' => '#00a6df'], ['name' => 'Biel', 'value' => '#ffffff']],
		'typography' => [['name' => 'H1', 'fontFamily' => 'Poppins', 'fontSize' => 56, 'fontWeight' => 700], ['name' => 'Body', 'fontFamily' => 'Inter', 'fontSize' => 18, 'fontWeight' => 400]],
	]]);
	$check('1. design system v1', $code === 201 && (int) $ds['version'] === 1, [$code, $ds['version'] ?? $ds]);

	[$code, $section] = $req('POST', "/clara/v1/projects/{$pid}/sections", $a, ['type' => 'hero', 'requirements' => ['content' => ['Nagłówek z nazwą obiektu', 'Godziny otwarcia', 'Przycisk Kup bilet']]]);
	$sid = (int) ($section['id'] ?? 0);
	$check('1. sekcja Hero', $code === 201 && $sid > 0, $code);

	/*--- 2. Cztery kierunki i cztery obrazy z jednego żądania ---*/
	[$code, $run] = $req('POST', "/clara/v1/sections/{$sid}/art-directions", $a, []);
	$layouts = array_column(array_column($run['directions'] ?? [], 'composition'), 'layout');
	$check('2. 4 kierunki A–D z 4 różnymi kompozycjami', in_array($code, [200, 201], true) && count($layouts) === 4 && count(array_unique($layouts)) === 4, $layouts);
	$check('2. kierunki zachowują wymagane treści', array_reduce($run['directions'] ?? [], fn ($ok, $d) => $ok && in_array('Przycisk Kup bilet', array_column($d['requiredContent'], 'item'), true), true));

	[$code, $batch] = $req('POST', "/clara/v1/sections/{$sid}/images", $a, ['runId' => $run['runId']]);
	ImageJobs::process(10);
	$batches = ImageJobs::batches($sid);
	$items = $batches[0]['items'] ?? [];
	$check('2. 4 obrazy po 1 żądaniu, wszystkie gotowe', in_array($code, [200, 201, 202], true) && count($batches) === 1 && count($items) === 4 && count(array_filter($items, fn ($i) => $i['status'] === 'succeeded' && $i['image'])) === 4, [$code, count($items)]);
	$check('2. różne pliki obrazów (różne kompozycje A–D)', count(array_unique(array_map(fn ($i) => md5_file(get_attached_file($i['image']['id'])), $items))) === 4);
	$check('2. powiązanie: przebieg art direction, design system v1, prompt z briefem', $batches[0]['artRunId'] === $run['runId'] && (int) ($batches[0]['designSystem']['id'] ?? 0) === (int) $ds['id'] && array_reduce($items, fn ($ok, $i) => $ok && $i['prompt'] !== '' && (bool) preg_match('/basen|otwock|pływ/iu', $i['prompt']), true), $items[0]['prompt'] ?? '');
	$check('2. koszt zapisany (mock = 0) i oznaczony jako mock', $batches[0]['cost'] === 0.0 && $batches[0]['mock'] === true, [$batches[0]['cost'], $batches[0]['mock']]);

	/*--- 3. Wybór i remix bez nadpisania rodzica ---*/
	$byKey = array_column($items, null, 'directionKey');
	$original = $byKey['B'];
	$originalHash = md5_file(get_attached_file($original['image']['id']));
	[$code] = $req('POST', "/clara/v1/sections/{$sid}/selection", $a, ['generationId' => $original['id']]);
	$check('3. wybór wariantu B', $code === 200, $code);
	[$code, $remixBatch] = $req('POST', "/clara/v1/images/{$original['id']}/remix", $a, ['prompt' => 'Więcej światła dziennego nad niecką']);
	ImageJobs::process(10);
	[, $gallery] = $req('GET', "/clara/v1/sections/{$sid}/gallery", $a);
	$remix = array_values(array_filter($gallery['items'], fn ($i) => ($i['remixOf'] ?? null) === $original['id']))[0] ?? null;
	$check('3. remix = nowa wersja v2 z parent_id = oryginał', in_array($code, [200, 201, 202], true) && $remix && $remix['id'] !== $original['id'] && $remix['version'] === 2 && $remix['parent_id'] === $original['id'] && $remix['status'] === 'succeeded', $remix);
	$check('3. oryginał nienaruszony (plik i wybór)', md5_file(get_attached_file($original['image']['id'])) === $originalHash && $gallery['selectedId'] === $original['id']);
	[, $lineage] = $req('GET', "/clara/v1/images/{$remix['id']}/lineage", $a);
	$check('3. historia wersji: oryginał → remix', ($lineage['path'] ?? []) === [$original['id'], $remix['id']], $lineage['path'] ?? $lineage);

	/*--- 4. Layout JSON ---*/
	$req('POST', "/clara/v1/sections/{$sid}/selection", $a, ['generationId' => $remix['id']]);
	[$code, $doc] = $req('POST', "/clara/v1/images/{$remix['id']}/layouts", $a, []);
	$texts = array_values(array_filter($doc['layout']['nodes'] ?? [], fn ($n) => in_array($n['type'], ['text', 'button'], true)));
	$check('4. Layout JSON v1 bez błędów walidacji', in_array($code, [200, 201], true) && $doc['layout']['schemaVersion'] === 1 && $doc['validation']['valid'] === true, $doc['validation'] ?? []);
	$check('4. teksty pozostają tekstem (żaden nie jest bitmapą), CTA = button', $texts && ! array_filter($texts, fn ($n) => $n['rasterized']) && in_array('button', array_column($texts, 'type'), true), array_column($texts, 'type'));
	$check('4. fotografia jako region rasteryzowany', count($doc['layout']['rasterizedRegions']) >= 1);
	[$code, $bad] = $req('POST', '/clara/v1/layouts/validate', $a, ['layout' => ['schemaVersion' => 99]]);
	$check('4. walidator odrzuca błędne dane', $code === 400 || ! empty($bad['errors']), $code);

	/*--- 5. Figma: paczka i kod jednorazowy ---*/
	[$code, $bundle] = $req('GET', "/clara/v1/layouts/{$doc['id']}/figma-bundle", $a);
	$check('5. paczka Figmy: layout + osadzony obraz remiksu', $code === 200 && $bundle['format'] === 'clara-figma-bundle' && isset($bundle['assets'][(string) $remix['id']]), $code);
	[$code, $token] = $req('POST', "/clara/v1/layouts/{$doc['id']}/figma-token", $a);
	wp_set_current_user(0);
	$redeem = new WP_REST_Request('POST', '/clara/v1/figma/bundle');
	$redeem->set_header('content-type', 'text/plain');
	$redeem->set_body(wp_json_encode(['token' => $token['token'] ?? '']));
	$redeemed = rest_do_request($redeem);
	$check('5. plugin wymienia kod na paczkę (bez sesji), jednorazowo', $code === 201 && $redeemed->get_status() === 200 && $redeemed->get_data()['layoutId'] === $doc['id'] && rest_do_request($redeem)->get_status() === 401);
	if ($fixture = getenv('CLARA_E2E_FIXTURE')) {
		file_put_contents($fixture, wp_json_encode($bundle));
	}

	/*--- 6. Uprawnienia: wszystkie trasy clara/v1 ---*/
	$public = ['POST /clara/v1/figma/bundle'];
	$ids = ['id' => $pid, 'attachment' => 1, 'asset' => 1, 'batch' => wp_generate_uuid4()];
	$anonOpen = [];
	$checked = 0;
	foreach (rest_get_server()->get_routes() as $route => $handlers) {
		if (! str_starts_with($route, '/clara/v1/') ) {
			continue;
		}
		$path = preg_replace_callback('/\(\?P<(\w+)>[^)]+\)/', fn ($m) => (string) ($ids[$m[1]] ?? 1), $route);
		foreach ($handlers as $handler) {
			foreach (array_keys($handler['methods']) as $method) {
				if ($method === 'OPTIONS' || in_array("{$method} {$route}", $public, true)) {
					continue;
				}
				[$code] = $req($method, $path, 0, $method === 'GET' ? null : []);
				$checked++;
				if ($code !== 401) {
					$anonOpen[] = "{$method} {$route} → {$code}";
				}
				[$code] = $req($method, $path, $s, $method === 'GET' ? null : []);
				if ($code !== 403) {
					$anonOpen[] = "subscriber {$method} {$route} → {$code}";
				}
			}
		}
	}
	$check("6. {$checked} tras: niezalogowany → 401, subskrybent → 403 (wyjątek tylko /figma/bundle)", $checked > 30 && ! $anonOpen, $anonOpen);

	$foreign = [
		['GET', "/clara/v1/projects/{$pid}"], ['PATCH', "/clara/v1/projects/{$pid}", ['name' => 'X']], ['DELETE', "/clara/v1/projects/{$pid}"],
		['GET', "/clara/v1/projects/{$pid}/sections"], ['GET', "/clara/v1/sections/{$sid}"], ['POST', "/clara/v1/sections/{$sid}/art-directions", []],
		['POST', "/clara/v1/sections/{$sid}/images", ['runId' => $run['runId']]], ['GET', "/clara/v1/sections/{$sid}/gallery"], ['POST', "/clara/v1/sections/{$sid}/selection", ['generationId' => $original['id']]],
		['POST', "/clara/v1/images/{$original['id']}/remix", ['prompt' => 'X']], ['GET', "/clara/v1/images/{$remix['id']}/lineage"], ['GET', "/clara/v1/images/{$remix['id']}/layouts"],
		['POST', "/clara/v1/images/{$remix['id']}/layouts", []], ['GET', "/clara/v1/layouts/{$doc['id']}"], ['GET', "/clara/v1/layouts/{$doc['id']}/figma-bundle"],
		['POST', "/clara/v1/layouts/{$doc['id']}/figma-token"], ['GET', "/clara/v1/projects/{$pid}/inspirations"],
	];
	$leaks = [];
	foreach ($foreign as [$method, $path, $body]) {
		[$code] = $req($method, $path, $b, $body ?? ($method === 'GET' ? null : []));
		if ($code !== 404) {
			$leaks[] = "{$method} {$path} → {$code}";
		}
	}
	[, $list] = $req('GET', '/clara/v1/projects', $b);
	$check('6. inny użytkownik: 404 na ' . count($foreign) . ' zasobach właściciela i pusta lista projektów', ! $leaks && ! in_array($pid, array_column($list['items'] ?? $list, 'id'), true), $leaks);
	[, $still] = $req('GET', "/clara/v1/projects/{$pid}", $a);
	$check('6. próby innego użytkownika niczego nie zmieniły', $still['name'] === 'H2Otwock E2E');

	/*--- 7. Brak sekretów w odpowiedziach ---*/
	$secretLeaks = array_filter([
		'sól WP' => str_contains($responses, wp_salt('auth')),
		'klucz Anthropic' => (bool) preg_match('/sk-ant-[A-Za-z0-9_-]{10,}/', $responses),
		'token Figmy' => (bool) preg_match('/figd_[A-Za-z0-9_-]{10,}/', $responses),
		'nagłówek x-api-key' => stripos($responses, 'x-api-key') !== false,
		'hasło' => stripos($responses, 'user_pass') !== false,
		'e-mail właściciela' => str_contains($responses, get_userdata($a)->user_email),
	]);
	$check('7. żadna odpowiedź REST nie zawiera sekretów ani e-maila', ! $secretLeaks, array_keys($secretLeaks));
} finally {
	remove_filter('clara/llm_provider', $noLlm);
	remove_filter('clara/anthropic_api_key', $noKey);
	remove_filter('clara/image_spawn_cron', $noSpawn);
	global $wpdb;
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

WP_CLI::log("E2E H2Otwock: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
