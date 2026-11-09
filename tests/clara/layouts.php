<?php

/**
 * Visual-to-Layout JSON: heurystyka na obrazach mock, wymagane treści jako tekst, rozdział
 * confidence i bitmap, walidator (błędne dane), wersje i ręczne poprawki, analizator wizyjny
 * na mocku HTTP (bez realnych kosztów) z fallbackiem, uprawnienia, kaskady.
 */

use App\Clara\Creative\ImageJobs;
use App\Clara\Database\Schema;
use App\Clara\Layout\LayoutSchema;
use App\Clara\Layout\VisionLayoutAnalyzer;
use App\Clara\Projects\Projects;

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

$noLlm = fn () => null;
$noKey = fn () => '';
$noSpawn = fn () => false;
add_filter('clara/llm_provider', $noLlm);
add_filter('clara/anthropic_api_key', $noKey);
add_filter('clara/image_spawn_cron', $noSpawn);

$suffix = wp_generate_password(6, false, false);
$users = [];
foreach (['a', 'b'] as $key) {
	$users[$key] = wp_insert_user(['user_login' => "clara_lay_{$key}_{$suffix}", 'user_pass' => wp_generate_password(24), 'user_email' => "clara_lay_{$key}_{$suffix}@example.invalid", 'role' => 'editor']);
}
global $wpdb;
$docs = Schema::table('layout_documents');
$http = null;
$httpCalls = [];
$httpFilter = function ($pre, $args, $url) use (&$http, &$httpCalls) {
	if ($url !== 'https://api.anthropic.com/v1/messages') {
		return $pre;
	}
	$httpCalls[] = json_decode($args['body'], true);
	return $http();
};
add_filter('pre_http_request', $httpFilter, 10, 3);
$reply = fn (int $code, array $data) => ['response' => ['code' => $code, 'message' => ''], 'headers' => [], 'cookies' => [], 'body' => wp_json_encode($code === 200 ? ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => wp_json_encode($data)]], 'usage' => ['input_tokens' => 2000, 'output_tokens' => 500]] : $data)];

