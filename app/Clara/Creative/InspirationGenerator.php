<?php

namespace App\Clara\Creative;

use App\Clara\Database\Schema;
use App\Clara\DesignSystems\DesignSystems;
use App\Clara\Projects\Attachments;
use App\Clara\Projects\Projects;
use App\Clara\Projects\SectionInput;
use App\Clara\Projects\Sections;
use App\Clara\Support\Validator;
use WP_Error;

/**
 * Dashboard: prompt (po polsku) → 4 obrazy-inspiracje sekcji. Rodzaj sekcji (hero, karty oferty,
 * cennik, FAQ…) wynika z promptu; układ dobiera model, warianty różni tylko akcent nastroju.
 *
 * Dwa kroki, żeby UI pokazywało postęp: `start` od razu tworzy 4 warianty (status queued),
 * a `render` generuje jeden wariant — klient woła go równolegle dla każdego kafelka i pokazuje
 * obrazy, gdy tylko są gotowe. Obrazy to zwykłe warianty galerii sekcji (remix, Layout JSON, Figma).
 *
 * Kierunek projektu: wybrany wariant zapisany w projekcie. Z opcją „trzymaj się kierunku” kolejne
 * generowania dostają jego obraz jako referencję stylu i robią 4 odmiany w tej samej konwencji.
 */
class InspirationGenerator
{
	/**
	 * Rodzaj sekcji rozpoznany z promptu (pierwsze trafienie wygrywa). Bez trafienia generujemy dokładnie
	 * to, co opisuje brief — nie zakładamy hero.
	 */
	public const SECTION_KINDS = [
		'pricing' => ['pattern' => '/cennik|ceny|cenow|abonament|plan(y|ów)? (cenow|taryf)/u', 'label' => 'Cennik', 'prompt' => 'a pricing section with price plans / packages'],
		'services' => ['pattern' => '/kart[ay]? ofert|ofert|usług|pakiet|atrakcj|produkt|kafelk|karty|kart /u', 'label' => 'Karty oferty', 'prompt' => 'an offer / services section made of cards (each card: image or icon, title, short description, link or button)'],
		'faq' => ['pattern' => '/\bfaq\b|pytani|najczęściej/u', 'label' => 'FAQ', 'prompt' => 'an FAQ section with a list of questions and answers (accordion)'],
		'contact' => ['pattern' => '/kontakt|formularz|mapa|dojazd/u', 'label' => 'Kontakt', 'prompt' => 'a contact section with contact details and a contact form or map'],
		'gallery' => ['pattern' => '/galeri|zdjęci|portfolio|realizacj/u', 'label' => 'Galeria', 'prompt' => 'a gallery / portfolio section showing a grid of images'],
		'testimonials' => ['pattern' => '/opini|referencj|recenzj|klienci mówią|testimonial/u', 'label' => 'Opinie', 'prompt' => 'a testimonials section with customer reviews'],
		'about' => ['pattern' => '/o nas|o firmie|histori|zespół|zespol|misj/u', 'label' => 'O nas', 'prompt' => 'an about-us section telling the company story'],
		'cta' => ['pattern' => '/\bcta\b|wezwani|newsletter|zapis/u', 'label' => 'Wezwanie do działania', 'prompt' => 'a call-to-action banner section'],
		'footer' => ['pattern' => '/stopk|footer/u', 'label' => 'Stopka', 'prompt' => 'a website footer'],
		'hero' => ['pattern' => '/\bhero\b|baner|banner|nagłówek strony|strona główna|top strony/u', 'label' => 'Hero', 'prompt' => 'a hero section at the top of a homepage, with a simple navigation bar'],
	];

	/**
	 * Lekki akcent nastroju na wariant — tylko żeby 4 propozycje się różniły. Nie narzuca układu:
	 * kompozycję model dobiera sam do rodzaju sekcji i briefu.
	 */
	public const MOODS = [
		['light, airy and bright', 'dark, bold and high-contrast', 'colorful and graphic', 'minimal and elegant'],
		['warm and natural', 'modern and techy', 'playful and friendly', 'premium and editorial'],
		['soft pastel', 'monochrome with one strong accent color', 'photographic and atmospheric', 'geometric and structured'],
	];

