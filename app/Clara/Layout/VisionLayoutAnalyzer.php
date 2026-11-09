<?php

namespace App\Clara\Layout;

use App\Clara\Creative\AnthropicProvider;
use WP_Error;

/**
 * Analiza obrazu modelem wizyjnym (Claude Messages API, structured output). Model wskazuje regiony
 * (pozycje w ułamkach płótna, rola, rodzaj warstwy, pewność); treści tekstów NIE pochodzą od modelu —
 * wskazuje on tylko indeks z listy dokładnych tekstów sekcji, więc tekst zawsze pozostaje tekstem
 * i nie jest „odczytywany” z bitmapy. Wynik jest konwertowany do Layout JSON v1 i walidowany.
 */
class VisionLayoutAnalyzer implements LayoutAnalyzer
{
	private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

	private const KINDS = ['text', 'button', 'image', 'rectangle'];

	private const ROLES = ['background', 'overlay', 'visual', 'headline', 'subheadline', 'body', 'cta', 'decoration'];

	public function __construct(private AnthropicProvider $llm = new AnthropicProvider())
	{
	}

	public function id(): string
	{
		return 'anthropic-vision';
	}

	public function model(): string
	{
		return $this->llm->model();
	}

	public function analyze(array $input): array|WP_Error
	{
		$path = (string) ($input['imagePath'] ?? '');
		if (! is_readable($path) || filesize($path) > self::MAX_IMAGE_BYTES || ! in_array($input['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)) {
			return new WP_Error('clara_layout_image', __('Obrazu nie można przekazać do analizy (brak pliku, format lub rozmiar > 5 MB).', 'clara'));
		}

		$texts = array_values($input['texts']);
		$list = implode("\n", array_map(fn ($t, $i) => "{$i}. [{$t['role']}] " . $t['content'], $texts, array_keys($texts)));
		$system = 'You reconstruct website hero visuals as editable design layers. Identify regions in the image: '
			. 'photographic or complex areas become "image" regions (they stay bitmaps), flat shapes become "rectangle", '
			. 'and places where text or buttons belong become "text"/"button" regions. Never transcribe text from the image — '
			. 'assign each provided text by its index (textIndex), every provided text exactly once; use -1 for non-text regions. '
			. 'Coordinates are fractions 0–1 of the image width/height. Give honest confidence 0–1. Do not invent extra texts.';
		$user = "Layout archetype: " . ($input['direction']['composition']['layout'] ?? 'unknown') . "\nTexts:\n{$list}";

		$result = $this->llm->completeWithImage($system, $user, self::schema(), (string) $input['mime'], base64_encode((string) file_get_contents($path)));
		if (is_wp_error($result)) {
			return $result;
		}

		$layout = self::toLayout($result['data'], $input);

		return is_wp_error($layout) ? $layout : ['layout' => $layout, 'cost' => $result['cost'], 'usage' => $result['usage']];
	}

	public static function schema(): array
	{
		$num = ['type' => 'number'];

		return [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => ['background', 'regions', 'warnings'],
			'properties' => [
				'background' => ['type' => 'string', 'description' => 'Dominant background color #rrggbb'],
				'regions' => ['type' => 'array', 'items' => [
					'type' => 'object',
					'additionalProperties' => false,
					'required' => ['kind', 'role', 'textIndex', 'x', 'y', 'width', 'height', 'color', 'confidence', 'note'],
					'properties' => [
						'kind' => ['type' => 'string', 'enum' => self::KINDS],
						'role' => ['type' => 'string', 'enum' => self::ROLES],
						'textIndex' => ['type' => 'integer'],
						'x' => $num, 'y' => $num, 'width' => $num, 'height' => $num,
						'color' => ['type' => 'string', 'description' => 'Text color for text/button, fill for rectangle/button, #rrggbb'],
						'confidence' => $num,
						'note' => ['type' => 'string', 'description' => 'For image regions: why it stays a bitmap'],
					],
				]],
				'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
			],
		];
	}

	/**
	 * Odpowiedź modelu → Layout JSON v1. Teksty wyłącznie z listy wejściowej (po indeksie).
	 */
	public static function toLayout(array $data, array $input): array|WP_Error
	{
		$iw = (int) $input['imageWidth'];
		$ih = (int) $input['imageHeight'];
		$W = 1440;
		$H = (int) round($W * $ih / max(1, $iw));
		$hex = fn ($c, $fallback) => is_string($c) && preg_match('/^#[0-9a-f]{6}$/i', $c) ? strtolower($c) : $fallback;
		$clamp = fn ($v) => max(0.0, min(1.0, (float) $v));
		$texts = array_values($input['texts']);
		$fonts = $input['fonts'] ?? [];
		$bg = $hex($data['background'] ?? null, '#0b1b3a');

		$nodes = [[
			'id' => 'hero', 'type' => 'frame', 'role' => 'root', 'name' => 'Hero', 'parentId' => null, 'order' => 0,
			'frame' => ['x' => 0, 'y' => 0, 'width' => $W, 'height' => $H], 'style' => ['fill' => $bg], 'layoutMode' => 'none', 'confidence' => 0.9, 'rasterized' => false,
		]];
		$regions = [];
		$used = [];

		foreach (array_slice((array) ($data['regions'] ?? []), 0, 60) as $i => $r) {
			$kind = in_array($r['kind'] ?? '', self::KINDS, true) ? $r['kind'] : null;
			if (! $kind) {
				continue;
			}
			$x = $clamp($r['x'] ?? 0);
			$y = $clamp($r['y'] ?? 0);
			$w = min($clamp($r['width'] ?? 0), 1 - $x);
			$h = min($clamp($r['height'] ?? 0), 1 - $y);
			if ($w <= 0.005 || $h <= 0.005) {
				continue;
			}
			$frame = ['x' => round($x * $W), 'y' => round($y * $H), 'width' => max(1, round($w * $W)), 'height' => max(1, round($h * $H))];
			$role = in_array($r['role'] ?? '', self::ROLES, true) ? $r['role'] : 'decoration';
			$node = ['id' => "{$kind}-{$i}", 'type' => $kind, 'role' => $role, 'name' => ucfirst($role), 'parentId' => 'hero', 'order' => $i + 1, 'frame' => $frame, 'style' => [], 'layoutMode' => 'none', 'confidence' => round($clamp($r['confidence'] ?? 0.5), 2), 'rasterized' => false];

			if ($kind === 'text' || $kind === 'button') {
				$index = $r['textIndex'] ?? -1;
				if (! is_int($index) || ! isset($texts[$index]) || isset($used[$index])) {
					return new WP_Error('clara_layout_texts', __('Model przypisał teksty niezgodnie z listą treści sekcji.', 'clara'));
				}
				$used[$index] = true;
				$size = max(12, min(120, (int) round($frame['height'] * ($kind === 'button' ? 0.4 : 0.7))));
				$node['content'] = $texts[$index]['content'];
				$node['style'] = ['color' => $hex($r['color'] ?? null, '#ffffff'), 'fontFamily' => ($texts[$index]['role'] === 'headline' ? $fonts['heading'] ?? null : $fonts['body'] ?? null) ?: 'Inter', 'fontSize' => $size, 'fontWeight' => $texts[$index]['role'] === 'headline' ? 700 : 400, 'lineHeight' => 1.2];
				if ($kind === 'button') {
					$node['style']['fill'] = $node['style']['color'] === '#ffffff' ? ($input['palette'][2]['value'] ?? '#2f6bff') : '#ffffff';
					$node['style']['radius'] = 999;
					$node['style']['textAlign'] = 'center';
				}
			} elseif ($kind === 'image') {
				$node['assetRef'] = ['generationId' => (int) $input['generationId'], 'crop' => ['x' => (int) round($x * $iw), 'y' => (int) round($y * $ih), 'width' => max(1, (int) round($w * $iw)), 'height' => max(1, (int) round($h * $ih))]];
				$node['rasterized'] = true;
				$regions[] = ['nodeId' => $node['id'], 'frame' => $frame, 'reason' => mb_substr(sanitize_text_field((string) ($r['note'] ?? '')) ?: 'Złożony fragment obrazu — pozostaje bitmapą.', 0, 300)];
			} else {
				$node['style'] = ['fill' => $hex($r['color'] ?? null, $bg)];
			}
			$nodes[] = $node;
		}

		if (count($used) !== count($texts)) {
			return new WP_Error('clara_layout_texts', __('Model nie umieścił wszystkich treści sekcji jako tekstu.', 'clara'));
		}

		$warnings = array_map(fn ($w) => mb_substr(sanitize_text_field((string) $w), 0, 300), array_slice((array) ($data['warnings'] ?? []), 0, 20));
		$warnings[] = 'Układ odtworzony przez model wizyjny — best-effort, nie pixel-perfect.';

		return [
			'schemaVersion' => LayoutSchema::VERSION,
			'canvas' => ['width' => $W, 'height' => $H, 'background' => $bg],
			'nodes' => $nodes,
			'tokens' => ['colors' => array_map(fn ($p) => ['name' => (string) $p['name'], 'value' => strtolower((string) $p['value'])], array_slice($input['palette'] ?? [], 0, 50))],
			'rasterizedRegions' => $regions,
			'warnings' => $warnings,
		];
	}
}
