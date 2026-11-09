<?php

/**
 * Preferencje wyglądu: domyślne, zapis, walidacja, izolacja między użytkownikami, uprawnienia, render na <html>.
 */

use App\Clara\Preferences;

require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = 0;
$passes = 0;
$check = function (string $name, bool $ok, mixed $detail = '') use (&$failures, &$passes) {
	$ok ? $passes++ : $failures++;
	$detail = is_string($detail) ? $detail : wp_json_encode($detail, JSON_UNESCAPED_UNICODE);
	WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $name . (! $ok && $detail !== '' ? " — {$detail}" : ''));
};
$req = function (string $method, int $user, ?array $json = null) {
	wp_set_current_user($user);
	$request = new WP_REST_Request($method, '/clara/v1/preferences');
	if ($json !== null) {
		$request->set_header('content-type', 'application/json');
		$request->set_body(wp_json_encode($json));
	}
	$response = rest_do_request($request);
	return [$response->get_status(), $response->get_data()];
};

$suffix = wp_generate_password(6, false, false);
$users = [];
foreach (['a' => 'editor', 'b' => 'editor', 's' => 'subscriber'] as $key => $role) {
	$users[$key] = wp_insert_user(['user_login' => "clara_pref_{$key}_{$suffix}", 'user_pass' => wp_generate_password(24), 'user_email' => "clara_pref_{$key}_{$suffix}@example.invalid", 'role' => $role]);
}

try {
	[$a, $b, $s] = [$users['a'], $users['b'], $users['s']];

	[$code, $data] = $req('GET', $a);
	$check('domyślnie: niebieski, 100%, lista opcji', $code === 200 && $data['accent'] === 'blue' && $data['scale'] === 100 && $data['options']['accents'] === Preferences::ACCENTS && $data['options']['scales'] === Preferences::SCALES, $data);

	[$code, $data] = $req('PATCH', $a, ['accent' => 'pink']);
	$check('zmiana akcentu zachowuje skalę', $code === 200 && $data['accent'] === 'pink' && $data['scale'] === 100, $data);
	[$code, $data] = $req('PATCH', $a, ['scale' => '125']);
	$check('skala jako tekst liczbowy → 125, akcent bez zmian', $code === 200 && $data['scale'] === 125 && $data['accent'] === 'pink', $data);

	[$code, $data] = $req('PATCH', $a, ['accent' => 'neon', 'scale' => 300]);
	$check('błędne wartości → 400 z błędami pól, bez zapisu', $code === 400 && isset($data['data']['errors']['accent'], $data['data']['errors']['scale']) && Preferences::get($a) === ['accent' => 'pink', 'scale' => 125, 'effects' => 'full'], $data);
	[$code, $data] = $req('PATCH', $a, ['accent' => '<script>', 'scale' => 'abc']);
	$check('wstrzyknięcie/tekst → 400', $code === 400, $code);
	[$code, $data] = $req('PATCH', $a, ['scale' => 75, 'effects' => 'reduced']);
	$check('mini skala 75% i efekty ograniczone', $code === 200 && $data['scale'] === 75 && $data['effects'] === 'reduced' && $data['options']['effects'] === Preferences::EFFECTS, $data);
	[$code, $data] = $req('PATCH', $a, ['effects' => 'webgl-max']);
	$check('nieznany tryb efektów → 400', $code === 400 && isset($data['data']['errors']['effects']), $data);
	[$code, $data] = $req('PATCH', $a, ['accent' => 'violet', 'scale' => 90]);
	$check('wszystkie wartości z listy są akceptowane (violet, 90)', $code === 200 && $data['accent'] === 'violet' && $data['scale'] === 90, $data);

	update_user_meta($b, Preferences::META, ['accent' => 'hacked', 'scale' => 999]);
	$check('uszkodzone meta → bezpieczne domyślne', Preferences::get($b) === Preferences::DEFAULTS);
	[, $data] = $req('GET', $b);
	$check('izolacja: inny użytkownik ma własne ustawienia', $data['accent'] === 'blue' && Preferences::get($a)['accent'] === 'violet');

	[$code] = $req('GET', 0);
	[$code2] = $req('PATCH', 0, ['accent' => 'pink']);
	$check('niezalogowany → 401', $code === 401 && $code2 === 401, [$code, $code2]);
	[$code] = $req('PATCH', $s, ['accent' => 'pink']);
	$check('subskrybent → 403', $code === 403, $code);

	$html = view('clara.app', ['assets' => '', 'scripts' => [], 'config' => [], 'preferences' => Preferences::get($a)])->render();
	$check('widok panelu renderuje data-accent i data-scale na <html>', (bool) preg_match('/<html[^>]*data-accent="violet"[^>]*data-scale="90"[^>]*data-effects="reduced"/', $html));
} finally {
	foreach ($users as $userId) {
		wp_delete_user($userId);
	}
	wp_set_current_user(0);
}

WP_CLI::log("Preferencje: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