	/** Odmiany w ramach obranego kierunku (ten sam styl, inny akcent). */
	public const VARIATIONS = [
		['stay very close to the reference: same style, refined details, better hierarchy and spacing', 'same style as the reference, with different imagery and fresh copy', 'same visual style as the reference, with stronger typographic emphasis', 'same palette, typography and mood as the reference, with a more daring arrangement of elements'],
		['same style as the reference, with different lighting / time of day in the imagery', 'same style as the reference, closer and more human imagery', 'same style as the reference, with a bit more content and detail', 'same style as the reference, calmer and more minimal, with more whitespace'],
	];

	public const ORIGIN = 'dashboard';

	/** Rundy to tanie szkice; „Dopracuj” generuje jeden wybrany wariant w wyższej jakości. */
	public const DRAFT_QUALITY = 'low';

	public const REFINE_QUALITY = 'medium';

	/** Obraz referencyjny (kierunek / szkic) jest pomniejszany — mniej tokenów wejściowych u dostawcy. */
	private const REFERENCE_WIDTH = 768;

	private const REFINE_REFERENCE_WIDTH = 1024;

	/** Screeny / frame'y projektu wysyłane jako wzór stylu (mniejsze — liczy się styl, nie detale). */
	private const STYLE_REFS = 3;

	private const STYLE_REFERENCE_WIDTH = 640;

	private const RATE_LIMIT = 12;

	public static function start(array $params, int $userId): array|WP_Error
	{
		$v = new Validator();
		$prompt = $v->text('prompt', $params['prompt'] ?? '', 2000, true, true);
		if ($prompt !== '' && mb_strlen($prompt) < 5) {
			$v->fail('prompt', 'Opisz sekcję — minimum 5 znaków.');
		}
		if (! $v->ok()) {
			return $v->error();
		}

		$project = null;
		if ($projectId = (int) ($params['projectId'] ?? 0)) {
			$project = Projects::find($projectId);
			if (! $project || (int) $project['owner_user_id'] !== $userId) {
				return new WP_Error('clara_not_found', __('Nie znaleziono projektu.', 'clara'), ['status' => 404]);
			}
		}

		$round = max(0, min(50, (int) ($params['round'] ?? 0)));
		$section = null;
		if ($reuse = (int) ($params['sectionId'] ?? 0)) {
			$section = $project ? Sections::find($reuse) : null;
			if (! $section || (int) $section['project_id'] !== (int) $project['id']) {
				return new WP_Error('clara_not_found', __('Nie znaleziono sekcji w tym projekcie.', 'clara'), ['status' => 404]);
			}
		}

		$reference = null;
		if (filter_var($params['keepDirection'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
			$reference = $project ? self::directionImage($project) : null;
			if (! $reference) {
				return new WP_Error('clara_direction_missing', __('Ten projekt nie ma jeszcze obranego kierunku — wybierz go przy jednej z inspiracji.', 'clara'), ['status' => 409]);
			}
		}

		$provider = ImageJobs::provider();
		if (! $provider || $provider->isMock()) {
			return new WP_Error('clara_image_unconfigured', __('Generowanie inspiracji wymaga klucza OpenAI (CLARA_OPENAI_API_KEY w wp-config.php).', 'clara'), ['status' => 409]);
		}
		if (! self::allow($userId)) {
			return new WP_Error('clara_rate_limited', sprintf(__('Limit %d generowań na godzinę — spróbuj później.', 'clara'), self::RATE_LIMIT), ['status' => 429]);
		}

		$created = ! $project;
		if ($created) {
			$project = Projects::find(Projects::create(['name' => self::projectName($prompt), 'brief' => ['summary' => $prompt]], $userId));
		}
		// „Generuj jeszcze” dokłada warianty do tej samej sekcji (jedna galeria do remiksu).
		$kind = self::sectionKind($prompt);
		$label = self::SECTION_KINDS[$kind]['label'] ?? 'Sekcja';
		$sectionName = $section ? $section['name'] : $label . ($reference ? ' — w kierunku projektu' : ' — inspiracje');
		$type = isset(SectionInput::TYPES[$kind]) ? $kind : 'other';
		$sectionId = $section ? (int) $section['id'] : Sections::create($project['id'], ['type' => $type, 'name' => $sectionName, 'requirements' => ['goal' => $prompt]]);

		if ($section) {
			self::failAbandoned($sectionId);
		}
		$blocked = ImageJobs::guard($provider, $sectionId, 4);
		if ($blocked) {
			if ($created) {
				Projects::delete((int) $project['id']);
			} elseif (! $section) {
				Sections::delete($sectionId);
			}
			return $blocked;
		}

		$ds = DesignSystems::latest((int) $project['id']);
		$styleRefs = self::styleReferences($project, $ds);
		$batchId = wp_generate_uuid4();
		$now = current_time('mysql', true);
		foreach (self::round($round, (bool) $reference) as $key => $direction) {
			$layout = $reference ? ($reference['layout'] ?: 'free') : 'free';
			$meta = [
				'batchId' => $batchId,
				'directionKey' => $key,
				'title' => $direction['title'],
				'prompt' => $reference ? self::referencePrompt($prompt, $direction['prompt'], $kind, count($styleRefs)) : self::prompt($prompt, $direction['prompt'], $ds, $kind, count($styleRefs)),
				'sectionKind' => $kind,
				'styleRefs' => $styleRefs,
				'userPrompt' => $prompt,
				'version' => 1,
				'mock' => false,
				'origin' => self::ORIGIN,
				'projectId' => (int) $project['id'],
				'createdBy' => $userId,
				'designSystem' => $ds ? ['id' => $ds['id'], 'version' => $ds['version']] : null,
				'reference' => $reference ? $reference['generationId'] : null,
				'quality' => self::DRAFT_QUALITY,
			];
			// Prośba o odmienność dopisana na końcu — działa też, gdy styl wyznaczają screeny projektu.
			if ($round > 0) {
				$meta['prompt'] .= "\nThis is an additional proposal (round " . ($round + 1) . '): make it clearly different from earlier proposals — fresh imagery, different arrangement and emphasis.';
			}
			self::insert($provider, $sectionId, $key, $direction['title'], $layout, $meta, $now);
		}

		return [
			'project' => ['id' => (int) $project['id'], 'name' => $project['name']],
			'section' => ['id' => $sectionId, 'name' => $sectionName],
			'provider' => $provider->id(),
			'model' => $provider->model(),
			'keepDirection' => (bool) $reference,
			'round' => $round,
			'batch' => ImageJobs::batch($sectionId, $batchId),
		];
	}

	/** Warianty z dashboardu porzucone > 10 min (zamknięta karta) nie blokują kolejnych rund w sekcji. */
	private static function failAbandoned(int $sectionId): void
	{
		global $wpdb;

		$ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::table('generations') . " WHERE section_id = %d AND kind = %s AND status IN ('queued', 'running') AND created_at < %s", $sectionId, ImageJobs::KIND, gmdate('Y-m-d H:i:s', time() - 600)));
		foreach ($ids as $id) {
			ImageJobs::failGeneration((int) $id, new WP_Error('clara_image_abandoned', __('Generowanie przerwane (zamknięto stronę) — wygeneruj ponownie.', 'clara')));
		}
	}

