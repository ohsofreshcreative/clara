<?php

/**
 * Testy integracyjne fundamentu Clara. Uruchamiane przez `wp eval-file` (patrz run.sh).
 * Nie usuwają tabel ani cudzych danych; jedyny rekord testowy jest kasowany na końcu.
 */

use App\Clara\Access;
use App\Clara\Database\Schema;
use App\Clara\Panel;

$failures = 0;
$passes = 0;

$check = function (string $name, bool $ok, string $detail = '') use (&$failures, &$passes) {
	$ok ? $passes++ : $failures++;
	WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' && ! $ok ? " — {$detail}" : ''));
};

$snapshot = function () {
	global $wpdb;
	$out = [];
	foreach (Schema::TABLES as $name) {
		$row = $wpdb->get_row('SHOW CREATE TABLE ' . Schema::table($name), ARRAY_N);
		// AUTO_INCREMENT zmienia się przy insertach — nie jest częścią schematu.
		$out[$name] = preg_replace('/ AUTO_INCREMENT=\d+/', '', $row[1] ?? '');
	}
	return $out;
};

$request = function (string $method, string $route, int $userId) {
	wp_set_current_user($userId);
	$response = rest_do_request(new WP_REST_Request($method, $route));
	return [$response->get_status(), $response->get_data()];
};

global $wpdb;

/*--- Migracje ---*/

Schema::migrate();
$check('migracja tworzy 8 tabel z prefiksem WP', ! in_array(false, Schema::status(), true));
$check('tabele używają $wpdb->prefix', str_starts_with(Schema::table('projects'), $wpdb->prefix . 'clara_'));
$check('wersja schematu zapisana', Schema::isCurrent());

$before = $snapshot();
$jobs = Schema::table('jobs');
$now = current_time('mysql', true);
$wpdb->insert($jobs, ['job_type' => 'clara_test', 'payload_json' => '{}', 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now]);
$testId = (int) $wpdb->insert_id;

$changes = Schema::migrate();
$check('ponowna migracja bez zmian (idempotentna)', $changes === [], wp_json_encode($changes));
$check('schemat identyczny po ponownej migracji', $before === $snapshot());
$check('dane zachowane po migracji', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$jobs} WHERE id = %d", $testId)) === 1);
$wpdb->delete($jobs, ['id' => $testId]);

$unique = $wpdb->get_results('SHOW INDEX FROM ' . Schema::table('design_systems') . " WHERE Key_name = 'project_version' AND Non_unique = 0");
$check('unikalność (project_id, version) dla design_systems', count($unique) === 2);

/*--- REST ---*/

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$adminId = (int) ($admin[0] ?? 0);

[$code] = $request('GET', '/clara/v1/status', 0);
$check('REST odrzuca niezalogowanego (401)', $code === 401, (string) $code);

[$code, $data] = $request('GET', '/clara/v1/status', $adminId);
$check('REST zwraca status zalogowanemu z uprawnieniem (200)', $code === 200 && ($data['app'] ?? null) === 'clara', (string) $code);
$check('odpowiedź REST nie zawiera danych wrażliwych', ! preg_match('/pass|token|secret|key/i', wp_json_encode($data)));

$deny = fn () => 'clara_test_missing_capability';
add_filter('clara/capability', $deny);
[$code] = $request('GET', '/clara/v1/status', $adminId);
$check('REST odrzuca zalogowanego bez uprawnienia (403)', $code === 403, (string) $code);
$check('panel odmawia bez uprawnienia', ! Access::canUse($adminId));
remove_filter('clara/capability', $deny);

[$code] = $request('POST', '/clara/v1/status', $adminId);
$check('niedozwolona metoda odrzucona (404 rest_no_route)', $code === 404, (string) $code);

[$code] = $request('GET', '/clara/v1/nieistniejacy', $adminId);
$check('nieznana trasa odrzucona (404)', $code === 404, (string) $code);

/*--- Routing panelu ---*/

$rules = (array) get_option('rewrite_rules');
$panelRule = '^(?:' . implode('|', Panel::ROUTES) . ')(?:/.*)?/?$';
$check('reguła rewrite podstron panelu zarejestrowana', isset($rules[$panelRule]) && str_contains($rules[$panelRule], Panel::QUERY_VAR));
$check('reguła przekierowania starego /clara zarejestrowana', isset($rules['^clara(?:/(.*?))?/?$']) && str_contains($rules['^clara(?:/(.*?))?/?$'], Panel::LEGACY_VAR));
$check('adres panelu to strona główna', Panel::url() === home_url('/') && Panel::url('projects') === home_url('/projects'));
$check('niezalogowany nie ma dostępu do panelu', ! Access::canUse(0));

wp_set_current_user(0);

WP_CLI::log("Wynik: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
