<?php

/**
 * Testy Design System Managera: wersjonowanie, provenance, import JSON (Clara/W3C), czytelne
 * błędy, Figma REST na mocku HTTP (pre_http_request), uprawnienia i brak tokenu w odpowiedziach.
 * Uruchamiane przez `wp eval-file` (run.sh). Dane i użytkownicy testowi są usuwani na końcu.
 */

use App\Clara\Projects\Projects;

require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = 0;
$passes = 0;

$check = function (string $name, bool $ok, mixed $detail = '') use (&$failures, &$passes) {
	$ok ? $passes++ : $failures++;
	$detail = is_string($detail) ? $detail : wp_json_encode($detail, JSON_UNESCAPED_UNICODE);
	WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $name . (! $ok && $detail !== '' ? " — {$detail}" : ''));
};

$req = function (string $method, string $route, int $user, ?array $json = null, ?string $raw = null, array $headers = []) {
	wp_set_current_user($user);
	$request = new WP_REST_Request($method, $route);
	if ($json !== null) {
		$request->set_header('content-type', 'application/json');
		$request->set_body(wp_json_encode($json));
	}
	if ($raw !== null) {
		$request->set_body($raw);
	}
	foreach ($headers as $k => $v) {
		$request->set_header($k, $v);
	}
	$response = rest_do_request($request);
	return [$response->get_status(), $response->get_data()];
};

$suffix = wp_generate_password(6, false, false);
$users = [];
foreach (['a', 'b'] as $key) {
	$users[$key] = wp_insert_user([
		'user_login' => "clara_ds_{$key}_{$suffix}",
		'user_pass' => wp_generate_password(24),
		'user_email' => "clara_ds_{$key}_{$suffix}@example.invalid",
		'role' => 'editor',
	]);
}

const FAKE_TOKEN = 'figd_TEST_SECRET_never_leak_123';
$figmaMock = null;
$figmaCalls = [];
$tokenFilter = fn () => FAKE_TOKEN;
$httpFilter = function ($pre, $args, $url) use (&$figmaMock, &$figmaCalls) {
	if (! str_starts_with($url, 'https://api.figma.com/')) {
		return $pre;
	}
	$figmaCalls[] = ['url' => $url, 'token' => $args['headers']['X-Figma-Token'] ?? null];
	return $figmaMock ? $figmaMock($url) : new WP_Error('http_request_failed', 'mock offline');
};
add_filter('pre_http_request', $httpFilter, 10, 3);

$json = fn ($code, $body, $headers = []) => ['response' => ['code' => $code, 'message' => ''], 'headers' => $headers, 'body' => wp_json_encode($body), 'cookies' => []];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

