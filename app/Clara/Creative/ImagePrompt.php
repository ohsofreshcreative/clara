<?php

namespace App\Clara\Creative;

/**
 * Prompt obrazu z kierunku artystycznego. Obraz nie zawiera tekstu — nagłówki i CTA pozostają
 * tekstem w kolejnych etapach (Layout JSON, Figma).
 */
class ImagePrompt
{
	private const LAYOUT_HINTS = [
		'split' => 'two-column composition: empty calm area on the left for text, main visual on the right',
		'full-bleed' => 'full-bleed edge-to-edge photograph with a calm central area for overlaid text',
		'centered-type' => 'minimal background with large empty space in the upper center for typography, small visual detail below',
		'asymmetric-collage' => 'asymmetric collage of 3-4 frames of different sizes, empty area on the left for text',
		'editorial-grid' => 'editorial magazine grid with image blocks and empty text blocks',
		'framed-card' => 'background photograph with an empty card area on the left for text',
	];

	public static function build(array $direction, array $interpretation): string
	{
		$c = $direction['composition'];
		$palette = implode(', ', array_map(fn ($p) => "{$p['name']} {$p['value']}", $direction['palette']));

		$prompt = implode("\n", array_filter([
			'Website hero section visual, 16:9, high quality, original artwork (do not imitate existing brands or websites).',
			'Layout: ' . (self::LAYOUT_HINTS[$c['layout']] ?? $c['layout']) . ". Focal point: {$c['focalPoint']}. Image treatment: {$c['imageTreatment']}.",
			"Composition notes: {$c['description']}",
			"Concept: {$direction['concept']}",
			"Imagery: {$direction['imagery']}",
			$direction['mood'] ? 'Mood: ' . implode(', ', $direction['mood']) . '.' : '',
			"Color palette: {$palette}.",
			$interpretation['summary'] ? "Context: {$interpretation['summary']}" : '',
			'Do NOT render any text, letters, logos or UI labels in the image — leave clean space where text will be placed.',
		]));

		return mb_substr($prompt, 0, 4000);
	}

	/**
	 * Prompt remiksu: prompt rodzica + instrukcja zmian + opis referencji (tylko nastrój i kierunek,
	 * bez kopiowania cudzych projektów). Zakaz tekstu w obrazie zawsze na końcu.
	 */
	public static function remix(string $parentPrompt, string $instruction, array $references): string
	{
		$noText = 'Do NOT render any text, letters, logos or UI labels in the image — leave clean space where text will be placed.';
		$refs = array_map(fn ($r) => '- ' . trim(($r['title'] ?: 'untitled') . ($r['creator'] ? " by {$r['creator']}" : '') . ' (' . (wp_parse_url($r['sourceUrl'], PHP_URL_HOST) ?: 'source') . ')'), $references);

		$tail = implode("\n", array_filter([
			"Remix instruction (keep the overall composition unless the instruction says otherwise): {$instruction}",
			$refs ? "Mood references from the project collection — take only general mood, color and lighting cues; do NOT copy their composition, subjects or any recognizable design:\n" . implode("\n", $refs) : '',
			$noText,
		]));

		$base = trim(str_replace($noText, '', $parentPrompt));
		$base = mb_substr($base, 0, max(0, 4000 - mb_strlen($tail) - 1));

		return mb_substr($base . "\n" . $tail, 0, 4000);
	}
}
