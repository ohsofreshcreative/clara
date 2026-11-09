<?php

namespace App\Clara\Layout;

use App\Clara\Creative\AnthropicProvider;
use App\Clara\Creative\Generations;
use App\Clara\DesignSystems\DesignSystems;
use WP_Error;

/**
 * Obraz wariantu → wersjonowany Layout JSON. Analizator wybierany filtrem `clara/layout_analyzer`
 * (domyślnie model wizyjny, gdy serwer ma klucz; inaczej heurystyka). Wynik zawsze przechodzi
 * walidację schematu z wymaganymi treściami; przy błędzie analizatora lub walidacji zapisujemy
 * deterministyczną rekonstrukcję z jawnym powodem (fallbackReason).
 */
class LayoutInterpreter
{
	public static function analyzer(): LayoutAnalyzer
	{
		$default = AnthropicProvider::apiKey() !== '' ? new VisionLayoutAnalyzer() : new HeuristicLayoutAnalyzer();
		$analyzer = apply_filters('clara/layout_analyzer', $default);

		return $analyzer instanceof LayoutAnalyzer ? $analyzer : new HeuristicLayoutAnalyzer();
	}

	public static function analyze(array $generation): array|WP_Error
	{
		$input = self::input($generation);
		if (is_wp_error($input)) {
			return $input;
		}

		$options = ['requiredTexts' => $input['requiredTexts'], 'assets' => [(int) $generation['id']]];
		$analyzer = self::analyzer();
		$result = $analyzer->analyze($input);
		$fallbackReason = null;
		$attempt = ['analyzer' => $analyzer->id(), 'model' => $analyzer->model(), 'cost' => null];

		if (! is_wp_error($result)) {
			$attempt['cost'] = $result['cost'] ?? null;
			$check = LayoutSchema::validate($result['layout'], $options);
			if ($check['errors']) {
				$fallbackReason = ['code' => 'clara_layout_invalid', 'message' => 'Wynik analizatora nie przeszedł walidacji schematu.', 'errors' => array_slice($check['errors'], 0, 20, true)];
			}
		} else {
			$fallbackReason = ['code' => $result->get_error_code(), 'message' => $result->get_error_message()];
		}

		if ($fallbackReason && ! $analyzer instanceof HeuristicLayoutAnalyzer) {
			$analyzer = new HeuristicLayoutAnalyzer();
			$result = $analyzer->analyze($input);
			if (is_wp_error($result)) {
				return $result;
			}
		} elseif ($fallbackReason) {
			return new WP_Error($fallbackReason['code'], $fallbackReason['message'], ['status' => 422, 'errors' => $fallbackReason['errors'] ?? []]);
		}

		$layout = $result['layout'];
		$layout['source'] = ['generationId' => (int) $generation['id'], 'assetId' => (int) $generation['result_asset_id'], 'analyzer' => $analyzer->id(), 'model' => $analyzer->model()];
		$check = LayoutSchema::validate($layout, $options);

		if ($check['errors']) {
			return new WP_Error('clara_layout_invalid', __('Nie udało się zbudować poprawnego layoutu.', 'clara'), ['status' => 500, 'errors' => $check['errors']]);
		}

		return LayoutDocuments::create((int) $generation['id'], $layout, [
			'valid' => true,
			'origin' => 'analysis',
			'analyzer' => $analyzer->id(),
			'model' => $analyzer->model(),
			'cost' => $result['cost'] ?? null,
			'usage' => $result['usage'] ?? null,
			'fallbackReason' => $fallbackReason ? $fallbackReason + ['attempt' => $attempt] : null,
			'warnings' => $check['warnings'],
			'summary' => $check['summary'],
			'requiredTexts' => $input['requiredTexts'],
			'createdBy' => get_current_user_id(),
		]);
	}

	/**
	 * Ręcznie poprawiony layout zapisany jako nowa wersja (po walidacji).
	 */
	public static function saveManual(array $generation, mixed $layout, ?int $basedOn): array|WP_Error
	{
		if (is_string($layout)) {
			$layout = LayoutSchema::parse($layout);
		}
		if (is_wp_error($layout)) {
			return $layout;
		}
		if (! is_array($layout)) {
			return LayoutSchema::invalid(['layout' => 'Oczekiwano obiektu layoutu.']);
		}

		$layout = LayoutSchema::migrate($layout);
		if (is_wp_error($layout)) {
			return $layout;
		}

		$input = self::input($generation);
		$required = is_wp_error($input) ? [] : $input['requiredTexts'];
		$check = LayoutSchema::validate($layout, ['requiredTexts' => $required, 'assets' => [(int) $generation['id']]]);
		if ($check['errors']) {
			return LayoutSchema::invalid($check['errors']);
		}

		$layout['source'] = ['generationId' => (int) $generation['id'], 'assetId' => (int) $generation['result_asset_id'], 'analyzer' => 'manual', 'model' => ''];

		return LayoutDocuments::create((int) $generation['id'], $layout, [
			'valid' => true,
			'origin' => 'manual',
			'basedOn' => $basedOn,
			'analyzer' => 'manual',
			'warnings' => $check['warnings'],
			'summary' => $check['summary'],
			'requiredTexts' => $required,
			'createdBy' => get_current_user_id(),
		]);
	}