try {
	$a = $users['a'];
	$b = $users['b'];
	[, $project] = $req('POST', '/clara/v1/projects', $a, ['name' => 'DS test', 'figma_url' => 'https://www.figma.com/design/AbCdEf1234567890XyZ/DS']);
	$pid = (int) $project['id'];
	$base = "/clara/v1/projects/{$pid}/design-systems";

	/*--- Ręczne tokeny + wersjonowanie ---*/
	[$code, $data] = $req('GET', "{$base}/latest", $a);
	$check('brak DS: latest = null', $code === 200 && $data === null, [$code, $data]);

	$manual = ['tokens' => [
		'colors' => [['name' => 'Primary', 'value' => '#0A6CFF'], ['name' => 'Ink', 'value' => '#123']],
		'typography' => [['name' => 'H1', 'fontFamily' => 'Inter', 'fontSize' => '48px', 'fontWeight' => 700, 'lineHeight' => 56]],
		'spacing' => [['name' => 'md', 'value' => 16]],
		'radii' => [['name' => 'card', 'value' => 24]],
		'shadows' => [['name' => 'glow', 'value' => '0 0 32px #2f6bff40']],
	], 'note' => 'Pierwsza wersja'];
	[$code, $v1] = $req('POST', $base, $a, $manual);
	$check('ręczne tokeny → v1 (201)', $code === 201 && $v1['version'] === 1 && $v1['source']['type'] === 'manual', [$code, $v1]);
	$check('normalizacja: hex lowercase, #123 → #112233, 48px → 48', $v1['tokens']['colors'][0]['value'] === '#0a6cff' && $v1['tokens']['colors'][1]['value'] === '#112233' && $v1['tokens']['typography'][0]['fontSize'] == 48);
	$check('provenance: autor i brak rodzica dla v1', $v1['source']['createdBy'] === $a && $v1['source']['parentVersion'] === null && $v1['source']['note'] === 'Pierwsza wersja');

	$manual['tokens']['colors'][0]['value'] = '#ff0000';
	[$code, $v2] = $req('POST', $base, $a, $manual);
	$check('kolejny zapis → v2 z parentVersion 1', $code === 201 && $v2['version'] === 2 && $v2['source']['parentVersion'] === 1);
	[$code, $old] = $req('GET', "/clara/v1/design-systems/{$v1['id']}", $a);
	$check('v1 niezmieniona po zapisie v2 (wersje niemutowalne)', $old['tokens']['colors'][0]['value'] === '#0a6cff');

	/*--- Błędne tokeny: czytelne błędy ze ścieżką ---*/
	$bad = [
		'kolor słowem' => [['colors' => [['name' => 'X', 'value' => 'blue']]], 'colors[0].value'],
		'brak nazwy' => [['colors' => [['value' => '#fff']]], 'colors[0].name'],
		'duplikat nazwy' => [['spacing' => [['name' => 'a', 'value' => 1], ['name' => 'A', 'value' => 2]]], 'spacing[1].name'],
		'fontSize poza zakresem' => [['typography' => [['name' => 'T', 'fontFamily' => 'Inter', 'fontSize' => 9999]]], 'typography[0].fontSize'],
		'cień z url()' => [['shadows' => [['name' => 's', 'value' => 'url(javascript:alert(1))']]], 'shadows[0].value'],
		'nieznana grupa' => [['animations' => []], 'tokens'],
		'grupa nie-lista' => [['colors' => ['name' => 'x']], 'colors'],
	];
	foreach ($bad as $label => [$tokens, $field]) {
		[$code, $data] = $req('POST', $base, $a, ['tokens' => $tokens]);
		$message = $data['data']['errors'][$field] ?? '';
		$check("błędne tokeny: {$label} → 400 z komunikatem dla {$field}", $code === 400 && $message !== '', [$code, $data['data']['errors'] ?? null]);
	}
	[$code, $data] = $req('GET', "{$base}/latest", $a);
	$check('błędne zapisy nie tworzą wersji', $data['version'] === 2);

	/*--- Import JSON ---*/
	[$code, $data] = $req('POST', "{$base}/import", $a, ['content' => '{"colors": [ {"name": "x", "value": "#fff"}, ]}']);
	$check('import: składnia JSON → 400 „Niepoprawny JSON”', $code === 400 && str_contains($data['message'], 'Niepoprawny JSON'), [$code, $data['message'] ?? '']);
	[$code, $data] = $req('POST', "{$base}/import", $a, ['content' => '[1,2,3]']);
	$check('import: lista zamiast obiektu → 400', $code === 400 && str_contains($data['message'], 'obiektu'), $data['message'] ?? '');
	[$code, $data] = $req('POST', "{$base}/import", $a, ['content' => '{"foo": {"bar": 1}}']);
	$check('import: nieznany format → 400 z podpowiedzią', $code === 400 && str_contains($data['message'], 'Nie rozpoznano formatu'), $data['message'] ?? '');
	[$code, $data] = $req('POST', "{$base}/import", $a, ['content' => '']);
	$check('import: pusty plik → 400', $code === 400, $code);
	[$code, $data] = $req('POST', "{$base}/import", $a, ['content' => '{"colors":[{"name":"x","value":"' . str_repeat('a', 1048600) . '"}]}']);
	$check('import: > 1 MB → 413', $code === 413, $code);
	[$code, $data] = $req('POST', "{$base}/import", $a, ['content' => '{"colors":[{"name":"Brand","value":"rgb(1,2,3)"}]}']);
	$check('import Clara: zły kolor → 400 ze ścieżką colors[0].value', $code === 400 && isset($data['data']['errors']['colors[0].value']), $data['data']['errors'] ?? null);

	[$code, $v3] = $req('POST', "{$base}/import", $a, ['filename' => 'tokens.json', 'content' => wp_json_encode(['colors' => [['name' => 'Water', 'value' => '#00a6df']]])]);
	$check('import Clara v1 → v3 z provenance json_import', $code === 201 && $v3['version'] === 3 && $v3['source']['type'] === 'json_import' && $v3['source']['format'] === 'clara-v1' && $v3['source']['filename'] === 'tokens.json', [$code, $v3['source'] ?? null]);

	$dtcg = [
		'color' => [
			'$type' => 'color',
			'brand' => ['primary' => ['$value' => '#00A6DF'], 'accent' => ['$value' => '{color.brand.primary}']],
			'modern' => ['$value' => ['colorSpace' => 'srgb', 'components' => [1, 0, 0], 'hex' => '#ff0000']],
		],
		'space' => ['$type' => 'dimension', 'md' => ['$value' => '16px'], 'lg' => ['$value' => ['value' => 2, 'unit' => 'rem']]],
		'radius' => ['card' => ['$type' => 'dimension', '$value' => '24px']],
		'font' => ['h1' => ['$type' => 'typography', '$value' => ['fontFamily' => ['Poppins', 'sans-serif'], 'fontSize' => '48px', 'fontWeight' => 700, 'lineHeight' => 1.2]]],
		'shadow' => ['card' => ['$type' => 'shadow', '$value' => ['offsetX' => '0px', 'offsetY' => '4px', 'blur' => '12px', 'spread' => '0px', 'color' => '#00000033']]],
		'motion' => ['fast' => ['$type' => 'duration', '$value' => '100ms']],
		'broken' => ['$type' => 'color', '$value' => '{color.missing}'],
	];
	[$code, $v4] = $req('POST', "{$base}/import", $a, ['filename' => 'figma-variables.json', 'content' => wp_json_encode($dtcg)]);
	$colors = array_column($v4['tokens']['colors'] ?? [], 'value', 'name');
	$check('import W3C DTCG → v4 (format w3c-dtcg)', $code === 201 && $v4['source']['format'] === 'w3c-dtcg', [$code, $v4['source'] ?? $v4]);
	$check('DTCG: alias rozwiązany i kolor-obiekt (hex)', ($colors['color.brand.accent'] ?? '') === '#00a6df' && ($colors['color.modern'] ?? '') === '#ff0000', $colors);
	$check('DTCG: dimension → spacing (rem→px) i radii', array_column($v4['tokens']['spacing'], 'value', 'name') == ['space.md' => 16, 'space.lg' => 32] && $v4['tokens']['radii'][0]['value'] == 24);
	$check('DTCG: typografia (rodzina, lineHeight 1.2 → 57.6px)', $v4['tokens']['typography'][0]['fontFamily'] === 'Poppins' && $v4['tokens']['typography'][0]['lineHeight'] == 57.6, $v4['tokens']['typography'][0] ?? null);
	$check('DTCG: cień złożony do CSS', ($v4['tokens']['shadows'][0]['value'] ?? '') === '0px 4px 12px 0px #00000033', $v4['tokens']['shadows'][0] ?? null);
	$warnings = implode(' | ', $v4['source']['warnings'] ?? []);
	$check('DTCG: ostrzeżenia o nieobsługiwanym typie i złym aliasie', str_contains($warnings, 'motion.fast') && str_contains($warnings, 'broken'), $warnings);

	/*--- Screeny referencyjne ---*/
	$upload = fn ($user, $body, $name) => $req('POST', "{$base}/screens", $user, null, $body, ['content_disposition' => "attachment; filename=\"{$name}\""]);
	[$code, $v5] = $upload($a, $png, 'hero-ref.png');
	$check('screen referencyjny → v5, tokeny przeniesione', $code === 201 && $v5['version'] === 5 && count($v5['assets']) === 1 && $v5['tokens'] == $v4['tokens'], [$code, $v5['assets'] ?? $v5]);
	[$code] = $upload($a, "%PDF-1.4\n%%EOF\n", 'ref.pdf');
	$check('screen: PDF odrzucony (tylko obrazy) → 415', $code === 415, $code);
	[$code] = $upload($a, 'nie obraz', 'fake.png');
	$check('screen: fałszywy PNG → 415', $code === 415, $code);
	[$code, $v6] = $req('POST', $base, $a, $manual);
	$check('zapis tokenów zachowuje screeny', count($v6['assets']) === 1);
	$assetId = $v5['assets'][0]['id'];
	[$code, $v7] = $req('DELETE', "{$base}/screens/{$assetId}", $a);
	$check('usunięcie screena → nowa wersja bez niego, plik zostaje dla historii', $code === 201 && $v7['assets'] === [] && get_post($assetId) !== null);

	/*--- Przywracanie ---*/
	[$code, $restored] = $req('POST', "/clara/v1/design-systems/{$v1['id']}/restore", $a);
	$check('przywrócenie v1 → nowa wersja z restoredFrom=1', $code === 201 && $restored['version'] === 8 && $restored['source']['restoredFrom'] === 1 && $restored['tokens'] == $v1['tokens'], [$code, $restored['source'] ?? null]);
	[$code, $list] = $req('GET', $base, $a);
	$check('historia: 8 wersji malejąco z licznikami tokenów', $code === 200 && count($list) === 8 && $list[0]['version'] === 8 && isset($list[0]['counts']['colors']) && ! isset($list[0]['tokens']));
	[$code, $projects] = $req('GET', '/clara/v1/projects', $a);
	$check('lista projektów zawiera design_system_version', (int) ($projects[0]['design_system_version'] ?? 0) === 8);

	/*--- Uprawnienia ---*/
	[$code] = $req('GET', $base, $b);
	$check('inny użytkownik: historia DS → 404', $code === 404, $code);
	[$code] = $req('POST', $base, $b, $manual);
	$check('inny użytkownik: zapis DS → 404', $code === 404, $code);
	[$code] = $req('GET', "/clara/v1/design-systems/{$v1['id']}", $b);
	$check('inny użytkownik: odczyt wersji → 404', $code === 404, $code);
	[$code] = $req('POST', "/clara/v1/design-systems/{$v1['id']}/restore", $b);
	$check('inny użytkownik: przywrócenie → 404', $code === 404, $code);
	[$code] = $req('GET', $base, 0);
	$check('niezalogowany → 401', $code === 401, $code);

	/*--- Figma REST (mock HTTP) ---*/
	[$code, $data] = $req('POST', "{$base}/figma", $a);
	$check('Figma bez tokenu → 409 z instrukcją importu JSON', $code === 409 && str_contains($data['message'], 'import'), [$code, $data['message'] ?? '']);
	[$code, $status] = $req('GET', '/clara/v1/figma/status', $a);
	$check('figma/status: configured=false, variables=false', $status['configured'] === false && $status['capabilities']['variables'] === false);

	add_filter('clara/figma_token', $tokenFilter);
	delete_transient("clara_figma_{$pid}");

	$figmaMock = fn ($url) => str_contains($url, '/nodes?')
		? $json(200, ['nodes' => [
			'1:2' => ['document' => ['fills' => [['type' => 'SOLID', 'color' => ['r' => 0, 'g' => 0.651, 'b' => 0.875, 'a' => 1]]]]],
			'1:3' => ['document' => ['style' => ['fontFamily' => 'Poppins', 'fontSize' => 56, 'fontWeight' => 600, 'lineHeightPx' => 64]]],
			'1:4' => ['document' => ['effects' => [['type' => 'DROP_SHADOW', 'visible' => true, 'offset' => ['x' => 0, 'y' => 8], 'radius' => 24, 'spread' => 0, 'color' => ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 0.25]]]]],
			'1:5' => ['document' => ['fills' => [['type' => 'GRADIENT_LINEAR']]]],
			'1:6' => ['document' => []],
		]])
		: $json(200, ['name' => 'H2Otwock DS', 'lastModified' => '2026-11-01T10:00:00Z', 'version' => '123', 'styles' => [
			'1:2' => ['name' => 'Brand/Water', 'styleType' => 'FILL'],
			'1:3' => ['name' => 'Heading/H1', 'styleType' => 'TEXT'],
			'1:4' => ['name' => 'Elevation/Card', 'styleType' => 'EFFECT'],
			'1:5' => ['name' => 'Gradient/Hero', 'styleType' => 'FILL'],
			'1:6' => ['name' => 'Grid/12', 'styleType' => 'GRID'],
		]]);
	[$code, $fig] = $req('POST', "{$base}/figma", $a);
	$check('Figma (mock): style → nowa wersja figma_rest', $code === 201 && $fig['source']['type'] === 'figma_rest' && $fig['source']['fileKey'] === 'AbCdEf1234567890XyZ', [$code, $fig['source'] ?? $fig]);
	$check('Figma (mock): kolor, typografia, cień zmapowane', ($fig['tokens']['colors'][0]['value'] ?? '') === '#00a6df' && ($fig['tokens']['typography'][0]['fontSize'] ?? 0) == 56 && ($fig['tokens']['shadows'][0]['value'] ?? '') === '0px 8px 24px 0px #00000040', $fig['tokens'] ?? null);
	$check('Figma (mock): gradient i siatka pominięte z ostrzeżeniem', count($fig['source']['warnings']) === 2);
	$check('Figma: tylko api.figma.com, 2 zapytania, token w nagłówku serwera', count($figmaCalls) === 2 && $figmaCalls[0]['token'] === FAKE_TOKEN && str_starts_with($figmaCalls[1]['url'], 'https://api.figma.com/v1/files/AbCdEf1234567890XyZ/nodes'));
	$check('Figma: token nie trafia do odpowiedzi REST', ! str_contains(wp_json_encode([$fig, $status]), FAKE_TOKEN));

	[$code, $data] = $req('POST', "{$base}/figma", $a);
	$check('Figma: lokalny odstęp między pobraniami → 429', $code === 429, $code);

	// Podglądy frame'ów: szerokie frame'y → /v1/images → pobranie z magazynu Figmy → assety figma_frame.
	delete_transient("clara_figma_{$pid}");
	$png = (string) file_get_contents(get_theme_file_path('resources/images/clara/hero-figure.jpg'));
	$s3 = function ($pre, $args, $url) use ($png) {
		return str_starts_with($url, 'https://figma-alpha-api.s3.example/') ? ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => $png, 'cookies' => [], 'filename' => null] : $pre;
	};
	add_filter('pre_http_request', $s3, 5, 3);
	$figmaCalls = [];
	$figmaMock = fn ($url) => str_contains($url, '/images/')
		? $json(200, ['images' => ['10:1' => 'https://figma-alpha-api.s3.example/a.jpg', '10:2' => 'https://figma-alpha-api.s3.example/b.jpg']])
		: $json(200, ['name' => 'H2Otwock DS', 'styles' => [], 'document' => ['children' => [['type' => 'CANVAS', 'children' => [
			['id' => '10:1', 'type' => 'FRAME', 'name' => 'Strona główna', 'absoluteBoundingBox' => ['width' => 1440]],
			['id' => '10:2', 'type' => 'FRAME', 'name' => 'Oferta', 'absoluteBoundingBox' => ['width' => 1440]],
			['id' => '10:3', 'type' => 'FRAME', 'name' => 'Przycisk', 'absoluteBoundingBox' => ['width' => 120]],
		]]]]]);
	[$code, $withFrames] = $req('POST', "{$base}/figma", $a);
	$frames = array_values(array_filter($withFrames['assets'] ?? [], fn ($x) => ($x['role'] ?? '') === 'figma_frame'));
	$check('Figma: szerokie frame’y zapisane jako podglądy (bez małych elementów)', $code === 201 && array_column($frames, 'frameName') === ['Strona główna', 'Oferta'] && $withFrames['source']['framesFound'] === 2, [$code, $frames, $withFrames['source'] ?? null]);
	$check('Figma: podglądy z /v1/images (jpg, skala 0.5)', (bool) array_filter($figmaCalls, fn ($c) => str_contains($c['url'], '/v1/images/AbCdEf1234567890XyZ?ids=') && str_contains($c['url'], 'format=jpg&scale=0.5')), array_column($figmaCalls, 'url'));
	delete_transient("clara_figma_{$pid}");
	[$code, $again] = $req('POST', "{$base}/figma", $a);
	$check('Figma: ponowny import zastępuje podglądy frame’ów (bez duplikatów)', $code === 201 && count(array_filter($again['assets'], fn ($x) => ($x['role'] ?? '') === 'figma_frame')) === 2, $code);
	remove_filter('pre_http_request', $s3, 5);

	// Kolory z linku do ramki palety: primary / secondary / third z wypełnień, odcienie pominięte.
	delete_transient("clara_figma_{$pid}");
	$swatch = fn ($name, $label, $rgb) => ['type' => 'FRAME', 'name' => $name, 'fills' => [['type' => 'SOLID', 'color' => $rgb + ['a' => 1]]], 'children' => [['type' => 'TEXT', 'name' => $label, 'characters' => $label]]];
	$figmaCalls = [];
	$figmaMock = fn ($url) => $json(200, ['name' => 'H2Otwock', 'nodes' => ['17304:77' => ['document' => ['name' => '02. Colors', 'type' => 'SECTION', 'children' => [
		['type' => 'FRAME', 'name' => 'primary', 'children' => [['type' => 'TEXT', 'name' => 'Header', 'characters' => '#00A64D'], $swatch('Primary', 'primary', ['r' => 0.102, 'g' => 0.224, 'b' => 0.333])]],
		['type' => 'FRAME', 'name' => 'secondary', 'children' => [$swatch('Secondary', 'secondary', ['r' => 0.286, 'g' => 0.176, 'b' => 0.157])]],
		['type' => 'FRAME', 'name' => 'shades', 'children' => [$swatch('Shades', 'third', ['r' => 0.733, 'g' => 0.839, 'b' => 0.953])]],
		['type' => 'FRAME', 'name' => 'shades', 'children' => [$swatch('Shades', 'shades', ['r' => 0, 'g' => 0, 'b' => 0])]],
		['type' => 'FRAME', 'name' => 'D1D7DD/10%', 'fills' => [['type' => 'SOLID', 'color' => ['r' => 0.8, 'g' => 0.9, 'b' => 1, 'a' => 1]]], 'children' => [['type' => 'TEXT', 'characters' => '#CFE6FE']]],
	]]]]]);
	$colorsUrl = 'https://www.figma.com/design/AbCdEf1234567890XyZ/H2Otwock?node-id=17304-77&t=abc';
	[$code, $colored] = $req('POST', "{$base}/colors", $a, ['url' => $colorsUrl]);
	$check('kolory z Figmy: Primary, Secondary, Third bez odcieni', $code === 201 && $colored['tokens']['colors'] === [['name' => 'Primary', 'value' => '#1a3955'], ['name' => 'Secondary', 'value' => '#492d28'], ['name' => 'Third', 'value' => '#bbd6f3']] && $colored['source']['type'] === 'figma_colors', [$code, $colored['tokens']['colors'] ?? $colored]);
	$check('kolory z Figmy: zapytanie o węzeł z linku (17304:77), typografia i screeny zachowane', str_contains($figmaCalls[0]['url'] ?? '', 'nodes?ids=17304%3A77') && count($colored['assets']) === count($again['assets']) && $colored['tokens']['typography'] === $again['tokens']['typography'], array_column($figmaCalls, 'url'));
	$check('kolory z Figmy: link zapisany w projekcie', (Projects::find($pid)['brief']['colorsUrl'] ?? '') === $colorsUrl);
	[$code] = $req('POST', "{$base}/colors", $a, ['url' => 'https://www.figma.com/design/AbCdEf1234567890XyZ/H2Otwock']);
	[$code2] = $req('POST', "{$base}/colors", $a, ['url' => 'https://evil.example/design/AbCdEf1234567890XyZ/x?node-id=1-2']);
	[$code3] = $req('POST', "{$base}/colors", $b, ['url' => $colorsUrl]);
	$check('kolory z Figmy: link bez node-id / obca domena → 400, cudzy projekt → 404', $code === 400 && $code2 === 400 && in_array($code3, [403, 404], true), [$code, $code2, $code3]);
	delete_transient("clara_figma_{$pid}");
	$figmaMock = fn ($url) => $json(200, ['nodes' => ['17304:77' => ['document' => ['name' => 'X', 'children' => [$swatch('Akcent', 'accent', ['r' => 1, 'g' => 0, 'b' => 0])]]]]]);
	[$code, $none] = $req('POST', "{$base}/colors", $a, ['url' => $colorsUrl]);
	$check('kolory z Figmy: brak „primary” → 422 z instrukcją', $code === 422 && str_contains($none['message'] ?? '', 'primary'), [$code, $none['message'] ?? null]);

	// Typografia z osobnej ramki: style próbek (bez fontu etykiet), nagłówki wg rozmiaru, akapity jako Body.
	delete_transient("clara_figma_{$pid}");
	$t = fn ($text, $family, $size, $weight, $lh, $name = null) => ['type' => 'TEXT', 'name' => $name ?? $text, 'characters' => $text, 'style' => ['fontFamily' => $family, 'fontSize' => $size, 'fontWeight' => $weight, 'lineHeightPx' => $lh]];
	$figmaCalls = [];
	$figmaMock = fn ($url) => $json(200, ['nodes' => ['3301:1897' => ['document' => ['name' => '01a. Fonts - desktop', 'type' => 'SECTION', 'children' => [
		$t('01a. Fonts – desktop', 'Sora', 48, 600, 60), $t('Usage', 'Sora', 32, 600, 52), $t('h1', 'Sora', 32, 600, 52), $t('72', 'Sora', 32, 600, 52), $t('bold', 'Sora', 32, 600, 52),
		$t('Bricolage Grotesque', 'Bricolage Grotesque', 60, 500, 62),
		$t('Hero - simple header 2', 'PolySans', 52, 700, 56), $t('Hero - simple header 1', 'PolySans', 60, 700, 66),
		$t(str_repeat('Integer pretium interdum lorem ut viverra. ', 4), 'Zalando Sans SemiExpanded', 20, 500, 28),
	]]]]]);
	$typoUrl = 'https://www.figma.com/design/AbCdEf1234567890XyZ/H2Otwock?node-id=3301-1897';
	[$code, $typed] = $req('POST', "{$base}/typography", $a, ['url' => $typoUrl]);
	$names = array_map(fn ($x) => "{$x['name']} {$x['fontFamily']} {$x['fontSize']}", $typed['tokens']['typography'] ?? []);
	$check('typografia z Figmy: H1, H2, Body z próbek, bez etykiet (Sora) i próbki nazwy kroju', $code === 201 && $names === ['H1 PolySans 60', 'H2 PolySans 52', 'Body Zalando Sans SemiExpanded 20'] && $typed['source']['type'] === 'figma_typography', [$code, $names, $typed['message'] ?? null]);
	$check('typografia z Figmy: kolory z ramki zostają, link zapisany', ($typed['tokens']['colors'] ?? []) === $colored['tokens']['colors'] && (Projects::find($pid)['brief']['typographyUrl'] ?? '') === $typoUrl);
	delete_transient("clara_figma_{$pid}");
	$figmaMock = fn ($url) => $json(200, ['nodes' => ['3301:1897' => ['document' => ['name' => 'X', 'children' => []]]]]);
	[$code] = $req('POST', "{$base}/typography", $a, ['url' => $typoUrl]);
	$check('typografia z Figmy: pusta ramka → 422', $code === 422, $code);

	$errorsByStatus = [
		[fn () => $json(403, ['status' => 403, 'err' => 'Invalid token ' . FAKE_TOKEN]), 502, 'nie ma dostępu'],
		[fn () => $json(404, ['status' => 404]), 502, 'Nie znaleziono pliku'],
		[fn () => $json(429, [], ['retry-after' => '42']), 429, '42 s'],
		[fn () => new WP_Error('http_request_failed', 'cURL error 28'), 502, 'połączyć'],
		[fn () => ['response' => ['code' => 200], 'headers' => [], 'body' => '<html>', 'cookies' => []], 502, 'nieczytelną'],
	];
	foreach ($errorsByStatus as [$mock, $expected, $text]) {
		delete_transient("clara_figma_{$pid}");
		$figmaMock = $mock;
		[$code, $data] = $req('POST', "{$base}/figma", $a);
		$check("Figma błąd → {$expected} „{$text}” bez szczegółów odpowiedzi", $code === $expected && str_contains($data['message'], $text) && ! str_contains(wp_json_encode($data), FAKE_TOKEN), [$code, $data['message'] ?? '']);
	}

	delete_transient("clara_figma_{$pid}");
	$figmaMock = fn () => $json(200, ['name' => 'Pusty', 'styles' => []]);
	[$code, $data] = $req('POST', "{$base}/figma", $a);
	$check('Figma bez stylów: wersja z ostrzeżeniem o zmiennych (Enterprise)', $code === 201 && str_contains(implode(' ', $data['source']['warnings']), 'Enterprise'));

	[, $p2] = $req('POST', '/clara/v1/projects', $a, ['name' => 'Bez Figmy']);
	[$code, $data] = $req('POST', "/clara/v1/projects/{$p2['id']}/design-systems/figma", $a);
	$check('Figma: projekt bez linku → 400 z instrukcją', $code === 400 && str_contains($data['message'], 'linku'), $data['message'] ?? '');
	remove_filter('clara/figma_token', $tokenFilter);

	/*--- Kasowanie projektu ---*/
	Projects::delete($pid);
	global $wpdb;
	$left = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . App\Clara\Database\Schema::table('design_systems') . ' WHERE project_id = %d', $pid));
	$check('usunięcie projektu kasuje wersje DS i screeny', $left === 0 && get_post($assetId) === null);
} finally {
	remove_filter('pre_http_request', $httpFilter, 10);
	remove_filter('clara/figma_token', $tokenFilter);
	global $wpdb;
	foreach ($users as $userId) {
		$ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . App\Clara\Database\Schema::table('projects') . ' WHERE owner_user_id = %d', $userId));
		foreach ($ids as $id) {
			delete_transient("clara_figma_{$id}");
			Projects::delete((int) $id);
		}
		wp_delete_user($userId);
	}
	wp_set_current_user(0);
}

WP_CLI::log("Design system: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
