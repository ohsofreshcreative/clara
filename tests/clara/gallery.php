<?php

/**
 * Galeria, wybór, historia wersji i Remix: remiks tworzy nową generację (parent_id = źródło)
 * i nie zmienia rodzica ani jego pliku; referencje z kolekcji (generation_sources + migawka);
 * walidacja, błędy dostawcy, uprawnienia, kaskady. Dostawca: mock GD + testowy (bez kosztów).
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

$fail = new class implements ImageProvider {
	public function id(): string { return 'mock'; }
	public function model(): string { return (new MockImageProvider())->model(); }
	public function isMock(): bool { return true; }
	public function estimateCost(): ?float { return 0.0; }
	public function generate(string $prompt, array $direction): array|WP_Error
	{
		return new WP_Error('fake_policy', 'Dostawca odrzucił prompt (zasady treści).');
	}
};
$useFail = fn () => $fail;
$noLlm = fn () => null;
$noSpawn = fn () => false;
add_filter('clara/image_spawn_cron', $noSpawn);
add_filter('clara/llm_provider', $noLlm);

$suffix = wp_generate_password(6, false, false);
$users = [];
foreach (['a', 'b'] as $key) {
	$users[$key] = wp_insert_user(['user_login' => "clara_gal_{$key}_{$suffix}", 'user_pass' => wp_generate_password(24), 'user_email' => "clara_gal_{$key}_{$suffix}@example.invalid", 'role' => 'editor']);
}

global $wpdb;
$gens = Schema::table('generations');
$row = fn (int $id) => $wpdb->get_row($wpdb->prepare("SELECT * FROM {$gens} WHERE id = %d", $id), ARRAY_A);
$sources = fn (int $id) => $wpdb->get_results($wpdb->prepare('SELECT inspiration_id, role FROM ' . Schema::table('generation_sources') . ' WHERE generation_id = %d ORDER BY id', $id), ARRAY_A);

try {
	$a = $users['a'];
	$b = $users['b'];

	[, $project] = $req('POST', '/clara/v1/projects', $a, ['name' => 'Galeria test', 'brief' => ['summary' => 'Kryty basen.']]);
	$pid = (int) $project['id'];
	[, $section] = $req('POST', "/clara/v1/projects/{$pid}/sections", $a, ['type' => 'hero']);
	$sid = (int) $section['id'];
	[, $run] = $req('POST', "/clara/v1/sections/{$sid}/art-directions", $a, []);
	$req('POST', "/clara/v1/sections/{$sid}/images", $a, ['runId' => $run['runId']]);
	ImageJobs::process(10);
	[, $ref1] = $req('POST', "/clara/v1/projects/{$pid}/inspirations", $a, ['url' => 'https://example.com/basen-1', 'title' => 'Basen nocą']);
	[, $ref2] = $req('POST', "/clara/v1/projects/{$pid}/inspirations", $a, ['url' => 'https://example.org/aqua', 'title' => 'Aqua']);
	$refIds = [$ref1['inspiration']['id'], $ref2['inspiration']['id']];
	$gallery = "/clara/v1/sections/{$sid}/gallery";

	/*--- Galeria 4 wariantów ---*/
	[$code, $g] = $req('GET', $gallery, $a);
	$check('galeria: 4 gotowe warianty v1, bez wyboru', $code === 200 && count($g['items']) === 4 && $g['selectedId'] === null && array_unique(array_column($g['items'], 'version')) === [1] && array_unique(array_column($g['items'], 'status')) === ['succeeded'], [$code, $g['items'] ?? $g]);
	$original = array_values(array_filter($g['items'], fn ($i) => $i['directionKey'] === 'A'))[0];
	$check('oryginał: rootId = własne id, parent = kierunek A', $original['rootId'] === $original['id'] && $original['parent_id'] === $run['directions'][0]['id'] && $original['remixOf'] === null);

	/*--- Remix: nowa wersja bez nadpisania rodzica ---*/
	$before = $row($original['id']);
	$parentFile = get_attached_file($original['image']['id']);
	$parentHash = md5_file($parentFile);
	[$code, $batch] = $req('POST', "/clara/v1/images/{$original['id']}/remix", $a, ['prompt' => 'Cieplejsze światło, zachód słońca nad basenem', 'references' => $refIds]);
	$remix = $batch['items'][0] ?? [];
	$check('remix → 202, 1 nowy wariant w kolejce, v2', $code === 202 && count($batch['items']) === 1 && $remix['status'] === 'queued' && $remix['version'] === 2 && $remix['remixOf'] === $original['id'] && $remix['rootId'] === $original['id'], [$code, $batch]);
	$check('remix: parent_id = obraz źródłowy (nowy wiersz)', (int) $row($remix['id'])['parent_id'] === $original['id'] && $remix['id'] > $original['id']);
	$check('remix: prompt = rodzic + instrukcja + referencje, zakaz tekstu na końcu', str_contains($remix['prompt'], 'Cieplejsze światło') && str_contains($remix['prompt'], 'example.com') && str_contains($remix['prompt'], 'do NOT copy') && str_ends_with($remix['prompt'], 'leave clean space where text will be placed.') && mb_strlen($remix['prompt']) <= 4000);
	$meta = json_decode($row($remix['id'])['prompt_json'], true);
	$check('remix: prompt rodzica i instrukcja zapisane w historii', $meta['parentPrompt'] === json_decode($before['prompt_json'], true)['prompt'] && $meta['remixPrompt'] === 'Cieplejsze światło, zachód słońca nad basenem');
	$check('remix: źródła w generation_sources (role=reference)', $sources($remix['id']) === array_map(fn ($id) => ['inspiration_id' => (string) $id, 'role' => 'reference'], $refIds), $sources($remix['id']));
	$runBatch = array_values(array_filter($req('GET', "/clara/v1/sections/{$sid}/images", $a)[1], fn ($b) => $b['artRunId'] === $run['runId']))[0] ?? null;
	$check('remix nie trafia do partii 4 kierunków', $batch['artRunId'] === null && $runBatch && count($runBatch['items']) === 4);
	[$code, $data] = $req('POST', "/clara/v1/images/{$original['id']}/remix", $a, ['prompt' => 'Druga zmiana']);
	$check('remix w trakcie innego → 409', $code === 409 && str_contains($data['message'], 'trwa'), $code);

	ImageJobs::process(5);
	[, $g] = $req('GET', $gallery, $a);
	$v2 = array_column($g['items'], null, 'id')[$remix['id']];
	$check('remix gotowy: własny plik, różny od rodzica', $v2['status'] === 'succeeded' && $v2['image']['id'] !== $original['image']['id'] && md5_file(get_attached_file($v2['image']['id'])) !== $parentHash, $v2['status']);
	$check('rodzic nietknięty (wiersz i plik identyczne)', $row($original['id']) === $before && file_exists($parentFile) && md5_file($parentFile) === $parentHash);
	$check('galeria: 5 wariantów (4 oryginały + remiks)', count($g['items']) === 5 && $g['items'][0]['id'] === $remix['id']);

	[, $batch3] = $req('POST', "/clara/v1/images/{$remix['id']}/remix", $a, ['prompt' => 'Więcej przestrzeni na nagłówek']);
	ImageJobs::process(5);
	$v3 = $batch3['items'][0];
	$check('remix remiksu → v3, ten sam root', $v3['version'] === 3 && $v3['rootId'] === $original['id'] && $v3['remixOf'] === $remix['id']);
	[$code, $lineage] = $req('GET', "/clara/v1/images/{$v3['id']}/lineage", $a);
	$check('historia: ścieżka oryginał → v2 → v3, 3 wersje, kierunek A', $code === 200 && $lineage['path'] === [$original['id'], $remix['id'], $v3['id']] && count($lineage['versions']) === 3 && $lineage['direction']['key'] === 'A', [$code, $lineage['path'] ?? $lineage]);
	[, $branch] = $req('POST', "/clara/v1/images/{$original['id']}/remix", $a, ['prompt' => 'Wersja zimowa']);
	ImageJobs::process(5);
	[, $lin2] = $req('GET', "/clara/v1/images/{$branch['items'][0]['id']}/lineage", $a);
	$check('rozgałęzienie: drugi remiks oryginału → v2, historia ma 4 wersje', $branch['items'][0]['version'] === 2 && $lin2['path'] === [$original['id'], $branch['items'][0]['id']] && count($lin2['versions']) === 4);

	/*--- Walidacja i błędy ---*/
	[, $otherProject] = $req('POST', '/clara/v1/projects', $a, ['name' => 'Inny projekt A']);
	[, $foreignRef] = $req('POST', "/clara/v1/projects/{$otherProject['id']}/inspirations", $a, ['url' => 'https://example.net/x']);
	$bad = [
		'pusty prompt' => [['prompt' => ''], 'prompt'],
		'2 znaki' => [['prompt' => 'ab'], 'prompt'],
		'prompt > 1000' => [['prompt' => str_repeat('x', 1001)], 'prompt'],
		'references nie-lista' => [['prompt' => 'Zmiana', 'references' => 5], 'references'],
		'references z tekstem' => [['prompt' => 'Zmiana', 'references' => ['1']], 'references'],
		'6 referencji' => [['prompt' => 'Zmiana', 'references' => [1, 2, 3, 4, 5, 6]], 'references'],
		'powtórzona referencja' => [['prompt' => 'Zmiana', 'references' => [$refIds[0], $refIds[0]]], 'references'],
		'referencja z innego projektu' => [['prompt' => 'Zmiana', 'references' => [$foreignRef['inspiration']['id']]], 'references'],
		'nieistniejąca referencja' => [['prompt' => 'Zmiana', 'references' => [999999999]], 'references'],
	];
	foreach ($bad as $label => [$body, $field]) {
		[$code, $data] = $req('POST', "/clara/v1/images/{$original['id']}/remix", $a, $body);
		$check("walidacja remiksu: {$label} → 400", $code === 400 && isset($data['data']['errors'][$field]), [$code, $data['data']['errors'] ?? $data['message'] ?? null]);
	}
	[$code] = $req('POST', "/clara/v1/images/{$run['directions'][0]['id']}/remix", $a, ['prompt' => 'Zmiana']);
	$check('remix kierunku (nie obrazu) → 404', $code === 404, $code);

	add_filter('clara/image_provider', $useFail);
	[, $failed] = $req('POST', "/clara/v1/images/{$original['id']}/remix", $a, ['prompt' => 'Zmiana odrzucona']);
	ImageJobs::process(5);
	remove_filter('clara/image_provider', $useFail);
	[, $g] = $req('GET', $gallery, $a);
	$failedItem = array_column($g['items'], null, 'id')[$failed['items'][0]['id']];
	$check('błąd dostawcy w remiksie: wariant failed z komunikatem, rodzic gotowy', $failedItem['status'] === 'failed' && $failedItem['error']['message'] === 'Dostawca odrzucił prompt (zasady treści).' && $row($original['id']) === $before);
	[$code, $data] = $req('POST', "/clara/v1/images/{$failedItem['id']}/remix", $a, ['prompt' => 'Zmiana']);
	$check('remix nieudanego obrazu → 409', $code === 409 && str_contains($data['message'], 'gotowego'), $code);

	/*--- Wybór wariantu ---*/
	$sel = "/clara/v1/sections/{$sid}/selection";
	[$code, $data] = $req('POST', $sel, $a, ['generationId' => $remix['id']]);
	[, $g] = $req('GET', $gallery, $a);
	[, $sections] = $req('GET', "/clara/v1/projects/{$pid}/sections", $a);
	$check('wybór v2 zapisany w sekcji, generacje bez zmian', $code === 200 && $data['selectedId'] === $remix['id'] && $g['selectedId'] === $remix['id'] && $sections[0]['selected_generation_id'] === $remix['id'] && $row($original['id']) === $before);
	[$code] = $req('POST', $sel, $a, ['generationId' => $failedItem['id']]);
	$check('wybór nieudanego wariantu → 409', $code === 409, $code);
	[, $otherSection] = $req('POST', "/clara/v1/projects/{$pid}/sections", $a, ['type' => 'hero', 'name' => 'Druga']);
	[$code] = $req('POST', "/clara/v1/sections/{$otherSection['id']}/selection", $a, ['generationId' => $remix['id']]);
	$check('wybór wariantu z innej sekcji → 400', $code === 400, $code);
	foreach (['abc', 0, -1, 1.5, [1]] as $value) {
		[$code] = $req('POST', $sel, $a, ['generationId' => $value]);
		$check('wybór: nieprawidłowe id ' . wp_json_encode($value) . ' → 400', $code === 400, $code);
	}
	[$code, $data] = $req('POST', $sel, $a, ['generationId' => null]);
	$check('wybór: null czyści', $code === 200 && $data['selectedId'] === null && $req('GET', $gallery, $a)[1]['selectedId'] === null);
	$req('POST', $sel, $a, ['generationId' => $original['id']]);

	/*--- Uprawnienia ---*/
	foreach ([['GET', $gallery, null], ['POST', $sel, ['generationId' => $original['id']]], ['GET', "/clara/v1/images/{$original['id']}/lineage", null], ['POST', "/clara/v1/images/{$original['id']}/remix", ['prompt' => 'Cudza zmiana']]] as [$method, $route, $body]) {
		[$code] = $req($method, $route, $b, $body);
		$check("inny użytkownik: {$method} " . preg_replace('/\d+/', '{id}', $route) . ' → 404', $code === 404, $code);
		[$code] = $req($method, $route, 0, $body);
		$check("niezalogowany: {$method} " . preg_replace('/\d+/', '{id}', $route) . ' → 401', $code === 401, $code);
	}
	$check('cudzy remiks nie utworzył generacji', count($req('GET', $gallery, $a)[1]['items']) === 8);
	$req('PATCH', "/clara/v1/sections/{$sid}", $a, ['status' => 'archived']);
	[$code] = $req('POST', "/clara/v1/images/{$original['id']}/remix", $a, ['prompt' => 'Zmiana']);
	$check('remix w zarchiwizowanej sekcji → 409', $code === 409, $code);
	$req('PATCH', "/clara/v1/sections/{$sid}", $a, ['status' => 'draft']);

	/*--- Historia po usunięciu referencji i kaskady ---*/
	$req('DELETE', "/clara/v1/inspirations/{$refIds[0]}", $a);
	[, $g] = $req('GET', $gallery, $a);
	$src = array_column($g['items'], null, 'id')[$remix['id']]['sources'];
	$check('usunięta inspiracja: migawka w historii zachowana, oznaczona jako niedostępna', count($src) === 2 && $src[0]['title'] === 'Basen nocą' && $src[0]['available'] === false && $src[1]['available'] === true && count($sources($remix['id'])) === 2);
	$req('DELETE', "/clara/v1/sections/{$sid}", $a);
	$check('usunięcie sekcji kasuje generacje i ich źródła', $row($remix['id']) === null && $sources($remix['id']) === []);
} finally {
	remove_filter('clara/image_spawn_cron', $noSpawn);
	remove_filter('clara/llm_provider', $noLlm);
	remove_filter('clara/image_provider', $useFail);
	foreach ($users as $userId) {
		foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::table('projects') . ' WHERE owner_user_id = %d', $userId)) as $id) {
			Projects::delete((int) $id);
		}
		wp_delete_user($userId);
	}
	wp_clear_scheduled_hook(ImageJobs::HOOK);
	wp_set_current_user(0);
}

WP_CLI::log("Galeria i remix: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
