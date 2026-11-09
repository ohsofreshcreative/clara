<?php

/**
 * AI Visual Generation: 1 żądanie → 4 obrazy przez kolejkę jobów, statusy, czytelne błędy,
 * ograniczony retry, limity kosztów i wariantów, anulowanie, przerwane joby, uprawnienia,
 * kaskadowe usuwanie. Dostawca: mock GD + testowe dostawcy (bez realnych kosztów).
 */

use App\Clara\Creative\ImageJobs;
use App\Clara\Creative\ImageProvider;
use App\Clara\Creative\MockImageProvider;
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

/** Testowy dostawca: zachowanie sterowane kolejką odpowiedzi. */
$fake = new class implements ImageProvider {
	public array $script = [];
	public ?float $unit = 0.04;
	public int $calls = 0;
	public function id(): string { return 'fake'; }
	public function model(): string { return 'fake-img-1'; }
	public function isMock(): bool { return false; }
	public function estimateCost(): ?float { return $this->unit; }
	public function generate(string $prompt, array $direction): array|WP_Error
	{
		$this->calls++;
		$next = array_shift($this->script) ?? 'ok';
		return match ($next) {
			'ok' => array_merge((new MockImageProvider())->generate($prompt, $direction), ['cost' => 0.05]),
			'ok-nocost' => array_merge((new MockImageProvider())->generate($prompt, $direction), ['cost' => null]),
			'retry' => new WP_Error('fake_rate_limited', 'Limit zapytań dostawcy obrazów.', ['retryable' => true]),
			'fatal' => new WP_Error('fake_policy', 'Dostawca odrzucił prompt (zasady treści).'),
			'gif' => ['body' => 'GIF89a', 'mime' => 'image/gif', 'cost' => 0.01, 'meta' => []],
			'empty' => ['body' => '', 'mime' => 'image/png', 'cost' => 0.01, 'meta' => []],
		};
	}
};
$useFake = fn () => $fake;
$noSpawn = fn () => false;
add_filter('clara/image_spawn_cron', $noSpawn);

$suffix = wp_generate_password(6, false, false);
$users = [];
foreach (['a', 'b'] as $key) {
	$users[$key] = wp_insert_user(['user_login' => "clara_img_{$key}_{$suffix}", 'user_pass' => wp_generate_password(24), 'user_email' => "clara_img_{$key}_{$suffix}@example.invalid", 'role' => 'editor']);
}

global $wpdb;
$jobsTable = Schema::table('jobs');
$jobStats = fn (string $batch) => $wpdb->get_results($wpdb->prepare("SELECT status, attempt_count, error_code FROM {$jobsTable} WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.batchId')) = %s ORDER BY id", $batch), ARRAY_A);

