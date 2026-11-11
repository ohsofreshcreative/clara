<?php

/**
 * Dashboard → 4 obrazy-inspiracje (InspirationGenerator) na atrapie generatora — bez kosztów i sieci.
 * Do tego kontrakt adaptera OpenAI (budowa żądania, parsowanie odpowiedzi i błędów) bez wywołań API.
 */

use App\Clara\Creative\ImageJobs;
use App\Clara\Creative\ImageProvider;
use App\Clara\Creative\InspirationGenerator;
use App\Clara\Creative\MockImageProvider;
use App\Clara\Creative\OpenAiImageProvider;
use App\Clara\Projects\Projects;
use App\Clara\Projects\Sections;

require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = 0;
$passes = 0;
$check = function (string $name, bool $ok, mixed $detail = '') use (&$failures, &$passes) {
	$ok ? $passes++ : $failures++;
	$detail = is_string($detail) ? $detail : wp_json_encode($detail, JSON_UNESCAPED_UNICODE);
	WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $name . (! $ok && $detail !== '' ? ' — ' . mb_substr($detail, 0, 600) : ''));
};
$req = function (string $method, string $route, int $user, ?array $json = null) {
	wp_set_current_user($user);
	$request = new WP_REST_Request($method, '/clara/v1' . $route);
	if ($json !== null) {
		$request->set_header('content-type', 'application/json');
		$request->set_body(wp_json_encode($json));
	}
	$response = rest_do_request($request);
	return [$response->get_status(), $response->get_data()];
};

$jpeg = (string) file_get_contents(get_theme_file_path('resources/images/clara/hero-figure.jpg'));
$fake = new class($jpeg) implements ImageProvider {
	public array $prompts = [];
	public int $failOn = -1;
	public function __construct(private string $jpeg) {}
	public function id(): string { return 'fake-img'; }
	public function model(): string { return 'fake-image-model'; }
	public function isMock(): bool { return false; }
	public function estimateCost(): ?float { return 0.05; }
	public function generate(string $prompt, array $direction): array|WP_Error
	{
		$this->prompts[] = $prompt;
		if (count($this->prompts) - 1 === $this->failOn || $this->failOn === 99) {
			return new WP_Error('clara_image_moderation', 'OpenAI odrzucił prompt przez zasady treści — zmień opis.');
		}
		return ['body' => $this->jpeg, 'mime' => 'image/jpeg', 'cost' => null, 'meta' => []];
	}
};
$fakeFilter = fn () => $fake;
$block = fn ($pre, $args, $url) => str_contains($url, 'api.openai.com') || str_contains($url, 'api.anthropic.com') ? new WP_Error('blocked', 'Test nie może wołać prawdziwego API.') : $pre;
add_filter('pre_http_request', $block, 10, 3);

$suffix = wp_generate_password(6, false, false);
$users = [];
foreach (['a' => 'editor', 'b' => 'editor', 's' => 'subscriber'] as $key => $role) {
	$users[$key] = wp_insert_user(['user_login' => "clara_gen_{$key}_{$suffix}", 'user_pass' => wp_generate_password(24), 'user_email' => "clara_gen_{$key}_{$suffix}@example.invalid", 'role' => $role]);
}
$projects = [];

