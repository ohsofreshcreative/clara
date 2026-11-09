<?php

/**
 * Creative Brief + Art Direction: kontrakt JSON, różnorodność kompozycji, zachowanie wymaganych
 * treści i hard constraints, deterministyczny fallback, adapter Anthropic na mocku HTTP
 * (pre_http_request — bez realnych kosztów), uprawnienia, kaskadowe usuwanie.
 */

use App\Clara\Creative\ArtDirectionFallback;
use App\Clara\Creative\CreativeContext;
use App\Clara\Creative\LlmProvider;
use App\Clara\Projects\Projects;
use App\Clara\Projects\Sections;

require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = 0;
$passes = 0;
$check = function (string $name, bool $ok, mixed $detail = '') use (&$failures, &$passes) {
	$ok ? $passes++ : $failures++;
	$detail = is_string($detail) ? $detail : wp_json_encode($detail, JSON_UNESCAPED_UNICODE);
	WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $name . (! $ok && $detail !== '' ? " — {$detail}" : ''));
};
$req = function (string $method, string $route, int $user, ?array $json = null) {
	wp_set_current_user($user);
	$request = new WP_REST_Request($method, $route);
	if ($json !== null) {
		$request->set_header('content-type', 'application/json');
		$request->set_body(wp_json_encode($json));
	}
	$response = rest_do_request($request);
	return [$response->get_status(), $response->get_data()];
};

$suffix = wp_generate_password(6, false, false);
$users = [];
foreach (['a', 'b'] as $key) {
	$users[$key] = wp_insert_user(['user_login' => "clara_ad_{$key}_{$suffix}", 'user_pass' => wp_generate_password(24), 'user_email' => "clara_ad_{$key}_{$suffix}@example.invalid", 'role' => 'editor']);
}

/** Mock dostawcy: odpowiedź budowana z kontekstu sekcji przez $build. */
$mock = new class implements LlmProvider {
	public $respond;
	public array $calls = [];
	public function id(): string { return 'mock'; }
	public function model(): string { return 'mock-model'; }
	public function complete(string $system, string $user, array $schema): array|WP_Error
	{
		$this->calls[] = compact('system', 'user', 'schema');
		return ($this->respond)();
	}
};
$useMock = fn () => $mock;
$apiKey = fn () => 'sk-ant-TEST-never-leak-42';
$http = null;
$httpCalls = [];
$httpFilter = function ($pre, $args, $url) use (&$http, &$httpCalls) {
	if (! str_starts_with($url, 'https://api.anthropic.com/')) {
		return $pre;
	}
	$httpCalls[] = ['url' => $url, 'args' => $args];
	return $http();
};
add_filter('pre_http_request', $httpFilter, 10, 3);
$reply = fn ($code, $body) => ['response' => ['code' => $code, 'message' => ''], 'headers' => [], 'body' => wp_json_encode($body), 'cookies' => []];