	/** 4 warianty rundy: tytuł „Propozycja N” (numeracja ciągła między rundami) + akcent nastroju / odmiany. */
	public static function round(int $round, bool $reference): array
	{
		$sets = $reference ? self::VARIATIONS : self::MOODS;
		$set = $sets[$round % count($sets)];
		$variants = [];
		foreach (array_values($set) as $i => $hint) {
			$n = $round * 4 + $i + 1;
			$variants[chr(65 + ($round * 4 + $i) % 26)] = [
				'title' => ($reference ? 'W kierunku — propozycja ' : 'Propozycja ') . $n,
				'prompt' => $reference ? $hint : "visual mood: {$hint}",
			];
		}

		return $variants;
	}

	/** Rodzaj sekcji z promptu (klucz SECTION_KINDS) albo 'other' — wtedy decyduje sam brief. */
	public static function sectionKind(string $prompt): string
	{
		$text = mb_strtolower($prompt);
		foreach (self::SECTION_KINDS as $key => $kind) {
			if (preg_match($kind['pattern'], $text)) {
				return $key;
			}
		}

		return 'other';
	}

	private static function kindPrompt(string $kind): string
	{
		return self::SECTION_KINDS[$kind]['prompt'] ?? 'exactly the kind of website section described in the client brief';
	}