try {
	$a = $users['a'];
	$b = $users['b'];

	[, $project] = $req('POST', '/clara/v1/projects', $a, ['name' => 'Obrazy test', 'brief' => ['summary' => 'Kryty basen.', 'tone' => ['świeży']]]);
	$pid = (int) $project['id'];
	$req('POST', "/clara/v1/projects/{$pid}/design-systems", $a, ['tokens' => ['colors' => [['name' => 'Woda', 'value' => '#00a6df'], ['name' => 'Granat', 'value' => '#0b1b3a'], ['name' => 'Biel', 'value' => '#ffffff']]]]);
	[, $section] = $req('POST', "/clara/v1/projects/{$pid}/sections", $a, ['type' => 'hero', 'requirements' => ['content' => ['Nagłówek', 'Kup bilet']]]);
	$sid = (int) $section['id'];
	[, $run] = $req('POST', "/clara/v1/sections/{$sid}/art-directions", $a, []);
	$route = "/clara/v1/sections/{$sid}/images";

	/*--- Happy path: mock, 4 obrazy po 1 żądaniu ---*/
	[$code, $status] = $req('GET', '/clara/v1/images/status', $a);
	$check('images/status: mock oznaczony, maks. 4 warianty', $code === 200 && $status['mock'] === true && $status['provider'] === 'mock' && $status['maxVariants'] === 4);

	[$code, $batch] = $req('POST', $route, $a, ['runId' => $run['runId']]);
	$check('1 żądanie → 202, partia 4 obrazów w kolejce', $code === 202 && count($batch['items']) === 4 && $batch['status'] === 'queued' && array_unique(array_column($batch['items'], 'status')) === ['queued'], [$code, $batch['status'] ?? $batch]);
	$check('4 joby w tabeli jobs', count($jobStats($batch['batchId'])) === 4);
	$check('cron zaplanowany po zleceniu', (bool) wp_next_scheduled(ImageJobs::HOOK));
	$check('lineage: parent_id = kierunki A–D, prompt bez tekstu w obrazie', array_column($batch['items'], 'parent_id') === array_column($run['directions'], 'id') && str_contains($batch['items'][0]['prompt'], 'Do NOT render any text') && str_contains($batch['items'][0]['prompt'], 'Woda #00a6df'));
	[$code, $data] = $req('POST', $route, $a, ['runId' => $run['runId']]);
	$check('kolejne zlecenie w trakcie partii → 409 z komunikatem', $code === 409 && str_contains($data['message'], 'jeszcze trwa'), [$code, $data['message'] ?? '']);

	$stats = ImageJobs::process(10);
	[, $batches] = $req('GET', $route, $a);
	$done = $batches[0];
	$check('worker: 4 przetworzone, partia succeeded', $stats['succeeded'] === 4 && $done['status'] === 'succeeded', [$stats, $done['status']]);
	$ok = true;
	foreach ($done['items'] as $item) {
		$file = get_attached_file($item['image']['id'] ?? 0);
		$size = $file ? getimagesize($file) : false;
		$ok = $ok && $item['statusLabel'] === 'Gotowe' && $size && $size[0] === 1200 && $size[2] === IMAGETYPE_PNG && (int) get_post_meta($item['image']['id'], '_clara_project_id', true) === $pid;
	}
	$check('4 pliki PNG 1200×675 w mediach, powiązane z projektem', $ok);
	$check('koszt zapisany (mock: 0)', $done['cost'] === 0.0 && $done['items'][0]['cost'] === 0.0, $done['cost']);
	$check('różne obrazy dla różnych kompozycji', count(array_unique(array_map(fn ($i) => md5_file(get_attached_file($i['image']['id'])), $done['items']))) === 4);
	$check('joby succeeded po 1 próbie', array_unique(array_column($jobStats($done['batchId']), 'status')) === ['succeeded']);

	/*--- Walidacja wejścia i liczby wariantów ---*/
	$bad = [
		'brak runId' => [[], 400, 'runId'],
		'zły runId' => [['runId' => 'x'], 400, 'runId'],
		'klucz E' => [['runId' => $run['runId'], 'keys' => ['A', 'E']], 400, 'keys'],
		'powtórzony klucz' => [['runId' => $run['runId'], 'keys' => ['A', 'A']], 400, 'keys'],
		'5 wariantów' => [['runId' => $run['runId'], 'keys' => ['A', 'B', 'C', 'D', 'A']], 400, 'keys'],
		'keys nie-lista' => [['runId' => $run['runId'], 'keys' => 'A'], 400, 'keys'],
	];
	foreach ($bad as $label => [$body, $expected, $field]) {
		[$code, $data] = $req('POST', $route, $a, $body);
		$check("walidacja: {$label} → {$expected} ({$field})", $code === $expected && isset($data['data']['errors'][$field]), [$code, $data['data']['errors'] ?? $data]);
	}
	[$code] = $req('POST', $route, $a, ['runId' => wp_generate_uuid4()]);
	$check('nieznany runId → 404', $code === 404, $code);
	[$code, $sub] = $req('POST', $route, $a, ['runId' => $run['runId'], 'keys' => ['B', 'D']]);
	$check('wybrane warianty B i D → 2 obrazy', $code === 202 && array_column($sub['items'], 'directionKey') === ['B', 'D']);
	ImageJobs::process(10);

	/*--- Błędy dostawcy, retry, koszty ---*/
	add_filter('clara/image_provider', $useFake);
	$fake->script = ['retry', 'ok', 'fatal', 'retry', 'retry', 'gif'];
	[$code, $batch] = $req('POST', $route, $a, ['runId' => $run['runId']]);
	ImageJobs::process(10);
	[, $batches] = $req('GET', $route, $a);
	$items = array_column($batches[0]['items'], null, 'directionKey');
	$jobs = $jobStats($batch['batchId']);
	$check('błąd przejściowy → ponowienie i sukces (2 próby)', $items['A']['status'] === 'succeeded' && (int) $jobs[0]['attempt_count'] === 2, [$items['A']['status'], $jobs[0]]);
	$check('błąd trwały → failed bez ponawiania, czytelny komunikat', $items['B']['status'] === 'failed' && $items['B']['error']['message'] === 'Dostawca odrzucił prompt (zasady treści).' && (int) $jobs[1]['attempt_count'] === 1);
	$check('ponowienia ograniczone do 2 prób → failed', $items['C']['status'] === 'failed' && (int) $jobs[2]['attempt_count'] === 2 && $jobs[2]['error_code'] === 'fake_rate_limited');
	$check('nieobsługiwany format (GIF) → failed', $items['D']['status'] === 'failed' && str_contains($items['D']['error']['message'], 'format'));
	$check('partia częściowa: status partial, koszt tylko udanych', $batches[0]['status'] === 'partial' && abs($batches[0]['cost'] - 0.05) < 0.0001, [$batches[0]['status'], $batches[0]['cost']]);
	$check('dostawca wywołany 6 razy (bez nadmiarowych prób)', $fake->calls === 6, $fake->calls);

	$fake->script = ['ok-nocost', 'empty'];
	[, $batch] = $req('POST', $route, $a, ['runId' => $run['runId'], 'keys' => ['A', 'B']]);
	ImageJobs::process(10);
	$items = array_column(ImageJobs::batch($sid, $batch['batchId'])['items'], null, 'directionKey');
	$check('brak kosztu od dostawcy → zapisany szacunek', $items['A']['cost'] === 0.04 && $items['A']['costSource'] === 'estimate');
	$check('pusty obraz → failed', $items['B']['status'] === 'failed' && str_contains($items['B']['error']['message'], 'pusty'));

	$fake->unit = 0.6;
	[$code, $data] = $req('POST', $route, $a, ['runId' => $run['runId']]);
	$check('limit kosztu partii (4×$0.60 > $2) → 402 z kwotami', $code === 402 && str_contains($data['message'], '$2.40') && str_contains($data['message'], '$2.00'), [$code, $data['message'] ?? '']);
	$budget = fn () => 0.1;
	add_filter('clara/image_daily_budget', $budget);
	$fake->unit = 0.04;
	[$code, $data] = $req('POST', $route, $a, ['runId' => $run['runId']]);
	$check('dzienny budżet przekroczony → 402', $code === 402 && str_contains($data['message'], 'Dzienny budżet'), [$code, $data['message'] ?? '']);
	remove_filter('clara/image_daily_budget', $budget);
	$fake->unit = null;
	[$code, $data] = $req('POST', $route, $a, ['runId' => $run['runId']]);
	$check('nieznany koszt dostawcy → 402 (blokada limitem)', $code === 402 && $data['code'] === 'clara_image_cost_unknown', $code);
	$fake->unit = 0.04;

	/*--- Anulowanie i przerwane joby ---*/
	[, $batch] = $req('POST', $route, $a, ['runId' => $run['runId']]);
	[$code, $cancelled] = $req('POST', "{$route}/{$batch['batchId']}/cancel", $a);
	$check('anulowanie partii w kolejce → cancelled', $code === 200 && $cancelled['status'] === 'cancelled' && array_unique(array_column($jobStats($batch['batchId']), 'status')) === ['cancelled']);
	$calls = $fake->calls;
	ImageJobs::process(10);
	$check('worker pomija anulowane (brak wywołań dostawcy)', $fake->calls === $calls);
	[$code] = $req('POST', "{$route}/{$batch['batchId']}/cancel", $a);
	$check('ponowne anulowanie → 409', $code === 409, $code);

	[, $batch] = $req('POST', $route, $a, ['runId' => $run['runId'], 'keys' => ['A']]);
	$wpdb->query($wpdb->prepare("UPDATE {$jobsTable} SET status = 'running', updated_at = %s WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.batchId')) = %s", gmdate('Y-m-d H:i:s', time() - 3600), $batch['batchId']));
	$wpdb->update(Schema::table('generations'), ['status' => 'running'], ['id' => $batch['items'][0]['id']]);
	$stats = ImageJobs::process(10);
	$item = ImageJobs::batch($sid, $batch['batchId'])['items'][0];
	$check('przerwany job (running > 10 min) → failed z komunikatem', $stats['recovered'] === 1 && $item['status'] === 'failed' && str_contains($item['error']['message'], 'przerwane'));

	$fake->script = ['ok'];
	[, $batch] = $req('POST', $route, $a, ['runId' => $run['runId'], 'keys' => ['A']]);
	remove_filter('clara/image_provider', $useFake);
	ImageJobs::process(10);
	$item = ImageJobs::batch($sid, $batch['batchId'])['items'][0];
	$check('zmiana dostawcy między zleceniem a wykonaniem → failed z instrukcją', $item['status'] === 'failed' && str_contains($item['error']['message'], 'zmienił się'));

	/*--- Uprawnienia ---*/
	[$code] = $req('GET', $route, $b);
	$check('inny użytkownik: lista → 404', $code === 404, $code);
	[$code] = $req('POST', $route, $b, ['runId' => $run['runId']]);
	$check('inny użytkownik: zlecenie → 404', $code === 404, $code);
	[$code] = $req('POST', "{$route}/{$batch['batchId']}/cancel", $b);
	$check('inny użytkownik: anulowanie → 404', $code === 404, $code);
	[$code] = $req('POST', $route, 0, ['runId' => $run['runId']]);
	$check('niezalogowany → 401', $code === 401, $code);
	$req('PATCH', "/clara/v1/sections/{$sid}", $a, ['status' => 'archived']);
	[$code] = $req('POST', $route, $a, ['runId' => $run['runId']]);
	$check('zarchiwizowana sekcja → 409', $code === 409, $code);

	/*--- WP-CLI worker i kaskadowe usuwanie ---*/
	$req('PATCH', "/clara/v1/sections/{$sid}", $a, ['status' => 'ready']);
	[, $batch] = $req('POST', $route, $a, ['runId' => $run['runId'], 'keys' => ['C']]);
	WP_CLI::run_command(['clara', 'worker'], ['limit' => 5]);
	$check('wp clara worker przetwarza kolejkę', ImageJobs::batch($sid, $batch['batchId'])['status'] === 'succeeded');

	$assetIds = $wpdb->get_col($wpdb->prepare('SELECT result_asset_id FROM ' . Schema::table('generations') . " WHERE section_id = %d AND kind = 'image' AND result_asset_id IS NOT NULL", $sid));
	$req('DELETE', "/clara/v1/sections/{$sid}", $a);
	$left = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('generations') . ' WHERE section_id = %d', $sid));
	$check('usunięcie sekcji kasuje generacje, pliki obrazów i joby', $left === 0 && $assetIds && ! array_filter(array_map('get_post', $assetIds)) && count($jobStats($done['batchId'])) === 0, [$left, count($assetIds)]);
} finally {
	remove_filter('clara/image_provider', $useFake);
	remove_filter('clara/image_spawn_cron', $noSpawn);
	wp_clear_scheduled_hook(ImageJobs::HOOK);
	foreach ($users as $userId) {
		foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::table('projects') . ' WHERE owner_user_id = %d', $userId)) as $id) {
			Projects::delete((int) $id);
		}
		wp_delete_user($userId);
	}
	wp_set_current_user(0);
}

WP_CLI::log("Obrazy: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