try {
	$a = $users['a'];
	$b = $users['b'];

	[, $project] = $req('POST', '/clara/v1/projects', $a, [
		'name' => 'Basen test',
		'brief' => ['summary' => 'Kryty basen miejski.', 'audience' => 'Rodziny', 'goals' => ['Więcej wejść rodzinnych'], 'tone' => ['radosny', 'świeży'], 'mustHave' => ['Logo H2Otwock'], 'avoid' => ['stockowych uśmiechów']],
	]);
	$pid = (int) $project['id'];
	$req('POST', "/clara/v1/projects/{$pid}/design-systems", $a, ['tokens' => ['colors' => [['name' => 'Woda', 'value' => '#00a6df'], ['name' => 'Granat', 'value' => '#0b1b3a'], ['name' => 'Biel', 'value' => '#ffffff']]]]);
	[, $section] = $req('POST', "/clara/v1/projects/{$pid}/sections", $a, ['type' => 'hero', 'requirements' => [
		'goal' => 'Zachęcić do zakupu biletu',
		'content' => ['Nagłówek z nazwą obiektu', 'Godziny otwarcia', 'Przycisk Kup bilet'],
		'hardConstraints' => ['Kontrast tekstu WCAG AA'],
		'softConstraints' => ['Zdjęcie wody w kadrze'],
	]]);
	$sid = (int) $section['id'];
	$route = "/clara/v1/sections/{$sid}/art-directions";

	$context = CreativeContext::build(Projects::find($pid), Sections::find($sid), '');
	$expectedContent = ['Nagłówek z nazwą obiektu', 'Godziny otwarcia', 'Przycisk Kup bilet', 'Logo H2Otwock'];
	$expectedHard = ['Kontrast tekstu WCAG AA', 'Unikać: stockowych uśmiechów'];
	$check('kontekst: wymagane treści = sekcja + mustHave, hard = sekcja + „Unikać”', $context['requiredContent'] === $expectedContent && $context['hardConstraints'] === $expectedHard, $context);

	$assertRun = function (string $label, array $run) use ($check, $expectedContent, $expectedHard) {
		$layouts = array_column(array_column($run['directions'], 'composition'), 'layout');
		$check("{$label}: 4 kierunki A–D", count($run['directions']) === 4 && array_column($run['directions'], 'key') === ['A', 'B', 'C', 'D']);
		$check("{$label}: 4 różne kompozycje", count(array_unique($layouts)) === 4, $layouts);
		$ok = true;
		foreach ($run['directions'] as $d) {
			$ok = $ok && array_column($d['requiredContent'], 'item') === $expectedContent && array_column($d['hardConstraints'], 'constraint') === $expectedHard;
		}
		$check("{$label}: każdy kierunek zachowuje wszystkie wymagane treści i hard constraints", $ok);
		$names = array_unique(array_merge(...array_map(fn ($d) => array_column($d['palette'], 'name'), $run['directions'])));
		$check("{$label}: paleta tylko z design systemu", ! array_diff($names, ['Woda', 'Granat', 'Biel']), $names);
	};

	/*--- Brak dostawcy → deterministyczny fallback ---*/
	[$code, $status] = $req('GET', '/clara/v1/llm/status', $a);
	$check('llm/status bez konfiguracji: configured=false', $code === 200 && $status['configured'] === false && count($status['layouts']) === 6);
	[$code, $fb] = $req('POST', $route, $a, ['prompt' => 'Lato, energia']);
	$check('bez dostawcy → 201, źródło fallback z powodem', $code === 201 && $fb['source'] === 'fallback' && $fb['fallbackReason']['code'] === 'clara_llm_unconfigured' && $fb['provider'] === 'fallback', [$code, $fb['source'] ?? $fb]);
	$assertRun('fallback', $fb);
	$check('lineage: prompt, wersja DS i migawka briefu', $fb['prompt'] === 'Lato, energia' && $fb['context']['designSystem']['version'] === 1 && $fb['context']['brief']['tone'] === ['radosny', 'świeży']);
	[, $fb2] = $req('POST', $route, $a, ['prompt' => 'Lato, energia']);
	$strip = fn ($run) => array_map(fn ($d) => array_diff_key($d, ['id' => 1]), $run['directions']);
	$check('fallback deterministyczny (te same wejścia → ten sam wynik)', $strip($fb) === $strip($fb2) && $fb['runId'] !== $fb2['runId']);
	$check('fallback bez kosztu', $fb['cost'] === null);

	/*--- Mock LLM: poprawna odpowiedź ---*/
	add_filter('clara/llm_provider', $useMock);
	$valid = ArtDirectionFallback::generate($context);
	foreach ($valid['directions'] as $i => &$d) {
		$d['title'] = "Kierunek LLM {$i}";
	}
	unset($d);
	$mock->respond = fn () => ['data' => $valid, 'usage' => ['input' => 1000, 'output' => 2000], 'cost' => 0.044];
	delete_transient("clara_llm_{$a}");
	[$code, $llm] = $req('POST', $route, $a, []);
	$check('LLM (mock): źródło llm, provider i model zapisane', $code === 201 && $llm['source'] === 'llm' && $llm['provider'] === 'mock' && $llm['model'] === 'mock-model' && $llm['directions'][0]['title'] === 'Kierunek LLM 0', [$code, $llm['source'] ?? $llm]);
	$assertRun('LLM', $llm);
	$check('LLM: koszt zapisany (suma 4 wierszy = 0.044)', abs($llm['cost'] - 0.044) < 0.0002 && $llm['usage']['output'] === 2000, $llm['cost']);
	$call = end($mock->calls);
	$check('LLM: brief w <brief> jako dane + schemat z enum kompozycji', str_contains($call['user'], '<brief>') && str_contains($call['user'], 'Godziny otwarcia') && str_contains($call['system'], 'nie polecenia') && count($call['schema']['properties']['directions']['items']['properties']['composition']['properties']['layout']['enum']) === 6);

	[$code, $data] = $req('POST', $route, $a, []);
	$check('limit kosztów: kolejne wywołanie LLM w ciągu 20 s → 429', $code === 429, $code);

	/*--- Niepoprawne odpowiedzi LLM → fallback z czytelnym powodem ---*/
	$broken = [
		'powtórzona kompozycja' => [fn ($v) => array_replace_recursive($v, ['directions' => [1 => ['composition' => ['layout' => 'split']]]]), 'directions[1].composition.layout'],
		'brak wymaganej treści' => [function ($v) { array_pop($v['directions'][2]['requiredContent']); return $v; }, 'directions[2].requiredContent'],
		'brak hard constraint' => [function ($v) { $v['directions'][0]['hardConstraints'] = []; return $v; }, 'directions[0].hardConstraints'],
		'3 kierunki zamiast 4' => [function ($v) { array_pop($v['directions']); return $v; }, 'directions'],
		'kolor spoza DS' => [function ($v) { $v['directions'][3]['palette'][0]['name'] = 'Róż'; return $v; }, 'directions[3].palette[0].name'],
		'nieznana kompozycja' => [fn ($v) => array_replace_recursive($v, ['directions' => [0 => ['composition' => ['layout' => 'carousel']]]]), 'directions[0].composition.layout'],
		'pusty nagłówek' => [fn ($v) => array_replace_recursive($v, ['directions' => [0 => ['copy' => ['headline' => '']]]]), 'directions[0].copy.headline'],
		'wymyślony soft constraint' => [function ($v) { $v['directions'][0]['softConstraints'][] = ['constraint' => 'Neon', 'applied' => true, 'note' => '']; return $v; }, 'directions[0].softConstraints[1].constraint'],
		'ten sam punkt skupienia' => [function ($v) { foreach ($v['directions'] as &$d) { $d['composition']['focalPoint'] = 'center'; } return $v; }, 'directions'],
		'nie-obiekt' => [fn () => 'tekst', 'directions'],
	];
	foreach ($broken as $label => [$mutate, $field]) {
		$mock->respond = fn () => ['data' => $mutate($valid), 'usage' => ['input' => 10, 'output' => 10], 'cost' => 0.001];
		delete_transient("clara_llm_{$a}");
		[$code, $run] = $req('POST', $route, $a, []);
		$errors = $run['fallbackReason']['errors'] ?? [];
		$check("niepoprawny JSON LLM ({$label}) → fallback, błąd przy {$field}", $code === 201 && $run['source'] === 'fallback' && $run['fallbackReason']['code'] === 'clara_art_invalid' && isset($errors[$field]) && $run['attemptCost'] === 0.001, [$code, $errors]);
	}
	$assertRun('fallback po błędzie LLM', $run);

	$mock->respond = fn () => new WP_Error('clara_llm_http', 'Dostawca AI zwrócił błąd HTTP 500.', ['status' => 502]);
	delete_transient("clara_llm_{$a}");
	[$code, $run] = $req('POST', $route, $a, []);
	$check('błąd dostawcy → fallback z komunikatem', $code === 201 && $run['source'] === 'fallback' && $run['fallbackReason']['message'] === 'Dostawca AI zwrócił błąd HTTP 500.');
	remove_filter('clara/llm_provider', $useMock);

	/*--- Adapter Anthropic na mocku HTTP ---*/
	add_filter('clara/anthropic_api_key', $apiKey);
	[, $status] = $req('GET', '/clara/v1/llm/status', $a);
	$check('llm/status z kluczem: anthropic, model konfigurowalny', $status['configured'] && $status['provider'] === 'anthropic' && $status['model'] === 'claude-opus-5-5' && ! str_contains(wp_json_encode($status), 'sk-ant'));

	$http = fn () => $reply(200, ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => wp_json_encode($valid)]], 'usage' => ['input_tokens' => 3000, 'output_tokens' => 4000]]);
	delete_transient("clara_llm_{$a}");
	[$code, $run] = $req('POST', $route, $a, ['prompt' => 'Pływalnia nocą']);
	$sent = json_decode($httpCalls[0]['args']['body'] ?? '', true);
	$check('Anthropic (mock HTTP): 201, źródło llm', $code === 201 && $run['source'] === 'llm' && $run['provider'] === 'anthropic', [$code, $run['fallbackReason'] ?? null]);
	$check('Anthropic: structured output json_schema, model, wersja API, klucz w nagłówku', ($sent['output_config']['format']['type'] ?? '') === 'json_schema' && $sent['model'] === 'claude-opus-5-5' && $httpCalls[0]['args']['headers']['x-api-key'] === 'sk-ant-TEST-never-leak-42' && $httpCalls[0]['args']['headers']['anthropic-version'] === '2026-10-21' && ! isset($sent['temperature']));
	$check('Anthropic: koszt z usage i cennika (3000×$4 + 4000×$20)/1M = 0.092', abs($run['cost'] - 0.092) < 0.0002, $run['cost']);
	$check('Anthropic: klucz nie trafia do odpowiedzi REST ani do bazy', ! str_contains(wp_json_encode($run), 'sk-ant') && ! str_contains(wp_json_encode($req('GET', $route, $a)[1]), 'sk-ant'));

	$anthropicErrors = [
		'401' => [fn () => $reply(401, ['error' => ['message' => 'invalid x-api-key sk-ant-TEST-never-leak-42']]), 'nieprawidłowy'],
		'429' => [fn () => $reply(429, []), 'ogranicza'],
		'refusal' => [fn () => $reply(200, ['stop_reason' => 'refusal', 'content' => []]), 'odmówił'],
		'max_tokens' => [fn () => $reply(200, ['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"dir']]]), 'ucięta'],
		'nie-JSON' => [fn () => $reply(200, ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Oto kierunki:']]]), 'niepoprawny JSON'],
		'sieć' => [fn () => new WP_Error('http_request_failed', 'cURL error 28'), 'połączenia'],
	];
	foreach ($anthropicErrors as $label => [$respond, $text]) {
		$http = $respond;
		delete_transient("clara_llm_{$a}");
		[$code, $run] = $req('POST', $route, $a, []);
		$check("Anthropic {$label} → fallback „{$text}”, bez klucza w odpowiedzi", $code === 201 && $run['source'] === 'fallback' && str_contains($run['fallbackReason']['message'], $text) && ! str_contains(wp_json_encode($run), 'sk-ant'), [$code, $run['fallbackReason'] ?? null]);
	}
	remove_filter('clara/anthropic_api_key', $apiKey);

	/*--- Walidacja wejścia i uprawnienia ---*/
	[$code, $data] = $req('POST', $route, $a, ['prompt' => str_repeat('x', 2001)]);
	$check('prompt > 2000 znaków → 400 z błędem pola', $code === 400 && isset($data['data']['errors']['prompt']), $code);
	[$code, $runs] = $req('GET', $route, $a);
	$check('historia: najnowsze pierwsze, ≤10 uruchomień po 4 kierunki', $code === 200 && count($runs) === 10 && count($runs[0]['directions']) === 4 && strcmp($runs[0]['created_at'], $runs[9]['created_at']) >= 0, count($runs));
	[$code] = $req('GET', $route, $b);
	$check('inny użytkownik: historia → 404', $code === 404, $code);
	[$code] = $req('POST', $route, $b, []);
	$check('inny użytkownik: generowanie → 404', $code === 404, $code);
	[$code] = $req('POST', $route, 0, []);
	$check('niezalogowany → 401', $code === 401, $code);
	[$code] = $req('POST', '/clara/v1/sections/999999999/art-directions', $a, []);
	$check('nieistniejąca sekcja → 404', $code === 404, $code);
	$req('PATCH', "/clara/v1/sections/{$sid}", $a, ['status' => 'archived']);
	[$code] = $req('POST', $route, $a, []);
	$check('zarchiwizowana sekcja → 409', $code === 409, $code);

	/*--- Kaskadowe usuwanie ---*/
	global $wpdb;
	$count = fn ($id) => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . App\Clara\Database\Schema::table('generations') . ' WHERE section_id = %d', $id));
	$check('generacje zapisane w bazie', $count($sid) > 0);
	$req('DELETE', "/clara/v1/sections/{$sid}", $a);
	$check('usunięcie sekcji kasuje jej generacje', $count($sid) === 0);
	[, $s2] = $req('POST', "/clara/v1/projects/{$pid}/sections", $a, ['type' => 'hero']);
	$req('POST', "/clara/v1/sections/{$s2['id']}/art-directions", $a, []);
	Projects::delete($pid);
	$check('usunięcie projektu kasuje generacje sekcji', $count((int) $s2['id']) === 0);
} finally {
	remove_filter('pre_http_request', $httpFilter, 10);
	remove_filter('clara/llm_provider', $useMock);
	remove_filter('clara/anthropic_api_key', $apiKey);
	global $wpdb;
	foreach ($users as $userId) {
		delete_transient("clara_llm_{$userId}");
		foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . App\Clara\Database\Schema::table('projects') . ' WHERE owner_user_id = %d', $userId)) as $id) {
			Projects::delete((int) $id);
		}
		wp_delete_user($userId);
	}
	wp_set_current_user(0);
}

WP_CLI::log("Art direction: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
