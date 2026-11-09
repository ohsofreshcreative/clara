<?php

/**
 * Testy CRUD projektów/sekcji/załączników, separacji uprawnień i walidacji.
 * Uruchamiane przez `wp eval-file` (run.sh). Tworzy tymczasowych użytkowników i dane,
 * które usuwa na końcu (także przy błędzie).
 */

use App\Clara\Projects\Attachments;
use App\Clara\Projects\DemoSeed;
use App\Clara\Projects\Projects;

require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = 0;
$passes = 0;

$check = function (string $name, bool $ok, mixed $detail = '') use (&$failures, &$passes) {
	$ok ? $passes++ : $failures++;
	$detail = is_string($detail) ? $detail : wp_json_encode($detail);
	WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $name . (! $ok && $detail !== '' ? " — {$detail}" : ''));
};

$req = function (string $method, string $route, int $user, ?array $json = null, ?string $raw = null, array $headers = []) {
	wp_set_current_user($user);
	[$path, $query] = array_pad(explode('?', $route, 2), 2, '');
	$request = new WP_REST_Request($method, $path);
	parse_str($query, $params);
	$request->set_query_params($params);
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
	return [$response->get_status(), $response->get_data(), $response->get_headers()];
};

$suffix = wp_generate_password(6, false, false);
$users = [];
foreach (['a' => 'editor', 'b' => 'editor', 'sub' => 'subscriber'] as $key => $role) {
	$users[$key] = wp_insert_user([
		'user_login' => "clara_test_{$key}_{$suffix}",
		'user_pass' => wp_generate_password(24),
		'user_email' => "clara_test_{$key}_{$suffix}@example.invalid",
		'role' => $role,
	]);
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$upload = fn (int $project, int $user, string $body, string $name) => $req('POST', "/clara/v1/projects/{$project}/attachments", $user, null, $body, [
	'content_disposition' => "attachment; filename=\"{$name}\"",
	'content_type' => 'application/octet-stream',
]);

try {
	$a = $users['a'];
	$b = $users['b'];

	/*--- Uprawnienia podstawowe ---*/
	[$code] = $req('GET', '/clara/v1/projects', 0);
	$check('lista projektów: niezalogowany 401', $code === 401, $code);
	[$code] = $req('POST', '/clara/v1/projects', $users['sub'], ['name' => 'X']);
	$check('tworzenie projektu: subskrybent 403', $code === 403, $code);

	/*--- Projekt: happy path ---*/
	$payload = [
		'name' => 'Projekt testowy',
		'client_name' => 'Klient',
		'industry' => 'Rekreacja',
		'website_url' => 'https://example.com/strona',
		'figma_url' => 'https://www.figma.com/design/AbC123xyz/Test',
		'brief' => ['summary' => "Linia 1\nLinia 2", 'goals' => ['Cel 1', 'Cel 2'], 'tone' => ['Świeży'], 'attachments' => [['id' => 999]]],
	];
	[$code, $project] = $req('POST', '/clara/v1/projects', $a, $payload);
	$check('utworzenie projektu 201', $code === 201 && $project['owner_user_id'] === $a, [$code, $project]);
	$check('brief: wieloliniowy opis zachowany', ($project['brief']['summary'] ?? '') === "Linia 1\nLinia 2");
	$check('brief: klient nie może wstrzyknąć załączników', ($project['brief']['attachments'] ?? null) === []);
	$pid = (int) $project['id'];

	[$code, $data] = $req('GET', "/clara/v1/projects/{$pid}", $a);
	$check('odczyt projektu właściciela 200', $code === 200 && $data['name'] === 'Projekt testowy' && $data['sections'] === []);

	[$code, $data] = $req('PATCH', "/clara/v1/projects/{$pid}", $a, ['name' => 'Projekt zmieniony', 'brief' => ['summary' => 'Nowy']]);
	$check('częściowa edycja projektu', $code === 200 && $data['name'] === 'Projekt zmieniony' && $data['client_name'] === 'Klient' && $data['brief']['summary'] === 'Nowy', [$code, $data]);

	[$code, $data, $headers] = $req('GET', '/clara/v1/projects?per_page=1&page=1', $a);
	$check('paginacja + nagłówek X-WP-Total', $code === 200 && count($data) === 1 && (int) $headers['X-WP-Total'] >= 1, [$code, $headers]);
	[$code] = $req('GET', '/clara/v1/projects?per_page=500', $a);
	$check('per_page > 50 odrzucone', $code === 400, $code);

	/*--- Separacja uprawnień ---*/
	[$code] = $req('GET', "/clara/v1/projects/{$pid}", $b);
	$check('inny użytkownik: odczyt cudzego projektu 404', $code === 404, $code);
	[$code] = $req('PATCH', "/clara/v1/projects/{$pid}", $b, ['name' => 'Przejęty']);
	$check('inny użytkownik: edycja cudzego projektu 404', $code === 404, $code);
	[$code] = $req('DELETE', "/clara/v1/projects/{$pid}", $b);
	$check('inny użytkownik: usunięcie cudzego projektu 404', $code === 404, $code);
	[$code, $list] = $req('GET', '/clara/v1/projects', $b);
	$check('inny użytkownik: lista bez cudzych projektów', $code === 200 && ! in_array($pid, array_column($list, 'id'), true));
	$check('projekt nienaruszony po próbach innego użytkownika', Projects::find($pid)['name'] === 'Projekt zmieniony');

	/*--- Walidacja URL i danych ---*/
	$invalid = [
		'pusta nazwa' => ['name' => '   '],
		'za długa nazwa' => ['name' => str_repeat('a', 192)],
		'URL javascript:' => ['name' => 'X', 'website_url' => 'javascript:alert(1)'],
		'URL bez schematu' => ['name' => 'X', 'website_url' => 'example.com'],
		'URL ftp' => ['name' => 'X', 'website_url' => 'ftp://example.com/plik'],
		'URL z danymi logowania' => ['name' => 'X', 'website_url' => 'https://user:pass@example.com'],
		'URL localhost' => ['name' => 'X', 'website_url' => 'http://localhost/admin'],
		'Figma: inny host' => ['name' => 'X', 'figma_url' => 'https://evil.example.com/design/abc'],
		'Figma: http' => ['name' => 'X', 'figma_url' => 'http://www.figma.com/design/abc123'],
		'Figma: zła ścieżka' => ['name' => 'X', 'figma_url' => 'https://www.figma.com/settings'],
		'brief jako lista' => ['name' => 'X', 'brief' => ['a', 'b']],
		'cele nie są listą' => ['name' => 'X', 'brief' => ['goals' => 'tekst']],
		'za dużo celów' => ['name' => 'X', 'brief' => ['goals' => array_fill(0, 21, 'c')]],
	];
	foreach ($invalid as $label => $body) {
		[$code, $data] = $req('POST', '/clara/v1/projects', $a, $body);
		$check("walidacja: {$label} → 400 z polem błędu", $code === 400 && ! empty($data['data']['errors']), [$code, $data['data']['errors'] ?? null]);
	}
	[$code, $data] = $req('POST', '/clara/v1/projects', $a, ['name' => '<script>alert(1)</script>Czysty']);
	$check('sanityzacja: HTML usunięty z nazwy', $code === 201 && $data['name'] === 'Czysty', $data['name'] ?? '');
	$req('DELETE', '/clara/v1/projects/' . (int) $data['id'], $a);

	/*--- Sekcje ---*/
	[$code, $types] = $req('GET', '/clara/v1/section-types', $a);
	$check('lista typów sekcji zawiera hero', $code === 200 && in_array('hero', array_column($types, 'value'), true));

	$sectionBody = ['type' => 'hero', 'requirements' => [
		'goal' => 'Cel', 'content' => ['Nagłówek', 'CTA'],
		'hardConstraints' => ['CTA widoczne'], 'softConstraints' => ['Błękity'],
	]];
	[$code, $section] = $req('POST', "/clara/v1/projects/{$pid}/sections", $a, $sectionBody);
	$check('utworzenie sekcji hero 201 z domyślną nazwą', $code === 201 && $section['name'] === 'Sekcja hero' && $section['status'] === 'draft', [$code, $section]);
	$check('sekcja: hard/soft constraints zapisane', $section['requirements']['hardConstraints'] === ['CTA widoczne'] && $section['requirements']['softConstraints'] === ['Błękity']);
	$sid = (int) $section['id'];

	[$code, $data] = $req('PATCH', "/clara/v1/sections/{$sid}", $a, ['status' => 'ready', 'name' => 'Hero główny']);
	$check('edycja sekcji', $code === 200 && $data['status'] === 'ready' && $data['name'] === 'Hero główny' && $data['requirements']['goal'] === 'Cel');
	[$code] = $req('PATCH', "/clara/v1/sections/{$sid}", $a, ['type' => 'faq']);
	$check('sekcja: zmiana typu odrzucona 400', $code === 400, $code);
	[$code] = $req('POST', "/clara/v1/projects/{$pid}/sections", $a, ['type' => 'nieznany']);
	$check('sekcja: nieznany typ 400', $code === 400, $code);
	[$code] = $req('POST', "/clara/v1/projects/{$pid}/sections", $a, ['type' => 'hero', 'status' => 'published']);
	$check('sekcja: nieznany status 400', $code === 400, $code);
	[$code] = $req('POST', "/clara/v1/projects/{$pid}/sections", $a, ['type' => 'hero', 'requirements' => ['hardConstraints' => 'nie lista']]);
	$check('sekcja: constraints nie-lista 400', $code === 400, $code);

	[$code] = $req('GET', "/clara/v1/sections/{$sid}", $b);
	$check('inny użytkownik: odczyt cudzej sekcji 404', $code === 404, $code);
	[$code] = $req('PATCH', "/clara/v1/sections/{$sid}", $b, ['name' => 'X']);
	$check('inny użytkownik: edycja cudzej sekcji 404', $code === 404, $code);
	[$code] = $req('POST', "/clara/v1/projects/{$pid}/sections", $b, ['type' => 'hero']);
	$check('inny użytkownik: dodanie sekcji do cudzego projektu 404', $code === 404, $code);

	/*--- Załączniki ---*/
	[$code, $file] = $upload($pid, $a, $png, 'szkic.png');
	$check('upload PNG 201', $code === 201 && $file['mime'] === 'image/png', [$code, $file]);
	$check('upload: meta projektu zapisana', (int) get_post_meta((int) ($file['id'] ?? 0), Attachments::META, true) === $pid);
	[$code, $pdfFile] = $upload($pid, $a, $pdf, 'brief.pdf');
	$check('upload PDF 201', $code === 201 && $pdfFile['mime'] === 'application/pdf', [$code, $pdfFile]);

	[$code, $data] = $upload($pid, $a, "to nie jest obrazek\n", 'udawany.png');
	$check('upload: tekst udający PNG → 415', $code === 415, [$code, $data]);
	[$code] = $upload($pid, $a, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'grafika.svg');
	$check('upload: SVG odrzucony → 415', $code === 415, $code);
	[$code] = $upload($pid, $a, "MZ\x90\x00binary", 'program.exe');
	$check('upload: EXE odrzucony → 415', $code === 415, $code);
	[$code, $double] = $upload($pid, $a, $png, 'obraz.php.png');
	$check('upload: podwójne rozszerzenie zneutralizowane (brak „.php.” w pliku)', $code === 201 && ! str_contains((string) $double['url'], '.php.'), [$code, $double['url'] ?? null]);
	[$code] = $upload($pid, $a, '', 'pusty.png');
	$check('upload: pusty plik → 400', $code === 400, $code);

	$limit = fn () => 32;
	add_filter('clara/attachment_max_bytes', $limit);
	[$code] = $upload($pid, $a, $png, 'duzy.png');
	$check('upload: przekroczony rozmiar → 413', $code === 413, $code);
	remove_filter('clara/attachment_max_bytes', $limit);

	[$code] = $upload($pid, $b, $png, 'cudzy.png');
	$check('upload do cudzego projektu → 404', $code === 404, $code);

	[$code, $data] = $req('GET', "/clara/v1/projects/{$pid}", $a);
	$names = array_column($data['brief']['attachments'], 'name');
	$check('załączniki widoczne w briefie', in_array('szkic.png', $names, true) && in_array('brief.pdf', $names, true), $names);

	[$code] = $req('PATCH', "/clara/v1/projects/{$pid}", $a, ['brief' => ['summary' => 'Po edycji']]);
	[$code, $data] = $req('GET', "/clara/v1/projects/{$pid}", $a);
	$check('edycja briefu zachowuje załączniki', count($data['brief']['attachments']) >= 2);

	[$code] = $req('DELETE', "/clara/v1/projects/{$pid}/attachments/{$file['id']}", $b);
	$check('inny użytkownik: usunięcie cudzego załącznika 404', $code === 404, $code);
	[$code] = $req('DELETE', "/clara/v1/projects/{$pid}/attachments/{$file['id']}", $a);
	$check('usunięcie załącznika', $code === 200 && get_post((int) $file['id']) === null, $code);

	/*--- Usuwanie ---*/
	[$code] = $req('DELETE', "/clara/v1/sections/{$sid}", $a);
	$check('usunięcie sekcji', $code === 200 && $req('GET', "/clara/v1/sections/{$sid}", $a)[0] === 404);

	$req('POST', "/clara/v1/projects/{$pid}/sections", $a, ['type' => 'about']);
	[$code] = $req('DELETE', "/clara/v1/projects/{$pid}", $a);
	global $wpdb;
	$orphans = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . App\Clara\Database\Schema::table('sections') . ' WHERE project_id = %d', $pid));
	$check('usunięcie projektu kasuje sekcje i pliki', $code === 200 && $orphans === 0 && get_post((int) $pdfFile['id']) === null && Projects::find($pid) === null);

	/*--- Seed demo ---*/
	$first = DemoSeed::run($a);
	$second = DemoSeed::run($a);
	[$code, $demo] = $req('GET', "/clara/v1/projects/{$first['project']}", $a);
	$check('seed H2Otwock: idempotentny', $first['created'] && ! $second['created'] && $first['project'] === $second['project']);
	$check('seed H2Otwock: jawnie oznaczony demo z sekcją hero', $code === 200 && ($demo['brief']['demo'] ?? false) === true && ($demo['sections'][0]['type'] ?? '') === 'hero');
	[$code] = $req('GET', "/clara/v1/projects/{$first['project']}", $b);
	$check('seed: niewidoczny dla innego użytkownika', $code === 404, $code);
	Projects::delete($first['project']);
} finally {
	global $wpdb;
	foreach ($users as $userId) {
		// Sprzątanie danych testowych tymczasowych użytkowników.
		$ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . App\Clara\Database\Schema::table('projects') . ' WHERE owner_user_id = %d', $userId));
		foreach ($ids as $id) {
			Projects::delete((int) $id);
		}
		wp_delete_user($userId);
	}
	wp_set_current_user(0);
}

WP_CLI::log("Projekty: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