	/** Generuje jeden wariant z `start` (idempotentnie: drugi wywołujący dostaje 409 albo gotowy wynik). */
	public static function render(int $generationId, int $userId): array|WP_Error
	{
		global $wpdb;

		$generation = Generations::find($generationId, [ImageJobs::KIND]);
		$meta = $generation ? (json_decode((string) $generation['prompt_json'], true) ?: []) : [];
		$section = $generation ? Sections::find((int) $generation['section_id']) : null;
		$project = $section ? Projects::find($section['project_id']) : null;
		if (! $project || (int) $project['owner_user_id'] !== $userId || ($meta['origin'] ?? '') !== self::ORIGIN) {
			return new WP_Error('clara_not_found', __('Nie znaleziono.', 'clara'), ['status' => 404]);
		}

		// Atomowe przejęcie wariantu — chroni przed podwójnym (płatnym) generowaniem; wariant porzucony
		// przez przerwany proces (running > 10 min) można przejąć ponownie.
		$table = Schema::table('generations');
		$wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'queued' WHERE id = %d AND status = 'running' AND created_at < %s", $generationId, gmdate('Y-m-d H:i:s', time() - 600)));
		$claimed = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'running' WHERE id = %d AND status = 'queued'", $generationId));
		if (! $claimed) {
			$item = self::item($section, $meta['batchId'], $generationId);
			return in_array($item['status'] ?? '', ['succeeded', 'failed'], true) ? $item : new WP_Error('clara_image_busy', __('Ten wariant jest już generowany.', 'clara'), ['status' => 409]);
		}

		$provider = ImageJobs::provider();
		if (! $provider || $provider->isMock() || $provider->id() !== $generation['provider']) {
			$result = new WP_Error('clara_image_provider_changed', __('Dostawca obrazów zmienił się od zlecenia — wygeneruj ponownie.', 'clara'));
		} else {
			$refining = ! empty($meta['refineOf']);
			$images = [];
			if (! empty($meta['reference']) && ($ref = self::referenceBytes((int) $meta['reference'], (int) $project['id'], $refining ? self::REFINE_REFERENCE_WIDTH : self::REFERENCE_WIDTH))) {
				$images[] = $ref;
			}
			// Dopracowanie trzyma się samego szkicu; zwykłe warianty dostają też wzór stylu projektu.
			foreach ($refining ? [] : (array) ($meta['styleRefs'] ?? []) as $attachmentId) {
				if ($ref = self::attachmentBytes((int) $attachmentId, (int) $project['id'], self::STYLE_REFERENCE_WIDTH)) {
					$images[] = $ref;
				}
			}
			$quality = $meta['quality'] ?? null;
			$result = $images && $provider instanceof OpenAiImageProvider
				? $provider->generateWithReference((string) $meta['prompt'], $images, $quality)
				: $provider->generate((string) $meta['prompt'], (json_decode((string) $generation['art_direction_json'], true) ?: []) + ['quality' => $quality]);
		}

		$error = is_wp_error($result) ? $result : ImageJobs::storeResult($generation, $meta, $result);
		if ($error) {
			ImageJobs::failGeneration($generationId, $error);
		}

		return self::item($section, $meta['batchId'], $generationId);
	}