try {
	[$a, $b, $s] = [$users['a'], $users['b'], $users['s']];

	// Adapter OpenAI: wybór, żądanie, odpowiedzi — bez sieci.
	$check('bez klucza OpenAI domyślnie mock', ImageJobs::provider() instanceof MockImageProvider);
	add_filter('clara/openai_api_key', fn () => 'sk-test-not-real');
	$check('z kluczem OpenAI domyślnie OpenAiImageProvider (gpt-image-2)', ImageJobs::provider() instanceof OpenAiImageProvider && ImageJobs::provider()->model() === 'gpt-image-2');
	remove_all_filters('clara/openai_api_key');
	$body = json_decode(OpenAiImageProvider::body('gpt-image-2', 'Hero basenu'), true);
	$check('żądanie: model, 1 obraz 1536×1024, JPEG, bez klucza w treści', $body === ['model' => 'gpt-image-2', 'prompt' => 'Hero basenu', 'n' => 1, 'size' => '1536x1024', 'quality' => 'medium', 'output_format' => 'jpeg']);
	$ok = OpenAiImageProvider::parse(200, wp_json_encode(['data' => [['b64_json' => base64_encode($jpeg)]], 'usage' => ['input_tokens' => 50, 'output_tokens' => 4000]]), 0.06);
	$check('odpowiedź 200 → JPEG z bajtami i metadanymi zużycia', ! is_wp_error($ok) && $ok['mime'] === 'image/jpeg' && $ok['body'] === $jpeg && $ok['meta']['outputTokens'] === 4000, is_wp_error($ok) ? $ok->get_error_message() : $ok['meta']);
	$e401 = OpenAiImageProvider::parse(401, '{"error":{"message":"Incorrect API key sk-xxx"}}', 0.06);
	$check('401 → komunikat po polsku bez echa odpowiedzi', is_wp_error($e401) && str_contains($e401->get_error_message(), 'nieprawidłowy') && ! str_contains($e401->get_error_message(), 'sk-xxx'));
	$mod = OpenAiImageProvider::parse(400, '{"error":{"code":"moderation_blocked"}}', 0.06);
	$check('moderacja → czytelny błąd', is_wp_error($mod) && $mod->get_error_code() === 'clara_image_moderation');
	$e500 = OpenAiImageProvider::parse(503, '', 0.06);
	$check('5xx → błąd przejściowy (retryable)', is_wp_error($e500) && $e500->get_error_data()['retryable'] === true);
	$check('pusta odpowiedź 200 → błąd', is_wp_error(OpenAiImageProvider::parse(200, '{"data":[]}', 0.06)));

	// Bez prawdziwego generatora dashboard nie udaje wyniku.
	[$code, $data] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero basen otwock h2otwock']);
	$check('bez klucza → 409 z jasnym komunikatem', $code === 409 && str_contains($data['message'] ?? '', 'CLARA_OPENAI_API_KEY'), $data);
	[$code] = $req('POST', '/generate', 0, ['prompt' => 'sekcja Hero basen']);
	[$code2] = $req('POST', '/generate', $s, ['prompt' => 'sekcja Hero basen']);
	[$code3] = $req('POST', '/generate/1/render', 0);
	$check('niezalogowany 401, subskrybent 403 (start i render)', $code === 401 && $code2 === 403 && $code3 === 401, [$code, $code2, $code3]);

	add_filter('clara/image_provider', $fakeFilter);
	[$code] = $req('POST', '/generate', $a, ['prompt' => 'abc']);
	$check('za krótki prompt → 400', $code === 400, $code);

	// Krok 1: start — 4 warianty w kolejce, od razu (bez czekania na obrazy).
	[$code, $data] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero basen otwock h2otwock']);
	$projects[] = $pid = (int) ($data['project']['id'] ?? 0);
	$items = $data['batch']['items'] ?? [];
	$check('start → 201, 4 warianty queued, generator jeszcze nie wołany', $code === 201 && count($items) === 4 && ! array_filter($items, fn ($i) => $i['status'] !== 'queued') && $fake->prompts === [], [$code, $data['message'] ?? null, array_column($items, 'status')]);
	$check('bez wybranego projektu — nowy projekt z nazwy z promptu', ($data['project']['name'] ?? '') === 'Sekcja Hero basen otwock h2otwock' && (int) Projects::find($pid)['owner_user_id'] === $a, $data['project'] ?? null);
	$check('4 propozycje bez sztywnych układów (Propozycja 1–4)', array_column($items, 'title') === ['Propozycja 1', 'Propozycja 2', 'Propozycja 3', 'Propozycja 4'], array_column($items, 'title'));
	$check('prompt „Hero” → sekcja hero z nawigacją', Sections::find((int) $data['section']['id'])['type'] === 'hero' && str_contains($items[0]['prompt'], 'hero section') && ! str_contains($items[0]['prompt'], 'split layout'), $items[0]['prompt']);

	// Rodzaj sekcji z promptu — karty oferty to nie hero.
	$kinds = ['karty oferty basenu: 3 pakiety wejść' => 'services', 'cennik karnetów' => 'pricing', 'FAQ o basenie' => 'faq', 'opinie klientów' => 'testimonials', 'stopka strony' => 'footer', 'sekcja z mapą dojazdu i formularzem' => 'contact', 'coś fajnego dla basenu' => 'other'];
	$got = array_map(fn ($p) => InspirationGenerator::sectionKind($p), array_keys($kinds));
	$check('rozpoznanie rodzaju sekcji z promptu', $got === array_values($kinds), array_combine(array_keys($kinds), $got));
	[$code, $offer] = $req('POST', '/generate', $a, ['prompt' => 'karty oferty basenu: 3 pakiety wejść', 'projectId' => $pid]);
	$offerPrompt = $offer['batch']['items'][0]['prompt'] ?? '';
	$check('karty oferty → sekcja services, prompt kart bez hero i nawigacji', $code === 201 && Sections::find((int) $offer['section']['id'])['type'] === 'services' && str_starts_with($offer['section']['name'], 'Karty oferty') && str_contains($offerPrompt, 'services section made of cards') && str_contains($offerPrompt, 'not a hero') && ! str_contains($offerPrompt, 'hero section'), [$code, $offer['section'] ?? null, $offerPrompt]);
	foreach ($offer['batch']['items'] ?? [] as $item) {
		$req('POST', "/generate/{$item['id']}/render", $a);
	}
	$fake->prompts = [];
	$check('koszt szacunkowy partii (4 × 0,05)', abs(($data['batch']['cost'] ?? 0) - 0.2) < 0.001 && ! $data['batch']['mock'], $data['batch']['cost'] ?? null);

	// Krok 2: render każdego wariantu osobno.
	$rendered = [];
	foreach ($items as $item) {
		[$code, $one] = $req('POST', "/generate/{$item['id']}/render", $a);
		$rendered[] = [$code, $one['status'] ?? null, (bool) ($one['image'] ?? null)];
	}
	$check('render → 4 obrazy gotowe, po jednym wywołaniu generatora na wariant', count(array_filter($rendered, fn ($r) => $r === [200, 'succeeded', true])) === 4 && count($fake->prompts) === 4 && count(array_unique($fake->prompts)) === 4, $rendered);
	$check('prompt zawiera opis użytkownika (PL) jako dane i wymóg polskich tekstów', str_contains($fake->prompts[0], '"sekcja Hero basen otwock h2otwock"') && str_contains($fake->prompts[0], 'Polish'), $fake->prompts[0]);
	[$code, $again] = $req('POST', "/generate/{$items[0]['id']}/render", $a);
	$check('ponowny render gotowego wariantu nie generuje drugi raz (bez kosztu)', $code === 200 && $again['status'] === 'succeeded' && count($fake->prompts) === 4, [$code, count($fake->prompts)]);
	[$code] = $req('POST', "/generate/{$items[0]['id']}/render", $b);
	$check('render cudzego wariantu → 404', $code === 404, $code);

	$sectionId = (int) $data['section']['id'];
	[$code, $gallery] = $req('GET', "/sections/{$sectionId}/gallery", $a);
	$check('obrazy w galerii sekcji (dalej remix / layout)', $code === 200 && count($gallery['items']) === 4, [$code, count($gallery['items'] ?? [])]);
	$imageId = (int) $items[0]['id'];
	[$code, $layout] = $req('POST', "/images/{$imageId}/layouts", $a, []);
	$check('Layout JSON z obrazu inspiracji', $code === 201 && ($layout['validation']['valid'] ?? false), [$code, $layout['code'] ?? null]);
	[$code, $bundle] = $req('GET', '/layouts/' . (int) ($layout['id'] ?? 0) . '/figma-bundle', $a);
	$check('paczka Figmy z obrazem inspiracji', $code === 200 && ($bundle['assets'][(string) $imageId]['mime'] ?? '') === 'image/jpeg', [$code, $bundle['code'] ?? null]);

	// Kierunek projektu
	[$code, $noDir] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero basen', 'projectId' => $pid, 'keepDirection' => true]);
	$check('„trzymaj się kierunku” bez kierunku → 409 z instrukcją', $code === 409 && ($noDir['code'] ?? '') === 'clara_direction_missing', [$code, $noDir['code'] ?? null]);
	$dirId = (int) $items[1]['id'];
	[$code, $dir] = $req('PUT', "/projects/{$pid}/direction", $a, ['generationId' => $dirId]);
	$check('obranie kierunku zapisuje wariant, tytuł i obraz', $code === 200 && $dir['direction']['generationId'] === $dirId && $dir['direction']['layout'] === 'full-bleed' && str_contains($dir['direction']['imageUrl'], '.jpg') && (Projects::find($pid)['brief']['direction']['generationId'] ?? 0) === $dirId, [$code, $dir]);
	[$code] = $req('PUT', "/projects/{$pid}/direction", $b, ['generationId' => $dirId]);
	[$code2] = $req('PUT', "/projects/{$pid}/direction", $a, ['generationId' => 999999999]);
	$check('kierunek: cudzy projekt 404, obcy wariant 404', $code === 404 && $code2 === 404, [$code, $code2]);
	[$code] = $req('PATCH', "/projects/{$pid}", $a, ['brief' => ['summary' => 'Nowy opis briefu']]);
	$check('edycja briefu nie kasuje kierunku', $code === 200 && (Projects::find($pid)['brief']['direction']['generationId'] ?? 0) === $dirId && Projects::find($pid)['brief']['summary'] === 'Nowy opis briefu', $code);

	// Generowanie w kierunku: prawdziwy adapter OpenAI na przechwyconym HTTP — referencja trafia do /images/edits.
	remove_filter('clara/image_provider', $fakeFilter);
	add_filter('clara/openai_api_key', fn () => 'sk-test-not-real');
	$sent = [];
	$openai = function ($pre, $args, $url) use (&$sent, $jpeg) {
		if (! str_starts_with($url, 'https://api.openai.com/')) {
			return $pre;
		}
		$sent[] = ['url' => $url, 'type' => $args['headers']['Content-Type'] ?? '', 'auth' => str_starts_with($args['headers']['Authorization'] ?? '', 'Bearer '), 'hasImage' => str_contains((string) $args['body'], 'name="image[]"') && str_contains((string) $args['body'], "\xFF\xD8\xFF"), 'refSize' => strlen((string) $args['body']), 'body' => (string) $args['body']];
		return ['headers' => [], 'body' => wp_json_encode(['data' => [['b64_json' => base64_encode($jpeg)]]]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
	};
	add_filter('pre_http_request', $openai, 99, 3);
	[$code, $dirRun] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero basen zima', 'projectId' => $pid, 'keepDirection' => true]);
	$dirItems = $dirRun['batch']['items'] ?? [];
	$check('start w kierunku → 4 odmiany, flaga keepDirection, sekcja „w kierunku”', $code === 201 && $dirRun['keepDirection'] === true && count($dirItems) === 4 && str_contains($dirRun['section']['name'], 'kierunku') && str_starts_with($dirItems[0]['title'], 'W kierunku'), [$code, $dirRun['code'] ?? null]);
	foreach ($dirItems as $item) {
		$req('POST', "/generate/{$item['id']}/render", $a);
	}
	$dirSent = $sent;
	$check('render w kierunku → multipart /images/edits z obrazem referencji i promptem stylu', count($sent) === 4 && ! array_filter($sent, fn ($r) => $r['url'] !== OpenAiImageProvider::EDITS_ENDPOINT || ! str_starts_with($r['type'], 'multipart/form-data; boundary=') || ! $r['auth'] || ! $r['hasImage'] || ! str_contains($r['body'], 'chosen design direction')), array_map(fn ($r) => array_diff_key($r, ['body' => 1]), $sent));
	[, $dirGallery] = $req('GET', '/sections/' . (int) $dirRun['section']['id'] . '/gallery', $a);
	$check('odmiany w kierunku zapisane jako obrazy OpenAI', count(array_filter($dirGallery['items'], fn ($i) => $i['status'] === 'succeeded' && $i['provider'] === 'openai')) === 4, array_map(fn ($i) => [$i['status'], $i['provider'] ?? null, $i['error'] ?? null], $dirGallery['items']));
	$sent = [];
	[$code, $plain] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero basen lato', 'projectId' => $pid, 'keepDirection' => false]);
	$req('POST', '/generate/' . $plain['batch']['items'][0]['id'] . '/render', $a);
	$check('bez opcji kierunku → zwykłe /images/generations (JSON)', count($sent) === 1 && $sent[0]['url'] === OpenAiImageProvider::ENDPOINT && $sent[0]['type'] === 'application/json', array_map(fn ($r) => array_diff_key($r, ['body' => 1]), $sent));
	$check('runda z dashboardu to szkic: quality=low w żądaniu', (json_decode($sent[0]['body'], true)['quality'] ?? '') === 'low', json_decode($sent[0]['body'], true)['quality'] ?? null);
	$check('referencja kierunku pomniejszona (mniejsza niż oryginał)', $dirSent[0]['refSize'] < strlen($jpeg) || getimagesizefromstring($jpeg)[0] <= 768, [$dirSent[0]['refSize'], strlen($jpeg)]);

	// „Dopracuj”: szkic → ten sam projekt w jakości medium, szkic jako referencja.
	global $wpdb;
	$draftId = (int) $plain['batch']['items'][0]['id'];
	$draftCost = (float) $wpdb->get_var($wpdb->prepare('SELECT estimated_cost FROM ' . \App\Clara\Database\Schema::table('generations') . ' WHERE id = %d', $draftId));
	$sent = [];
	[$code, $refined] = $req('POST', "/generate/{$draftId}/refine", $a);
	$check('refine → 201, nowy wariant w kolejce z refineOf i quality=medium', $code === 201 && $refined['status'] === 'queued' && $refined['refineOf'] === $draftId && $refined['quality'] === 'medium' && $refined['id'] !== $draftId && $refined['directionKey'] === $plain['batch']['items'][0]['directionKey'], [$code, $refined]);
	[$code2, $again] = $req('POST', "/generate/{$draftId}/refine", $a);
	$check('powtórny refine zwraca ten sam wariant (bez podwójnego kosztu)', $code2 === 201 && $again['id'] === $refined['id'], [$code2, $again['id'] ?? null]);
	[$code, $done] = $req('POST', "/generate/{$refined['id']}/render", $a);
	$body = $sent[0]['body'] ?? '';
	$check('render dopracowania → /images/edits, quality=medium, szkic jako referencja, prompt „final quality”', $code === 200 && $done['status'] === 'succeeded' && count($sent) === 1 && $sent[0]['url'] === OpenAiImageProvider::EDITS_ENDPOINT && $sent[0]['hasImage'] && str_contains($body, "name=\"quality\"\r\n\r\nmedium") && str_contains($body, 'final quality'), [$code, $done['status'] ?? null, count($sent)]);
	$refinedCost = (float) $wpdb->get_var($wpdb->prepare('SELECT estimated_cost FROM ' . \App\Clara\Database\Schema::table('generations') . ' WHERE id = %d', $refined['id']));
	$check('koszt szkicu niższy niż dopracowania', $draftCost > 0 && $draftCost < $refinedCost, [$draftCost, $refinedCost]);
	[$code] = $req('POST', "/generate/{$refined['id']}/refine", $a);
	[$code2] = $req('POST', "/generate/{$draftId}/refine", $b);
	$check('refine: wariant już dopracowany 409, cudzy 404', $code === 409 && $code2 === 404, [$code, $code2]);

	// Wzór stylu: screen referencyjny z design systemu + frame z Figmy idą do AI razem z promptem.
	$screen = \App\Clara\Projects\Attachments::store($pid, $a, $jpeg, 'podstrona-oferta.jpg');
	$frame = \App\Clara\Projects\Attachments::store($pid, $a, $jpeg, 'strona-glowna-figma-1.jpg');
	$foreignShot = \App\Clara\Projects\Attachments::store((int) Projects::create(['name' => 'Obcy DS'], $b), $b, $jpeg, 'obcy.jpg');
	\App\Clara\DesignSystems\DesignSystems::createVersion($pid, array_fill_keys(\App\Clara\DesignSystems\TokenSchema::GROUPS, []), [
		['role' => 'figma_frame', 'frameName' => 'Strona główna'] + $frame,
		['role' => 'reference_screen'] + $screen,
		['role' => 'reference_screen'] + $foreignShot,
	], ['type' => 'reference_screens']);
	$refs = InspirationGenerator::styleReferences(Projects::find($pid), \App\Clara\DesignSystems\DesignSystems::latest($pid));
	$check('wzór stylu: najpierw screeny, potem frame’y Figmy, bez cudzych plików', $refs === [(int) $screen['id'], (int) $frame['id']], $refs);
	$sent = [];
	[$code, $styled] = $req('POST', '/generate', $a, ['prompt' => 'karty oferty basenu na lato', 'projectId' => $pid]);
	$req('POST', '/generate/' . $styled['batch']['items'][0]['id'] . '/render', $a);
	$body = $sent[0]['body'] ?? '';
	$check('generowanie ze screenami projektu → /images/edits z 2 obrazami i promptem „style reference”', $code === 201 && count($sent) === 1 && $sent[0]['url'] === OpenAiImageProvider::EDITS_ENDPOINT && substr_count($body, 'name="image[]"') === 2 && str_contains($body, 'style reference') && str_contains($body, 'services section made of cards'), [$code, count($sent), substr_count($body, 'name="image[]"')]);
	remove_filter('pre_http_request', $openai, 99);
	remove_all_filters('clara/openai_api_key');
	add_filter('clara/image_provider', $fakeFilter);

	// „Generuj jeszcze”: ta sama sekcja, inne kompozycje w kolejnych rundach.
	[$code, $more] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero basen otwock h2otwock', 'projectId' => $pid, 'sectionId' => $sectionId, 'round' => 1]);
	$moreTitles = array_column($more['batch']['items'] ?? [], 'title');
	$check('generuj jeszcze → 4 nowe warianty w tej samej sekcji, inne kompozycje niż runda 0', $code === 201 && $more['section']['id'] === $sectionId && $more['round'] === 1 && count($moreTitles) === 4 && ! array_intersect($moreTitles, array_column($items, 'title')), [$code, $more['code'] ?? null, $moreTitles]);
	$fake->prompts = [];
	$req('POST', '/generate/' . $more['batch']['items'][0]['id'] . '/render', $a);
	$check('prompt kolejnej rundy prosi o wyraźnie inne propozycje', str_contains($fake->prompts[0] ?? '', 'clearly different from earlier proposals'), $fake->prompts[0] ?? null);
	[$code] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero basen otwock h2otwock', 'projectId' => $pid, 'sectionId' => $sectionId, 'round' => 2]);
	$check('kolejna runda, gdy poprzednia jeszcze trwa → 409', $code === 409, $code);
	global $wpdb;
	$wpdb->query($wpdb->prepare('UPDATE ' . \App\Clara\Database\Schema::table('generations') . " SET created_at = %s WHERE section_id = %d AND status = 'queued'", gmdate('Y-m-d H:i:s', time() - 900), $sectionId));
	[, $more2] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero basen otwock h2otwock', 'projectId' => $pid, 'sectionId' => $sectionId, 'round' => 2]);
	$abandoned = array_filter(ImageJobs::batch($sectionId, $more['batch']['batchId'])['items'], fn ($i) => $i['status'] === 'failed' && ($i['error']['code'] ?? '') === 'clara_image_abandoned');
	$check('porzucona runda (> 10 min) nie blokuje — warianty oznaczone jako przerwane', isset($more2['batch']) && count($abandoned) === 3, [$more2['code'] ?? null, count($abandoned)]);
	$check('runda 2 → jeszcze inny zestaw kompozycji', ! array_intersect(array_column($more2['batch']['items'], 'title'), $moreTitles) && ! array_intersect(array_column($more2['batch']['items'], 'title'), array_column($items, 'title')), array_column($more2['batch']['items'], 'title'));
	[, $gallery] = $req('GET', "/sections/{$sectionId}/gallery", $a);
	$check('galeria sekcji zbiera wszystkie rundy (12 wariantów)', count($gallery['items']) === 12, count($gallery['items']));
	$foreignSection = Sections::create(Projects::create(['name' => 'Inny'], $a), ['type' => 'hero', 'name' => 'X']);
	$projects[] = (int) Sections::find($foreignSection)['project_id'];
	[$code] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero basen', 'projectId' => $pid, 'sectionId' => $foreignSection, 'round' => 1]);
	$check('sekcja z innego projektu → 404', $code === 404, $code);

	[$code, $cleared] = $req('DELETE', "/projects/{$pid}/direction", $a);
	$check('usunięcie kierunku', $code === 200 && $cleared['direction'] === null && ! isset(Projects::find($pid)['brief']['direction']), [$code, $cleared]);

	// Błędy
	$own = Projects::create(['name' => 'Własny projekt'], $a);
	$projects[] = $own;
	$fake->prompts = [];
	$fake->failOn = 2;
	[$code, $data2] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero basen', 'projectId' => $own]);
	$results = [];
	foreach ($data2['batch']['items'] as $item) {
		[, $results[$item['directionKey']]] = $req('POST', "/generate/{$item['id']}/render", $a);
	}
	$check('wybrany projekt; odrzucony wariant ma czytelny błąd, reszta gotowa', $code === 201 && $data2['project']['id'] === $own && $results['C']['status'] === 'failed' && str_contains($results['C']['error']['message'] ?? '', 'zasady treści') && $results['A']['status'] === 'succeeded', array_map(fn ($r) => $r['status'] ?? null, $results));
	[$code] = $req('POST', '/generate', $b, ['prompt' => 'sekcja Hero basen', 'projectId' => $own]);
	$check('cudzy projekt → 404', $code === 404, $code);

	$fake->failOn = -1;
	[, $busyRun] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero równolegle', 'projectId' => $own]);
	global $wpdb;
	$busyId = (int) $busyRun['batch']['items'][0]['id'];
	$wpdb->update(\App\Clara\Database\Schema::table('generations'), ['status' => 'running'], ['id' => $busyId]);
	$before = count($fake->prompts);
	[$code, $busy] = $req('POST', "/generate/{$busyId}/render", $a);
	$check('wariant w trakcie generowania → 409, bez drugiego wywołania', $code === 409 && count($fake->prompts) === $before, [$code, $busy['code'] ?? null]);
	$wpdb->update(\App\Clara\Database\Schema::table('generations'), ['created_at' => gmdate('Y-m-d H:i:s', time() - 900)], ['id' => $busyId]);
	[$code, $recovered] = $req('POST', "/generate/{$busyId}/render", $a);
	$check('porzucony wariant (running > 10 min) można wygenerować ponownie', $code === 200 && $recovered['status'] === 'succeeded', [$code, $recovered, $wpdb->get_row($wpdb->prepare('SELECT status, created_at FROM ' . \App\Clara\Database\Schema::table('generations') . ' WHERE id = %d', $busyId))]);

	add_filter('clara/inspire_rate_limit', fn () => 0);
	[$code] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero limit']);
	$check('limit generowań na godzinę → 429', $code === 429, $code);
	remove_all_filters('clara/inspire_rate_limit');

	add_filter('clara/image_daily_budget', fn () => 0.1);
	$count = count(Projects::forOwner($a, 1, 50));
	[$code] = $req('POST', '/generate', $a, ['prompt' => 'sekcja Hero budżet']);
	$check('dzienny budżet przekroczony → 402, bez pustego projektu', $code === 402 && count(Projects::forOwner($a, 1, 50)) === $count, $code);
	remove_all_filters('clara/image_daily_budget');
} finally {
	foreach (array_filter($projects) as $pid) {
		Projects::delete($pid);
	}
	foreach ($users as $userId) {
		wp_delete_user($userId);
	}
	delete_transient('clara_inspire_rl_' . $users['a']);
	remove_filter('pre_http_request', $block, 10);
	wp_set_current_user(0);
}

WP_CLI::log("Inspiracje z promptu: {$passes} PASS, {$failures} FAIL");

if ($failures > 0) {
	WP_CLI::halt(1);
}
