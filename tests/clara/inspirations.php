<?php

/**
 * Inspiration Finder i kolekcje: adapter wyszukiwania (mock + Openverse na mocku HTTP), puste wyniki,
 * błędy, sanityzacja, zapis po tokenie (licencja wyłącznie od dostawcy), ręczne linki bez licencji,
 * deduplikacja, uprawnienia, limity, kaskady. Bez ruchu do realnych serwisów.
 */

use App\Clara\Inspirations\Inspirations;
use App\Clara\Inspirations\MockSearchProvider;
use App\Clara\Inspirations\OpenverseProvider;
use App\Clara\Projects\Projects;

require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = 0;
$passes = 0;
$check = function (string $name, bool $ok, mixed $detail = '') use (&$failures, &$passes) {
	$ok ? $passes++ : $failures++;
	$detail = is_string($detail) ? $detail : wp_json_encode($detail, JSON_UNESCAPED_UNICODE);
	WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $name . (! $ok && $detail !== '' ? " — {$detail}" : ''));
};
$req = function (string $method, string $route, int $user, ?array $json = null, array $query = []) {
	wp_set_current_user($user);
	$request = new WP_REST_Request($method, $route);
	$request->set_query_params($query);
	if ($json !== null) {
		$request->set_header('content-type', 'application/json');
		$request->set_body(wp_json_encode($json));
	}
	$response = rest_do_request($request);
	return [$response->get_status(), $response->get_data()];
};

$useMock = fn () => new MockSearchProvider();
$useOpenverse = fn () => new OpenverseProvider();
$http = null;
$httpCalls = [];
$httpFilter = function ($pre, $args, $url) use (&$http, &$httpCalls) {
	if (! str_starts_with($url, 'https://api.openverse.org/')) {
		return $pre;
	}
	$httpCalls[] = ['url' => $url, 'args' => $args];
	return $http();
};
add_filter('pre_http_request', $httpFilter, 10, 3);
$reply = fn ($code, $body) => ['response' => ['code' => $code, 'message' => ''], 'headers' => [], 'body' => is_string($body) ? $body : wp_json_encode($body), 'cookies' => []];

$suffix = wp_generate_password(6, false, false);
$users = [];
foreach (['a', 'b'] as $key) {
	$users[$key] = wp_insert_user(['user_login' => "clara_ins_{$key}_{$suffix}", 'user_pass' => wp_generate_password(24), 'user_email' => "clara_ins_{$key}_{$suffix}@example.invalid", 'role' => 'editor']);
}
$clearTransients = function () {
	global $wpdb;
	$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_clara\\_search\\_%' OR option_name LIKE '\\_transient\\_timeout\\_clara\\_search\\_%' OR option_name LIKE '\\_transient\\_clara\\_insp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_clara\\_insp\\_%'");
	wp_cache_flush();
};