	/**
	 * „Dopracuj”: szkic → ten sam projekt w wyższej jakości. Szkic idzie jako referencja, więc kompozycja
	 * zostaje. Tworzy wariant w kolejce (render jak zwykle); powtórne wywołanie zwraca istniejący.
	 */
	public static function refine(int $generationId, int $userId): array|WP_Error
	{
		global $wpdb;

		$generation = Generations::find($generationId, [ImageJobs::KIND]);
		$meta = $generation ? (json_decode((string) $generation['prompt_json'], true) ?: []) : [];
		$section = $generation ? Sections::find((int) $generation['section_id']) : null;
		$project = $section ? Projects::find($section['project_id']) : null;
		if (! $project || (int) $project['owner_user_id'] !== $userId || ($meta['origin'] ?? '') !== self::ORIGIN) {
			return new WP_Error('clara_not_found', __('Nie znaleziono.', 'clara'), ['status' => 404]);
		}
		if ($generation['status'] !== 'succeeded' || ! $generation['result_asset_id']) {
			return new WP_Error('clara_refine_not_ready', __('Dopracować można tylko gotowy szkic.', 'clara'), ['status' => 409]);
		}
		if (($meta['quality'] ?? '') !== self::DRAFT_QUALITY) {
			return new WP_Error('clara_refine_done', __('Ten wariant jest już w pełnej jakości.', 'clara'), ['status' => 409]);
		}

		$table = Schema::table('generations');
		$existing = $wpdb->get_row($wpdb->prepare("SELECT id, prompt_json FROM {$table} WHERE section_id = %d AND kind = %s AND status IN ('queued', 'running', 'succeeded') AND prompt_json LIKE %s ORDER BY id DESC LIMIT 1", $section['id'], ImageJobs::KIND, '%"refineOf":' . $generationId . ',%'), ARRAY_A);
		if ($existing) {
			return self::item($section, (string) (json_decode($existing['prompt_json'], true)['batchId'] ?? ''), (int) $existing['id']);
		}

		$provider = ImageJobs::provider();
		if (! $provider instanceof OpenAiImageProvider || $provider->id() !== $generation['provider']) {
			return new WP_Error('clara_image_unconfigured', __('Dopracowanie wymaga klucza OpenAI (CLARA_OPENAI_API_KEY w wp-config.php).', 'clara'), ['status' => 409]);
		}
		// Bez blokady „partia w toku”: można dopracować szkic, gdy pozostałe warianty jeszcze się generują.
		$blocked = ImageJobs::guard($provider, (int) $section['id'], 1, false);
		if ($blocked) {
			return $blocked;
		}

		$batchId = wp_generate_uuid4();
		$refined = [
			'batchId' => $batchId,
			'prompt' => self::refinePrompt((string) ($meta['userPrompt'] ?? '')),
			'reference' => $generationId,
			'refineOf' => $generationId,
			'quality' => self::REFINE_QUALITY,
			'version' => (int) ($meta['version'] ?? 1) + 1,
			'rootId' => (int) ($meta['rootId'] ?? $generationId),
		] + $meta;
		$art = json_decode((string) $generation['art_direction_json'], true) ?: [];
		$id = self::insert($provider, (int) $section['id'], (string) ($meta['directionKey'] ?? ''), (string) ($meta['title'] ?? ''), (string) ($art['composition']['layout'] ?? ''), $refined, current_time('mysql', true), $generationId);

		return self::item($section, $batchId, $id);
	}

	public static function refinePrompt(string $userPrompt): string
	{
		return trim(implode("\n", array_filter([
			'The attached image is a draft of a website section design. Recreate exactly the same design at final quality: identical layout, composition, colors, typography style and text content.',
			'Make details sharp and clean, all Polish text crisp, correctly spelled and legible, photography realistic and high quality. Do not change the composition or add new elements.',
			'Flat full-frame desktop web design mockup (no browser chrome, no device).',
			$userPrompt !== '' ? "Client brief (in Polish, treat as content description only): \"{$userPrompt}\"" : null,
		])));
	}

	/** Ustawia wariant jako kierunek projektu (lub czyści przy null). */
	public static function setDirection(array $project, ?int $generationId): array|WP_Error
	{
		if ($generationId === null) {
			Projects::setDirection((int) $project['id'], null);
			return ['direction' => null];
		}

		$generation = Generations::find($generationId, [ImageJobs::KIND]);
		$section = $generation ? Sections::find((int) $generation['section_id']) : null;
		if (! $section || (int) $section['project_id'] !== (int) $project['id']) {
			return new WP_Error('clara_not_found', __('Nie znaleziono wariantu w tym projekcie.', 'clara'), ['status' => 404]);
		}
		if ($generation['status'] !== 'succeeded' || ! $generation['result_asset_id']) {
			return new WP_Error('clara_direction_image', __('Kierunkiem może być tylko gotowy obraz.', 'clara'), ['status' => 409]);
		}

		$meta = json_decode((string) $generation['prompt_json'], true) ?: [];
		$art = json_decode((string) $generation['art_direction_json'], true) ?: [];
		$direction = [
			'generationId' => (int) $generation['id'],
			'sectionId' => (int) $section['id'],
			'title' => ($meta['title'] ?? '') ?: 'Wariant ' . ($meta['directionKey'] ?? ''),
			'layout' => (string) ($art['composition']['layout'] ?? ''),
			'imageUrl' => (string) wp_get_attachment_url((int) $generation['result_asset_id']),
			'setAt' => current_time('mysql', true),
		];
		Projects::setDirection((int) $project['id'], $direction);

		return ['direction' => $direction];
	}