try {
	$a = $users['a'];
	$b = $users['b'];
	[, $project] = $req('POST', '/clara/v1/projects', $a, ['name' => 'H2Otwock layout', 'brief' => ['summary' => 'Kryty basen.']]);
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
	$byKey = array_column($gallery['items'], null, 'directionKey');
	$img = $byKey['A'];
	$route = "/clara/v1/images/{$img['id']}/layouts";

	/*--- Happy path: heurystyka ---*/
	[$code, $status] = $req('GET', '/clara/v1/layouts/status', $a);
	$check('layouts/status: heurystyka bez klucza, schemat v1', $code === 200 && $status['heuristic'] === true && $status['schemaVersion'] === 1);
	[$code, $doc] = $req('POST', $route, $a, []);
	$layout = $doc['layout'] ?? [];
	$nodes = array_column($layout['nodes'] ?? [], null, 'id');
	$texts = array_values(array_filter($layout['nodes'] ?? [], fn ($n) => in_array($n['type'], ['text', 'button'], true)));
	$check('analiza → 201, Layout JSON v1, płótno 1440×810 zgodne z proporcją obrazu', $code === 201 && $doc['schema_version'] === 1 && $layout['schemaVersion'] === 1 && $layout['canvas'] === ['width' => 1440, 'height' => 810, 'background' => $layout['canvas']['background']], [$code, $doc['message'] ?? $layout['canvas'] ?? null]);
	$check('walidacja zapisanego dokumentu bez błędów', LayoutSchema::validate($layout, ['requiredTexts' => ['Basen H2Otwock', 'Otwarte codziennie 6:00–22:00']])['errors'] === [] && $doc['validation']['valid'] === true);
	$contents = array_column($texts, 'content');
	$check('tekst pozostaje tekstem: wymagane treści jako węzły text, CTA jako button', in_array('Basen H2Otwock', $contents, true) && in_array('Otwarte codziennie 6:00–22:00', $contents, true) && in_array('button', array_column($texts, 'type'), true) && ! array_filter($texts, fn ($t) => $t['rasterized']), $contents);
	$check('obraz rozdzielony: kadr wizualny jako bitmapa z powodem w rasterizedRegions', $nodes['visual']['type'] === 'image' && $nodes['visual']['rasterized'] === true && $nodes['visual']['assetRef']['generationId'] === $img['id'] && $layout['rasterizedRegions'][0]['nodeId'] === 'visual' && $layout['rasterizedRegions'][0]['reason'] !== '');
	$check('split: kadr z prawej połowy obrazu, tekst w lewej kolumnie (Auto Layout pionowy)', $nodes['visual']['assetRef']['crop']['x'] === 624 && $nodes['content']['layoutMode'] === 'vertical' && $nodes['content']['frame']['x'] < 720);
	$check('confidence per węzeł i podsumowanie (edytowalne vs bitmapy)', $doc['validation']['summary']['rasterizedNodes'] === 1 && $doc['validation']['summary']['textNodes'] === count($texts) && $doc['validation']['summary']['editableNodes'] === count($layout['nodes']) - 1 && is_float($doc['validation']['summary']['avgConfidence']) && ! array_filter($layout['nodes'], fn ($n) => ! is_numeric($n['confidence'])));
	$check('kroje z design systemu (Poppins nagłówek, Inter treść)', $texts[0]['style']['fontFamily'] === 'Poppins' && $texts[1]['style']['fontFamily'] === 'Inter');
	$minContrast = min(array_map(fn ($t) => App\Clara\Layout\HeuristicLayoutAnalyzer::contrast($t['style']['color'], $t['type'] === 'button' ? $t['style']['fill'] : $layout['canvas']['background']), array_filter($texts, fn ($t) => $t['type'] === 'button')));
	$check('kontrast CTA ≥ 4.5:1', $minContrast >= 4.5, $minContrast);
	$check('ostrzeżenia: heurystyka i obraz mock jawnie opisane', (bool) array_filter($layout['warnings'], fn ($w) => str_contains($w, 'heurystycznie')) && (bool) array_filter($layout['warnings'], fn ($w) => str_contains($w, 'mock')));
	$check('powiązanie ze źródłem i zasoby podglądu', $layout['source']['generationId'] === $img['id'] && isset($doc['assets'][(string) $img['id']]['url']) && $doc['assets'][(string) $img['id']]['width'] === 1200);

	$frames = [];
	foreach (['A', 'B', 'C', 'D'] as $key) {
		[, $d] = $req('POST', "/clara/v1/images/{$byKey[$key]['id']}/layouts", $a, []);
		$visual = array_column($d['layout']['nodes'], null, 'id')['visual'];
		$frames[$key] = $visual['frame'];
		$overlay[$key] = isset(array_column($d['layout']['nodes'], null, 'id')['overlay']);
	}
	$check('4 kompozycje → 4 różne układy (kadry wizualne)', count(array_unique(array_map('wp_json_encode', $frames))) === 4 && $overlay['B'] === true, $frames);

	[$code, $list] = $req('GET', $route, $a);
	$check('wersje: lista bez pełnego JSON, 2 wersje dla A', $code === 200 && count($list) === 2 && ! isset($list[0]['layout']));
	[$code, $shown] = $req('GET', "/clara/v1/layouts/{$doc['id']}", $a);
	$check('odczyt dokumentu z zasobami', $code === 200 && $shown['layout'] === $layout && isset($shown['assets']));

	/*--- Walidator odrzuca błędne dane ---*/
	$mutate = function (callable $fn) use ($layout) {
		$l = $layout;
		$fn($l);
		return $l;
	};
	$idx = fn (string $id) => array_search($id, array_column($layout['nodes'], 'id'), true);
	$textIdx = array_search('text', array_column($layout['nodes'], 'type'), true);
	$cases = [
		'brak schemaVersion' => [fn (&$l) => $l['schemaVersion'] = null, 'schemaVersion'],
		'nieznane pole najwyższego poziomu' => [fn (&$l) => $l['script'] = 'x', 'script'],
		'brak płótna' => [function (&$l) { unset($l['canvas']); }, 'canvas'],
		'płótno 100 px' => [fn (&$l) => $l['canvas']['width'] = 100, 'canvas.width'],
		'pusta lista węzłów' => [fn (&$l) => $l['nodes'] = [], 'nodes'],
		'dwa korzenie' => [fn (&$l) => $l['nodes'][$idx('content')]['parentId'] = null, 'nodes'],
		'powtórzony id' => [fn (&$l) => $l['nodes'][$idx('content')]['id'] = 'visual', 'nodes.' . $idx('content') . '.id'],
		'zły typ' => [fn (&$l) => $l['nodes'][$idx('visual')]['type'] = 'video', 'nodes.' . $idx('visual') . '.type'],
		'rodzic nie istnieje' => [fn (&$l) => $l['nodes'][$idx('visual')]['parentId'] = 'brak', 'nodes.' . $idx('visual') . '.parentId'],
		'rodzicem jest obraz' => [fn (&$l) => $l['nodes'][$idx('content')]['parentId'] = 'visual', 'nodes.' . $idx('content') . '.parentId'],
		'cykl w drzewie' => [function (&$l) use ($idx) { $l['nodes'][] = ['id' => 'loop-a', 'type' => 'frame', 'parentId' => 'loop-b', 'order' => 9, 'frame' => ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10], 'style' => [], 'layoutMode' => 'none', 'confidence' => 1, 'rasterized' => false]; $l['nodes'][] = ['id' => 'loop-b', 'type' => 'frame', 'parentId' => 'loop-a', 'order' => 10, 'frame' => ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10], 'style' => [], 'layoutMode' => 'none', 'confidence' => 1, 'rasterized' => false]; }, 'nodes.' . (count($layout['nodes'])) . '.parentId'],
		'ramka poza płótnem' => [fn (&$l) => $l['nodes'][$idx('visual')]['frame']['width'] = 5000, 'nodes.' . $idx('visual') . '.frame'],
		'ujemna szerokość' => [fn (&$l) => $l['nodes'][$idx('content')]['frame']['width'] = -5, 'nodes.' . $idx('content') . '.frame'],
		'tekst bez treści' => [fn (&$l) => $l['nodes'][$textIdx]['content'] = '  ', "nodes.{$textIdx}.content"],
		'tekst rasteryzowany' => [fn (&$l) => $l['nodes'][$textIdx]['rasterized'] = true, "nodes.{$textIdx}.rasterized"],
		'treść na obrazie' => [fn (&$l) => $l['nodes'][$idx('visual')]['content'] = 'Basen H2Otwock', 'nodes.' . $idx('visual') . '.content'],
		'obraz bez assetRef' => [function (&$l) use ($idx) { unset($l['nodes'][$idx('visual')]['assetRef']); }, 'nodes.' . $idx('visual') . '.assetRef'],
		'zły kolor' => [fn (&$l) => $l['nodes'][$textIdx]['style']['color'] = 'red; background:url(x)', "nodes.{$textIdx}.style.color"],
		'nieznana właściwość stylu' => [fn (&$l) => $l['nodes'][$textIdx]['style']['position'] = 'fixed', "nodes.{$textIdx}.style.position"],
		'fontWeight 450' => [fn (&$l) => $l['nodes'][$textIdx]['style']['fontWeight'] = 450, "nodes.{$textIdx}.style.fontWeight"],
		'font z wstrzyknięciem CSS' => [fn (&$l) => $l['nodes'][$textIdx]['style']['fontFamily'] = 'Inter;}body{', "nodes.{$textIdx}.style.fontFamily"],
		'confidence 1.5' => [fn (&$l) => $l['nodes'][$textIdx]['confidence'] = 1.5, "nodes.{$textIdx}.confidence"],
		'Auto Layout na tekście' => [fn (&$l) => $l['nodes'][$textIdx]['layoutMode'] = 'vertical', "nodes.{$textIdx}.layoutMode"],
		'bitmapa bez opisu regionu' => [fn (&$l) => $l['rasterizedRegions'] = [], 'rasterizedRegions'],
		'region wskazuje tekst' => [fn (&$l) => $l['rasterizedRegions'][] = ['nodeId' => $l['nodes'][$textIdx]['id'], 'reason' => 'x'], 'rasterizedRegions.1.nodeId'],
		'za dużo węzłów' => [fn (&$l) => $l['nodes'] = array_merge($l['nodes'], array_map(fn ($i) => ['id' => "r{$i}", 'type' => 'rectangle', 'parentId' => 'hero', 'order' => $i, 'frame' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1], 'style' => [], 'layoutMode' => 'none', 'confidence' => 1, 'rasterized' => false], range(1, 200))), 'nodes'],
	];
	foreach ($cases as $label => [$fn, $path]) {
		[$code, $res] = $req('POST', '/clara/v1/layouts/validate', $a, ['layout' => $mutate($fn)]);
		$check("walidator: {$label}", $code === 200 && $res['valid'] === false && isset($res['errors'][$path]), [$path, array_slice($res['errors'] ?? [], 0, 3, true)]);
	}
	[, $res] = $req('POST', '/clara/v1/layouts/validate', $a, ['layout' => $layout]);
	$check('walidator: poprawny layout → valid', $res['valid'] === true && $res['errors'] === []);
	[, $res] = $req('POST', '/clara/v1/layouts/validate', $a, ['layout' => '{"schemaVersion":1,']);
	$check('walidator: uszkodzony JSON (tekst) → czytelny błąd', $res['valid'] === false && str_contains($res['errors']['layout'], 'Niepoprawny JSON'));
	[, $res] = $req('POST', '/clara/v1/layouts/validate', $a, ['layout' => str_repeat(' ', LayoutSchema::MAX_BYTES + 1)]);
	$check('walidator: JSON > 512 KB odrzucony przed parsowaniem', $res['valid'] === false && str_contains($res['errors']['layout'], '512 KB'));
	$migrated = LayoutSchema::migrate(['schemaVersion' => 2]);
	$check('migracja: nowsza wersja schematu → czytelny błąd', is_wp_error($migrated) && str_contains($migrated->get_error_data()['errors']['schemaVersion'], 'nowsza'));

	/*--- Ręczne poprawki = nowa wersja ---*/
	$edited = $mutate(function (&$l) use ($textIdx) { $l['nodes'][$textIdx]['frame']['y'] += 20; });
	[$code, $manual] = $req('POST', $route, $a, ['layout' => $edited, 'basedOn' => $doc['id']]);
	$check('ręczna poprawka → nowa wersja (origin manual), poprzednia zachowana', $code === 201 && $manual['validation']['origin'] === 'manual' && $manual['validation']['basedOn'] === $doc['id'] && $req('GET', "/clara/v1/layouts/{$doc['id']}", $a)[1]['layout'] === $layout, [$code, $manual['data']['errors'] ?? null]);
	[$code, $data] = $req('POST', $route, $a, ['layout' => $mutate(function (&$l) use ($textIdx) { $l['nodes'] = array_values(array_filter($l['nodes'], fn ($n) => ($n['content'] ?? '') !== 'Otwarte codziennie 6:00–22:00')); })]);
	$check('ręczna poprawka bez wymaganej treści → 400', $code === 400 && (bool) array_filter(array_keys($data['data']['errors']), fn ($k) => str_starts_with($k, 'requiredTexts')), [$code, $data['data']['errors'] ?? null]);
	[$code, $data] = $req('POST', $route, $a, ['layout' => $mutate(fn (&$l) => $l['nodes'][$idx('visual')]['assetRef']['generationId'] = $byKey['B']['id'])]);
	$check('obraz z innego wariantu → 400', $code === 400 && isset($data['data']['errors']['nodes.' . $idx('visual') . '.assetRef']));
	[$code] = $req('POST', $route, $a, ['layout' => $layout, 'basedOn' => 999999999]);
	$check('basedOn spoza wariantu → 400', $code === 400, $code);
	[$code] = $req('POST', $route, $a, ['layout' => ['schemaVersion' => 2]]);
	$check('ręczny layout w nowszej wersji schematu → 400', $code === 400, $code);
	$check('nieudane zapisy nie tworzą wersji', count($req('GET', $route, $a)[1]) === 3);

	/*--- Analizator wizyjny na mocku HTTP ---*/
	$key = fn () => 'test-key-not-real';
	remove_filter('clara/anthropic_api_key', $noKey);
	add_filter('clara/anthropic_api_key', $key);
	$useVision = fn () => new VisionLayoutAnalyzer();
	add_filter('clara/layout_analyzer', $useVision);
	$input = App\Clara\Layout\LayoutInterpreter::input(App\Clara\Creative\Gallery::find($img['id']));
	$regions = [['kind' => 'image', 'role' => 'visual', 'textIndex' => -1, 'x' => 0.5, 'y' => 0, 'width' => 0.5, 'height' => 1, 'color' => '#00a6df', 'confidence' => 0.8, 'note' => 'Zdjęcie basenu']];
	foreach ($input['texts'] as $i => $t) {
		$regions[] = ['kind' => $t['role'] === 'cta' ? 'button' : 'text', 'role' => $t['role'], 'textIndex' => $i, 'x' => 0.06, 'y' => 0.2 + $i * 0.15, 'width' => 0.4, 'height' => 0.1, 'color' => '#ffffff', 'confidence' => 0.7, 'note' => 'ZMYŚLONY TEKST Z OBRAZU'];
	}
	$http = fn () => $reply(200, ['background' => '#0b1b3a', 'regions' => $regions, 'warnings' => ['Gradient uproszczony']]);
	[$code, $vision] = $req('POST', $route, $a, []);
	$sent = $httpCalls[0] ?? [];
	$vNodes = $vision['layout']['nodes'] ?? [];
	$check('wizja: obraz wysłany jako base64 + structured output, bez URL', ($sent['messages'][0]['content'][0]['type'] ?? '') === 'image' && ($sent['messages'][0]['content'][0]['source']['type'] ?? '') === 'base64' && ($sent['output_config']['format']['type'] ?? '') === 'json_schema' && ! str_contains(wp_json_encode($sent), 'wp-content/uploads'));
	$check('wizja: 201, analizator anthropic-vision, koszt policzony', $code === 201 && $vision['validation']['analyzer'] === 'anthropic-vision' && $vision['validation']['fallbackReason'] === null && $vision['validation']['cost'] > 0, [$code, $vision['validation'] ?? $vision]);
	$check('wizja: treści z listy sekcji, nie od modelu; bitmapa z powodem', array_column(array_filter($vNodes, fn ($n) => isset($n['content'])), 'content') === array_column($input['texts'], 'content') && ! str_contains(wp_json_encode($vision['layout']), 'ZMYŚLONY') && $vision['layout']['rasterizedRegions'][0]['reason'] === 'Zdjęcie basenu');
	[$code, $data] = $req('POST', $route, $a, []);
	$check('wizja: limit 20 s między analizami → 429', $code === 429, $code);

	$fallbacks = [
		'model pominął tekst' => [fn () => $reply(200, ['background' => '#000000', 'regions' => array_slice($regions, 0, 2), 'warnings' => []]), 'clara_layout_texts'],
		'model zdublował tekst' => [fn () => $reply(200, ['background' => '#000000', 'regions' => array_merge($regions, [$regions[1]]), 'warnings' => []]), 'clara_layout_texts'],
		'HTTP 529' => [fn () => $reply(529, ['error' => ['type' => 'overloaded_error']]), 'clara_llm_http'],
	];
	foreach ($fallbacks as $label => [$respond, $codeExpected]) {
		delete_transient("clara_layout_cd_{$a}");
		$http = $respond;
		[$code, $fb] = $req('POST', $route, $a, []);
		$check("wizja: {$label} → fallback heurystyczny z powodem", $code === 201 && $fb['validation']['analyzer'] === 'heuristic' && $fb['validation']['fallbackReason']['code'] === $codeExpected && $fb['validation']['fallbackReason']['attempt']['analyzer'] === 'anthropic-vision', [$code, $fb['validation']['fallbackReason'] ?? $fb]);
	}
	remove_filter('clara/layout_analyzer', $useVision);
	remove_filter('clara/anthropic_api_key', $key);
	add_filter('clara/anthropic_api_key', $noKey);

	/*--- Błędne źródła i uprawnienia ---*/
	[$code] = $req('POST', "/clara/v1/images/{$run['directions'][0]['id']}/layouts", $a, []);
	$check('layout z kierunku (nie obrazu) → 404', $code === 404, $code);
	$wpdb->update(Schema::table('generations'), ['status' => 'failed'], ['id' => $byKey['D']['id']]);
	[$code, $data] = $req('POST', "/clara/v1/images/{$byKey['D']['id']}/layouts", $a, []);
	$check('layout z nieudanego obrazu → 409', $code === 409 && str_contains($data['message'], 'gotowego'), $code);
	foreach ([['GET', $route, null], ['POST', $route, []], ['GET', "/clara/v1/layouts/{$doc['id']}", null]] as [$method, $r, $body]) {
		[$code] = $req($method, $r, $b, $body);
		$check("inny użytkownik: {$method} " . preg_replace('/\d+/', '{id}', $r) . ' → 404', $code === 404, $code);
		[$code] = $req($method, $r, 0, $body);
		$check("niezalogowany: {$method} " . preg_replace('/\d+/', '{id}', $r) . ' → 401', $code === 401, $code);
	}
	[$code] = $req('POST', '/clara/v1/layouts/validate', 0, ['layout' => $layout]);
	$check('niezalogowany: walidator → 401', $code === 401, $code);

	$req('DELETE', "/clara/v1/sections/{$sid}", $a);
	$check('usunięcie sekcji kasuje wersje layoutu', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$docs} WHERE generation_id = %d", $img['id'])) === 0);
} finally {
	remove_filter('pre_http_request', $httpFilter, 10);
	remove_filter('clara/llm_provider', $noLlm);
	remove_filter('clara/anthropic_api_key', $noKey);
	remove_filter('clara/image_spawn_cron', $noSpawn);
	foreach ($users as $userId) {
		delete_transient("clara_layout_cd_{$userId}");
		foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::table('projects') . ' WHERE owner_user_id = %d', $userId)) as $id) {
			Projects::delete((int) $id);
		}
		wp_delete_user($userId);
	}
	wp_clear_scheduled_hook(ImageJobs::HOOK);
	wp_set_current_user(0);
}

WP_CLI::log("Layout JSON: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