try {
	$a = $users['a'];
	$b = $users['b'];
	$clearTransients();
	[, $project] = $req('POST', '/clara/v1/projects', $a, ['name' => 'Inspiracje test']);
	$pid = (int) $project['id'];
	[, $section] = $req('POST', "/clara/v1/projects/{$pid}/sections", $a, ['type' => 'hero']);
	$sid = (int) $section['id'];
	$coll = "/clara/v1/projects/{$pid}/inspirations";

	/*--- Mock: wyniki, puste wyniki, błąd ---*/
	add_filter('clara/search_provider', $useMock);
	[$code, $status] = $req('GET', '/clara/v1/search/status', $a);
	$check('search/status: mock oznaczony', $code === 200 && $status['mock'] === true);
	[$code, $res] = $req('GET', '/clara/v1/search', $a, null, ['q' => 'basen hero', 'project' => $pid]);
	$check('wyszukiwanie → wyniki z tokenem, URL, atrybucją i datą', $code === 200 && $res['total'] === 6 && count($res['items']) === 6 && strlen($res['items'][0]['token']) === 32 && $res['items'][0]['indexedAt'] !== '' && $res['items'][0]['saved'] === false, [$code, $res['total'] ?? $res]);
	$check('licencja tylko tam, gdzie zadeklarowana (co druga null)', $res['items'][0]['license']['name'] === 'CC BY 4.0' && $res['items'][1]['license'] === null && $res['items'][1]['creator'] === '');
	[$code, $res2] = $req('GET', '/clara/v1/search', $a, null, ['q' => 'basen hero', 'commercial' => 'true']);
	$check('filtr licencji komercyjnych pomija pozycje bez licencji', $code === 200 && count($res2['items']) === 3 && ! in_array(null, array_column($res2['items'], 'license'), true));
	[$code, $empty] = $req('GET', '/clara/v1/search', $a, null, ['q' => 'zz nic']);
	$check('puste wyniki → 200, total 0, pusta lista', $code === 200 && $empty['total'] === 0 && $empty['items'] === []);
	[$code, $err] = $req('GET', '/clara/v1/search', $a, null, ['q' => 'blad dostawcy']);
	$check('błąd dostawcy → 502 z czytelnym komunikatem', $code === 502 && $err['message'] === 'Wyszukiwarka testowa zwróciła błąd.', [$code, $err['message'] ?? '']);

	foreach (['' => 'q', 'a' => 'q', str_repeat('x', 201) => 'q'] as $q => $field) {
		[$code, $data] = $req('GET', '/clara/v1/search', $a, null, ['q' => $q]);
		$check('walidacja zapytania „' . mb_substr($q, 0, 5) . '…” (' . mb_strlen($q) . ' zn.) → 400', $code === 400 && isset($data['data']['errors'][$field]), $code);
	}
	[$code, $data] = $req('GET', '/clara/v1/search', $a, null, ['q' => 'basen', 'page' => 11]);
	$check('strona > 10 → 400', $code === 400 && isset($data['data']['errors']['page']));
	[$code, $data] = $req('GET', '/clara/v1/search', $a, null, ['q' => 'basen', 'per_page' => 50]);
	$check('per_page > 20 → 400', $code === 400 && isset($data['data']['errors']['per_page']));

	/*--- Zapis do kolekcji, deduplikacja, licencje ---*/
	$licensed = $res['items'][0];
	$unlicensed = $res['items'][1];
	[$code, $saved] = $req('POST', $coll, $a, ['token' => $licensed['token'], 'section_id' => $sid, 'note' => 'Dobra kompozycja']);
	$ins = $saved['inspiration'];
	$check('zapis wyniku → 201 z linkiem, atrybucją, licencją od dostawcy i sekcją', $code === 201 && $ins['source_url'] === $licensed['sourceUrl'] && $ins['attribution']['creator'] === 'Autor testowy 0' && $ins['rights']['license']['name'] === 'CC BY 4.0' && $ins['rights']['declaredBy'] === 'mock' && $ins['section_id'] === $sid && $ins['metadata']['query'] === 'basen hero', [$code, $saved]);
	[$code, $saved2] = $req('POST', $coll, $a, ['token' => $unlicensed['token']]);
	$check('wynik bez licencji → zapisany z license=null (bez dopisywania)', $code === 201 && $saved2['inspiration']['rights']['license'] === null && $saved2['inspiration']['rights']['declaredBy'] === null);
	[$code, $dup] = $req('POST', $coll, $a, ['token' => $licensed['token']]);
	$check('ponowny zapis → 200 duplicate, bez nowego wiersza', $code === 200 && $dup['duplicate'] === true && $dup['inspiration']['id'] === $ins['id']);
	$req('POST', $coll, $a, ['url' => 'https://example.net/hero?id=1']);
	[$code, $dupManual] = $req('POST', $coll, $a, ['url' => 'https://EXAMPLE.net/hero?utm_source=newsletter&id=1#top']);
	$check('deduplikacja po znormalizowanym URL (host, utm, fragment)', $code === 200 && $dupManual['duplicate'] === true && $dupManual['inspiration']['source_url'] === 'https://example.net/hero?id=1', [$code, $dupManual['inspiration']['source_url'] ?? null]);
	[$code, $forged] = $req('POST', $coll, $a, ['token' => $unlicensed['token'], 'license' => ['name' => 'CC0'], 'rights' => ['license' => 'CC0']]);
	$check('klient nie może dopisać licencji (pola ignorowane)', $code === 200 && $forged['inspiration']['rights']['license'] === null);
	[, $marked] = $req('GET', '/clara/v1/search', $a, null, ['q' => 'basen hero', 'project' => $pid]);
	$check('wyniki oznaczone jako zapisane w projekcie', $marked['items'][0]['saved'] === true && $marked['items'][2]['saved'] === false);

	[$code, $manual] = $req('POST', $coll, $a, ['url' => 'https://example.com/inspiracja/?utm_campaign=x&id=5', 'title' => 'Ręczny link']);
	$check('ręczny link → licencja nieznana, provider manual, utm usunięty', $code === 201 && $manual['inspiration']['rights']['license'] === null && $manual['inspiration']['attribution']['provider'] === 'manual' && $manual['inspiration']['source_url'] === 'https://example.com/inspiracja/?id=5', [$code, $manual]);
	$badUrls = ['javascript:alert(1)', 'ftp://example.com/a', 'http://localhost/x', 'http://192.168.1.10/a', 'http://127.0.0.1/', 'https://basen.local/', 'nie-url', ''];
	foreach ($badUrls as $url) {
		[$code, $data] = $req('POST', $coll, $a, ['url' => $url]);
		$check("ręczny link „{$url}” → 400", $code === 400 && isset($data['data']['errors']['url']), [$code, $data['data']['errors'] ?? null]);
	}
	[$code] = $req('POST', $coll, $a, ['token' => str_repeat('0', 32)]);
	$check('nieznany/wygasły token → 410', $code === 410, $code);
	[, $other] = $req('POST', '/clara/v1/projects', $b, ['name' => 'Cudzy']);
	[, $otherSection] = $req('POST', "/clara/v1/projects/{$other['id']}/sections", $b, ['type' => 'hero']);
	[$code, $data] = $req('POST', $coll, $a, ['token' => $res['items'][2]['token'], 'section_id' => $otherSection['id']]);
	$check('sekcja z innego projektu → 400', $code === 400 && isset($data['data']['errors']['section_id']), $code);

	[$code, $list] = $req('GET', $coll, $a);
	$check('kolekcja projektu: 4 pozycje, najnowsze pierwsze', $code === 200 && count($list) === 4 && $list[0]['id'] === $manual['inspiration']['id']);
	[$code, $bySection] = $req('GET', $coll, $a, null, ['section' => $sid]);
	$check('filtr kolekcji po sekcji', count($bySection) === 1 && $bySection[0]['id'] === $ins['id']);

	/*--- Uprawnienia ---*/
	[$code] = $req('GET', $coll, $b);
	$check('inny użytkownik: kolekcja → 404', $code === 404, $code);
	[$code] = $req('POST', "/clara/v1/projects/{$other['id']}/inspirations", $b, ['token' => $licensed['token']]);
	$check('token innego użytkownika → 410 (wynik niedostępny)', $code === 410, $code);
	[$code] = $req('DELETE', "/clara/v1/inspirations/{$ins['id']}", $b);
	$check('inny użytkownik: usunięcie → 404', $code === 404, $code);
	[$code] = $req('GET', '/clara/v1/search', 0, null, ['q' => 'basen']);
	$check('niezalogowany: wyszukiwanie → 401', $code === 401, $code);

	/*--- Kaskady ---*/
	$req('DELETE', "/clara/v1/sections/{$sid}", $a);
	$check('usunięcie sekcji zostawia inspirację w kolekcji projektu', Inspirations::find($ins['id'])['section_id'] === null);
	[$code] = $req('DELETE', "/clara/v1/inspirations/{$manual['inspiration']['id']}", $a);
	$check('usunięcie inspiracji', $code === 200 && Inspirations::find($manual['inspiration']['id']) === null);
	remove_filter('clara/search_provider', $useMock);

	/*--- Adapter Openverse na mocku HTTP ---*/
	add_filter('clara/search_provider', $useOpenverse);
	$clearTransients();
	$http = fn () => $reply(200, ['result_count' => 3, 'results' => [
		['id' => 'ov-1', 'title' => 'Pool <b>blue</b>', 'foreign_landing_url' => 'https://www.flickr.com/photos/x/1', 'thumbnail' => 'https://api.openverse.org/v1/images/ov-1/thumb/', 'creator' => 'Ann', 'creator_url' => 'https://www.flickr.com/photos/x', 'license' => 'by-sa', 'license_version' => '2.0', 'license_url' => 'https://creativecommons.org/licenses/by-sa/2.0/', 'source' => 'flickr', 'indexed_on' => '2020-04-07T08:22:23Z', 'width' => 1024, 'height' => 768],
		['id' => 'ov-2', 'title' => 'Bez licencji', 'foreign_landing_url' => 'https://example.org/2', 'thumbnail' => 'javascript:alert(1)', 'license' => '', 'source' => 'x'],
		['id' => 'ov-3', 'title' => 'Zły link', 'foreign_landing_url' => 'javascript:alert(1)', 'license' => 'cc0'],
	]]);
	[$code, $ov] = $req('GET', '/clara/v1/search', $a, null, ['q' => 'basen', 'commercial' => '1']);
	$sent = $httpCalls[0]['url'] ?? '';
	$check('Openverse: tylko api.openverse.org, bez treści dojrzałych, filtr komercyjny', str_starts_with($sent, 'https://api.openverse.org/v1/images/?') && str_contains($sent, 'mature=false') && str_contains($sent, 'license_type=commercial') && $httpCalls[0]['args']['redirection'] === 0);
	$check('Openverse: mapowanie licencji CC BY-SA 2.0 i atrybucji', $code === 200 && $ov['items'][0]['license']['name'] === 'CC BY-SA 2.0' && $ov['items'][0]['creator'] === 'Ann' && $ov['items'][0]['title'] === 'Pool blue', $ov['items'][0] ?? $ov);
	$check('Openverse: brak licencji → null, zła miniatura usunięta, wynik bez poprawnego linku odrzucony', count($ov['items']) === 2 && $ov['items'][1]['license'] === null && $ov['items'][1]['thumbnailUrl'] === '');
	$calls = count($httpCalls);
	$req('GET', '/clara/v1/search', $a, null, ['q' => 'basen', 'commercial' => '1']);
	$check('cache: powtórne zapytanie bez ruchu do API', count($httpCalls) === $calls);

	$errors = [
		'429' => [fn () => $reply(429, []), 429, 'ogranicza'],
		'500' => [fn () => $reply(500, 'err'), 502, 'HTTP 500'],
		'nie-JSON' => [fn () => $reply(200, '<html>'), 502, 'nieczytelną'],
		'sieć' => [fn () => new WP_Error('http_request_failed', 'timeout'), 502, 'połączyć'],
		'400 (poza zakresem)' => [fn () => $reply(400, ['detail' => 'page']), 200, ''],
	];
	$i = 0;
	foreach ($errors as $label => [$respond, $expected, $text]) {
		$http = $respond;
		[$code, $data] = $req('GET', '/clara/v1/search', $a, null, ['q' => 'unikalne ' . $i++]);
		$check("Openverse {$label} → {$expected}", $code === $expected && ($text === '' ? $data['items'] === [] : str_contains($data['message'], $text)), [$code, $data['message'] ?? $data]);
	}

	// Limit zapytań: 20/min na użytkownika (cache nie liczy się do limitu).
	$http = fn () => $reply(200, ['result_count' => 0, 'results' => []]);
	$hit = null;
	for ($n = 0; $n < 25 && $hit === null; $n++) {
		[$code] = $req('GET', '/clara/v1/search', $a, null, ['q' => "limit {$n}"]);
		if ($code === 429) {
			$hit = $n;
		}
	}
	$check('limit wyszukiwań → 429 najpóźniej po 20 zapytaniach/min', $hit !== null && $hit <= 20, $hit);
	remove_filter('clara/search_provider', $useOpenverse);

	Projects::delete($pid);
	$check('usunięcie projektu kasuje kolekcję', Inspirations::forProject($pid) === []);
} finally {
	remove_filter('pre_http_request', $httpFilter, 10);
	remove_filter('clara/search_provider', $useMock);
	remove_filter('clara/search_provider', $useOpenverse);
	global $wpdb;
	foreach ($users as $userId) {
		delete_transient("clara_search_rl_{$userId}");
		foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . App\Clara\Database\Schema::table('projects') . ' WHERE owner_user_id = %d', $userId)) as $id) {
			Projects::delete((int) $id);
		}
		wp_delete_user($userId);
	}
	$clearTransients();
	wp_set_current_user(0);
}

WP_CLI::log("Inspiracje: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