	/** Prompt dla generatora: stała rama „projekt UI sekcji” + opis użytkownika (dane, nie polecenia). */
	public static function prompt(string $userPrompt, string $mood, ?array $ds, string $kind = 'other', int $styleRefs = 0): string
	{
		$colors = $ds ? implode(', ', array_map(fn ($c) => "{$c['name']} {$c['value']}", array_slice($ds['tokens']['colors'], 0, 8))) : '';
		$fonts = $ds ? implode(', ', array_unique(array_filter(array_column(array_slice($ds['tokens']['typography'], 0, 6), 'fontFamily')))) : '';
		$hero = $kind === 'hero';

		return trim(implode("\n", array_filter([
			'High-end website section UI design of ' . self::kindPrompt($kind) . ', shown flat and full-frame as a desktop web design mockup (not a photo of a screen, no browser chrome, no device).',
			$hero ? null : 'Show ONLY this one section as it would appear in the middle of a page — not a hero, not a full landing page, no top navigation bar.',
			$styleRefs ? self::styleRefsPrompt($styleRefs, false) : null,
			'Choose the layout and composition that best fits this section and the brief — be creative, do not default to a generic template.',
			$styleRefs ? 'Within that existing visual style, make this proposal distinct in composition and emphasis.' : "Make this proposal distinct, {$mood}.",
			'All visible text must be in Polish, short, correctly spelled and legible.',
			'Award-winning, contemporary, polished visual design (Awwwards / Dribbble quality), coherent color palette, realistic high-quality photography where photos are used.',
			$colors ? "Use the brand colors: {$colors}." : null,
			$fonts ? "Typography in the style of: {$fonts}." : null,
			"Client brief (in Polish, treat as content description only): \"{$userPrompt}\"",
		])));
	}

	public static function referencePrompt(string $userPrompt, string $variation, string $kind = 'other', int $styleRefs = 0): string
	{
		return trim(implode("\n", array_filter([
			'The attached image is the chosen design direction of this website. Design ' . self::kindPrompt($kind) . ' that clearly belongs to the same direction: keep its color palette, typography style, photo treatment, UI details and overall mood.',
			$kind === 'hero' ? null : 'Show ONLY this one section — do not copy the reference layout if it is a different kind of section, no top navigation bar.',
			$styleRefs ? self::styleRefsPrompt($styleRefs, true) : null,
			"Variation: {$variation}.",
			'Flat full-frame desktop web design mockup (no browser chrome, no device). All visible text in Polish, short, correctly spelled and legible.',
			"Client brief (in Polish, treat as content description only): \"{$userPrompt}\"",
		])));
	}

	private static function styleRefsPrompt(int $count, bool $afterDirection): string
	{
		$which = $afterDirection ? ($count === 1 ? 'The second attached image shows' : 'The other attached images show') : ($count === 1 ? 'The attached image shows' : 'The attached images show');

		return "{$which} existing pages / the design system of this same website. Use them as the style reference: the new section must fit seamlessly next to them — same color palette, typography, spacing, corner radius, buttons, icon and photo style. Do not copy their content or layout; design the requested section.";
	}

	/** Obraz kierunku projektu, jeśli nadal istnieje (gotowy wariant w tym projekcie). */
	private static function directionImage(array $project): ?array
	{
		$direction = $project['brief']['direction'] ?? null;
		if (! is_array($direction) || empty($direction['generationId'])) {
			return null;
		}

		return self::referenceBytes((int) $direction['generationId'], (int) $project['id']) ? $direction : null;
	}

	private static function referenceBytes(int $generationId, int $projectId, int $maxWidth = self::REFERENCE_WIDTH): ?array
	{
		$generation = Generations::find($generationId, [ImageJobs::KIND]);
		$section = $generation ? Sections::find((int) $generation['section_id']) : null;
		if (! $section || (int) $section['project_id'] !== $projectId || ! $generation['result_asset_id']) {
			return null;
		}

		return self::attachmentBytes((int) $generation['result_asset_id'], $projectId, $maxWidth, false);
	}