	/** Rodzaje generacji, które mogą być zasobem obrazu w layoucie. */
	public const ASSET_KINDS = ['image'];

	/**
	 * Mapa zasobów obrazów użytych w layoucie (do podglądu i eksportu). Zasób musi należeć do tej
	 * samej sekcji co dokument — layout nie może wskazać cudzego obrazu.
	 */
	public static function assets(array $generation, ?array $layout = null): array
	{
		$ids = [(int) $generation['id']];
		foreach ($layout['nodes'] ?? [] as $node) {
			if (isset($node['assetRef']['generationId'])) {
				$ids[] = (int) $node['assetRef']['generationId'];
			}
		}

		$assets = [];
		foreach (array_unique($ids) as $id) {
			$source = $id === (int) $generation['id'] ? $generation : Generations::find($id, self::ASSET_KINDS);
			$assetId = $source && (int) $source['section_id'] === (int) $generation['section_id'] ? (int) $source['result_asset_id'] : 0;
			$url = $assetId ? wp_get_attachment_url($assetId) : false;
			if ($url) {
				$size = @getimagesize((string) get_attached_file($assetId));
				$assets[(string) $id] = ['url' => $url, 'width' => $size[0] ?? null, 'height' => $size[1] ?? null];
			}
		}

		return $assets;
	}

	public static function input(array $generation): array|WP_Error
	{
		if ($generation['status'] !== 'succeeded' || ! $generation['result_asset_id']) {
			return new WP_Error('clara_layout_source', __('Layout można zbudować tylko z gotowego obrazu.', 'clara'), ['status' => 409]);
		}

		$path = (string) get_attached_file((int) $generation['result_asset_id']);
		$size = $path && is_readable($path) ? @getimagesize($path) : false;
		if (! $size) {
			return new WP_Error('clara_layout_image', __('Plik obrazu wariantu jest niedostępny.', 'clara'), ['status' => 409]);
		}

		$direction = json_decode((string) $generation['art_direction_json'], true) ?: [];
		$meta = json_decode((string) $generation['prompt_json'], true) ?: [];
		$copy = $direction['copy'] ?? [];
		$required = array_values(array_filter(array_map(fn ($r) => trim((string) ($r['item'] ?? '')), $direction['requiredContent'] ?? [])));

		$texts = [];
		$seen = [];
		$add = function (string $role, string $content) use (&$texts, &$seen) {
			$content = trim($content);
			$key = mb_strtolower($content);
			if ($content !== '' && ! isset($seen[$key])) {
				$seen[$key] = true;
				$texts[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
			}
		};
		$add('headline', (string) ($copy['headline'] ?? ''));
		$add('subheadline', (string) ($copy['subheadline'] ?? ''));
		foreach ($required as $item) {
			$add('body', $item);
		}
		$add('cta', (string) ($copy['cta'] ?? ''));

		return [
			'generationId' => (int) $generation['id'],
			'imagePath' => $path,
			'imageWidth' => $size[0],
			'imageHeight' => $size[1],
			'mime' => $size['mime'] ?? '',
			'mock' => (bool) ($meta['mock'] ?? false),
			'direction' => $direction,
			'texts' => $texts,
			'requiredTexts' => $required,
			'palette' => $direction['palette'] ?? [],
			'fonts' => self::fonts($meta['designSystem']['id'] ?? null),
		];
	}

	/** Kroje z design systemu (nagłówek / treść); bez DS — domyślny Inter z ostrzeżeniem w UI. */
	private static function fonts(?int $designSystemId): array
	{
		$ds = $designSystemId ? DesignSystems::find((int) $designSystemId) : null;
		$typography = $ds['tokens']['typography'] ?? [];
		if (! $typography) {
			return [];
		}

		$pick = function (string $pattern) use ($typography) {
			foreach ($typography as $t) {
				if (preg_match($pattern, (string) ($t['name'] ?? ''))) {
					return (string) $t['fontFamily'];
				}
			}
			return null;
		};
		$valid = fn ($f) => is_string($f) && preg_match('/^[\p{L}\p{N} ._\'-]{1,80}$/u', $f) ? $f : null;

		return array_filter([
			'heading' => $valid($pick('/h1|head|nag|display|title/i') ?? $typography[0]['fontFamily'] ?? null),
			'body' => $valid($pick('/body|text|tekst|paragraph|treść/i') ?? end($typography)['fontFamily'] ?? null),
		]);
	}
}