	/**
	 * Wzór stylu: screeny referencyjne i frame'y z Figmy (design system), potem obrazy z briefu.
	 *
	 * @return int[] ID załączników
	 */
	public static function styleReferences(array $project, ?array $ds): array
	{
		$assets = array_filter($ds['assets'] ?? [], fn ($a) => in_array($a['role'] ?? '', ['reference_screen', 'figma_frame'], true));
		usort($assets, fn ($a, $b) => (($a['role'] ?? '') === 'reference_screen' ? 0 : 1) <=> (($b['role'] ?? '') === 'reference_screen' ? 0 : 1));
		$brief = array_filter($project['brief']['attachments'] ?? [], fn ($a) => str_starts_with((string) ($a['mime'] ?? ''), 'image/'));
		$ids = array_map(fn ($a) => (int) ($a['id'] ?? 0), [...$assets, ...$brief]);
		$ids = array_filter(array_unique($ids), fn ($id) => $id > 0 && self::attachmentBytes($id, (int) $project['id'], 1, true, true) !== null);

		return array_slice(array_values($ids), 0, self::STYLE_REFS);
	}

	/**
	 * Obraz załącznika jako pomniejszony JPEG (tylko do wysyłki — oryginał w mediach zostaje bez zmian).
	 * $checkProject: załącznik musi należeć do projektu (meta _clara_project_id); $probe: tylko sprawdzenie.
	 */
	private static function attachmentBytes(int $attachmentId, int $projectId, int $maxWidth, bool $checkProject = true, bool $probe = false): ?array
	{
		if ($checkProject && (int) get_post_meta($attachmentId, Attachments::META, true) !== $projectId) {
			return null;
		}
		$path = (string) get_attached_file($attachmentId);
		$size = $path && is_readable($path) ? @getimagesize($path) : false;
		if (! $size || ! in_array($size['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
			return null;
		}
		if ($probe) {
			return [];
		}

		if ($size[0] > $maxWidth || $size['mime'] === 'image/webp') {
			$editor = wp_get_image_editor($path);
			if (! is_wp_error($editor) && ($size[0] <= $maxWidth || ! is_wp_error($editor->resize($maxWidth, null)))) {
				$editor->set_quality(82);
				// wp_tempnam() jest w wp-admin — w żądaniach REST nie jest ładowane.
				require_once ABSPATH . 'wp-admin/includes/file.php';
				$tmp = wp_tempnam('clara-ref');
				$saved = $editor->save($tmp . '.jpg', 'image/jpeg');
				@unlink($tmp);
				if (! is_wp_error($saved) && is_readable($saved['path'])) {
					$body = (string) file_get_contents($saved['path']);
					@unlink($saved['path']);
					return ['body' => $body, 'mime' => 'image/jpeg'];
				}
			}
		}
		if ($size['mime'] === 'image/webp') {
			return null;
		}

		return ['body' => (string) file_get_contents($path), 'mime' => $size['mime']];
	}

	private static function item(array $section, string $batchId, int $generationId): array
	{
		$batch = ImageJobs::batch((int) $section['id'], $batchId);
		foreach (is_wp_error($batch) ? [] : $batch['items'] as $item) {
			if ($item['id'] === $generationId) {
				return $item;
			}
		}

		return ['id' => $generationId, 'status' => 'failed'];
	}

	private static function projectName(string $prompt): string
	{
		$name = trim(preg_replace('/\s+/u', ' ', $prompt));

		return mb_strlen($name) > 60 ? rtrim(mb_substr($name, 0, 57)) . '…' : mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
	}

	private static function insert(ImageProvider $provider, int $sectionId, string $key, string $title, string $layout, array $meta, string $now, int $parentId = 0): int
	{
		global $wpdb;

		$wpdb->insert(Schema::table('generations'), [
			'section_id' => $sectionId,
			'parent_id' => $parentId,
			'kind' => ImageJobs::KIND,
			'art_direction_json' => wp_json_encode(['key' => $key, 'title' => $title, 'composition' => ['layout' => $layout]]),
			'prompt_json' => wp_json_encode($meta),
			'model_id' => $provider->model(),
			'provider' => $provider->id(),
			'status' => 'queued',
			'estimated_cost' => $provider instanceof OpenAiImageProvider ? $provider->costFor($meta['quality'] ?? null) : $provider->estimateCost(),
			'created_at' => $now,
		]);

		return (int) $wpdb->insert_id;
	}

	private static function allow(int $userId): bool
	{
		$key = 'clara_inspire_rl_' . $userId;
		$hits = array_filter((array) get_transient($key), fn ($t) => $t > time() - HOUR_IN_SECONDS);
		if (count($hits) >= (int) apply_filters('clara/inspire_rate_limit', self::RATE_LIMIT)) {
			return false;
		}
		$hits[] = time();
		set_transient($key, array_values($hits), HOUR_IN_SECONDS);

		return true;
	}
}
