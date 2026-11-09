<?php

namespace App\Clara\Layout;

use WP_Error;

/**
 * Deterministyczna rekonstrukcja: układ z kompozycji kierunku artystycznego + próbkowanie obrazu (GD)
 * dla koloru tła i kontrastu tekstu. Nie „widzi” obiektów na obrazie — fragmenty wizualne zostają
 * bitmapami (kadry obrazu źródłowego), a cała treść jest tekstem. Pozycje są przybliżone, co
 * odzwierciedla `confidence`.
 */
class HeuristicLayoutAnalyzer implements LayoutAnalyzer
{
	public const MODEL = 'heuristic-v1';

	private const WIDTH = 1440;

	public function id(): string
	{
		return 'heuristic';
	}

	public function model(): string
	{
		return self::MODEL;
	}

	public function analyze(array $input): array|WP_Error
	{
		$iw = (int) $input['imageWidth'];
		$ih = (int) $input['imageHeight'];
		if ($iw < 1 || $ih < 1) {
			return new WP_Error('clara_layout_image', __('Brak wymiarów obrazu źródłowego.', 'clara'));
		}

		$W = self::WIDTH;
		$H = (int) round($W * $ih / $iw);
		$gen = (int) $input['generationId'];
		$layout = $input['direction']['composition']['layout'] ?? 'split';
		$align = $input['direction']['composition']['textAlign'] ?? 'left';
		$im = self::load($input['imagePath'] ?? '');

		// Strefy w ułamkach płótna: [x1, y1, x2, y2] — tekst, kadr wizualny (bitmapa), nakładka.
		[$text, $visual, $overlay, $fullBleed] = match ($layout) {
			'full-bleed' => [[.28, .3, .72, .72], [0, 0, 1, 1], [.25, .25, .75, .75], true],
			'centered-type' => [[.15, .1, .85, .52], [.4, .56, .6, .9], null, false],
			'asymmetric-collage' => [[.05, .18, .39, .78], [.42, 0, 1, 1], null, false],
			'editorial-grid' => [[.36, .08, .96, .6], [.04, .08, .32, .92], null, false],
			'framed-card' => [[.08, .22, .44, .78], [0, 0, 1, 1], [.06, .18, .46, .82], true],
			default => [[.06, .22, .46, .8], [.52, 0, 1, 1], null, false],
		};
		if ($layout === 'centered-type' || $layout === 'full-bleed') {
			$align = 'center';
		}

		$px = fn (array $z) => ['x' => round($z[0] * $W), 'y' => round($z[1] * $H), 'width' => round(($z[2] - $z[0]) * $W), 'height' => round(($z[3] - $z[1]) * $H)];
		$crop = fn (array $z) => ['x' => (int) round($z[0] * $iw), 'y' => (int) round($z[1] * $ih), 'width' => (int) round(($z[2] - $z[0]) * $iw), 'height' => (int) round(($z[3] - $z[1]) * $ih)];

		// Tło płótna: pas nad strefą tekstu (bez samych bloków treści); przy pełnym kadrze — cały obraz.
		$fallbackBg = $input['palette'][0]['value'] ?? '#0b1b3a';
		$bg = ! $im ? $fallbackBg : ($fullBleed ? self::average($im, 0, 0, 1, 1) : self::average($im, $text[0], 0, $text[2], min(0.06, $text[1])));
		// Kolor pod tekstem: nakładka albo jednolite tło płótna.
		$overlayFill = $fallbackBg;
		$under = $overlay ? $overlayFill : $bg;
		$color = self::readable($under, array_column($input['palette'] ?? [], 'value'));
		$accent = $input['palette'][2]['value'] ?? $input['palette'][1]['value'] ?? '#2f6bff';
		$accentText = self::readable($accent, ['#ffffff', '#0b1b3a']);

		$nodes = [[
			'id' => 'hero', 'type' => 'frame', 'role' => 'root', 'name' => 'Hero', 'parentId' => null, 'order' => 0,
			'frame' => ['x' => 0, 'y' => 0, 'width' => $W, 'height' => $H],
			'style' => ['fill' => $bg], 'layoutMode' => 'none', 'confidence' => 0.9, 'rasterized' => false,
		]];
		$regions = [];
		$order = 0;

		$nodes[] = [
			'id' => 'visual', 'type' => 'image', 'role' => $fullBleed ? 'background' : 'visual', 'name' => $fullBleed ? 'Tło — obraz' : 'Kadr wizualny',
			'parentId' => 'hero', 'order' => ++$order, 'frame' => $px($visual), 'style' => [],
			'assetRef' => ['generationId' => $gen, 'crop' => $crop($visual)], 'layoutMode' => 'none', 'confidence' => 0.7, 'rasterized' => true,
		];
		$regions[] = ['nodeId' => 'visual', 'frame' => $px($visual), 'reason' => 'Fotografia / złożona grafika wygenerowana przez AI — pozostaje bitmapą (kadr obrazu źródłowego).'];

		if ($overlay) {
			$nodes[] = [
				'id' => 'overlay', 'type' => 'rectangle', 'role' => 'overlay', 'name' => 'Nakładka pod tekst', 'parentId' => 'hero', 'order' => ++$order,
				'frame' => $px($overlay), 'style' => ['fill' => $overlayFill, 'opacity' => 0.78, 'radius' => $layout === 'framed-card' ? 24 : 0],
				'layoutMode' => 'none', 'confidence' => 0.5, 'rasterized' => false,
			];
		}

		$column = $px($text);
		$nodes[] = [
			'id' => 'content', 'type' => 'frame', 'role' => 'container', 'name' => 'Treść', 'parentId' => 'hero', 'order' => ++$order,
			'frame' => $column, 'style' => ['gap' => 24], 'layoutMode' => 'vertical', 'confidence' => 0.6, 'rasterized' => false,
		];

		$fonts = $input['fonts'] ?? [];
		$sizes = ['headline' => [64, 700, 1.1, $fonts['heading'] ?? 'Inter'], 'subheadline' => [24, 400, 1.4, $fonts['body'] ?? 'Inter'], 'body' => [18, 400, 1.5, $fonts['body'] ?? 'Inter'], 'cta' => [18, 600, 1.2, $fonts['body'] ?? 'Inter']];
		$y = $column['y'];
		$n = 0;
		foreach ($input['texts'] as $t) {
			[$size, $weight, $lh, $family] = $sizes[$t['role']] ?? $sizes['body'];
			$isCta = $t['role'] === 'cta';
			$width = $isCta ? min($column['width'], max(200, (int) round(mb_strlen($t['content']) * $size * 0.6 + 64))) : $column['width'];
			$lines = max(1, (int) ceil(mb_strlen($t['content']) * $size * 0.55 / max(1, $width - ($isCta ? 64 : 0))));
			$height = $isCta ? (int) round($size * $lh + 36) : (int) round($lines * $size * $lh);
			$x = match ($align) {
				'center' => $column['x'] + (int) round(($column['width'] - $width) / 2),
				'right' => $column['x'] + $column['width'] - $width,
				default => $column['x'],
			};
			if ($y + $height > $H) {
				$height = max(1, $H - $y);
			}

			$nodes[] = [
				'id' => $t['role'] . '-' . ++$n, 'type' => $isCta ? 'button' : 'text', 'role' => $t['role'], 'name' => ucfirst($t['role']),
				'parentId' => 'content', 'order' => ++$order,
				'frame' => ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height],
				'style' => array_filter([
					'color' => $isCta ? $accentText : $color, 'fill' => $isCta ? $accent : null, 'radius' => $isCta ? 999 : null, 'padding' => $isCta ? 18 : null,
					'fontFamily' => $family, 'fontSize' => $size, 'fontWeight' => $weight, 'lineHeight' => $lh, 'textAlign' => $isCta ? 'center' : $align,
				], fn ($v) => $v !== null),
				'content' => $t['content'], 'layoutMode' => 'none', 'confidence' => 0.6, 'rasterized' => false,
			];
			$y = min($H - 1, $y + $height + 24);
		}

		if ($im) {
			imagedestroy($im);
		}

		$warnings = ['Układ odtworzony heurystycznie z kompozycji kierunku „' . ($input['direction']['title'] ?? $layout) . '” — pozycje tekstu przybliżone, do korekty w Figmie.'];
		if ($y >= $H - 1) {
			$warnings[] = 'Treść nie mieści się w strefie tekstu — skróć teksty lub zmień układ.';
		}
		if (! empty($input['mock'])) {
			$warnings[] = 'Obraz źródłowy jest poglądowy (mock) — bitmapa pokazuje tylko kompozycję.';
		}

		return [
			'layout' => [
				'schemaVersion' => LayoutSchema::VERSION,
				'canvas' => ['width' => $W, 'height' => $H, 'background' => $bg],
				'nodes' => $nodes,
				'tokens' => ['colors' => array_map(fn ($p) => ['name' => (string) $p['name'], 'value' => strtolower((string) $p['value'])], array_slice($input['palette'] ?? [], 0, 50))],
				'rasterizedRegions' => $regions,
				'warnings' => $warnings,
			],
			'cost' => 0.0,
			'usage' => null,
		];
	}

	private static function load(string $path): \GdImage|false
	{
		if (! $path || ! is_readable($path) || ! function_exists('imagecreatefromstring') || filesize($path) > 15 * 1024 * 1024) {
			return false;
		}

		return @imagecreatefromstring((string) file_get_contents($path));
	}

	/** Średni kolor strefy (próbkowanie siatką 12×12). */
	private static function average(\GdImage $im, float $x1, float $y1, float $x2, float $y2): string
	{
		$w = imagesx($im);
		$h = imagesy($im);
		$sum = [0, 0, 0];
		$n = 0;
		for ($i = 0; $i < 12; $i++) {
			for ($j = 0; $j < 12; $j++) {
				$px = (int) min($w - 1, ($x1 + ($x2 - $x1) * ($i + .5) / 12) * $w);
				$py = (int) min($h - 1, ($y1 + ($y2 - $y1) * ($j + .5) / 12) * $h);
				$rgb = imagecolorat($im, $px, $py);
				$sum[0] += ($rgb >> 16) & 255;
				$sum[1] += ($rgb >> 8) & 255;
				$sum[2] += $rgb & 255;
				$n++;
			}
		}

		return sprintf('#%02x%02x%02x', ...array_map(fn ($c) => (int) round($c / $n), $sum));
	}

	/** Kolor tekstu o najwyższym kontraście wobec tła (preferuje paletę, min. WCAG AA 4.5:1). */
	public static function readable(string $background, array $candidates): string
	{
		$best = null;
		foreach (array_merge($candidates, ['#ffffff', '#000000']) as $c) {
			if (! is_string($c) || ! preg_match('/^#[0-9a-f]{6}$/i', $c)) {
				continue;
			}
			$ratio = self::contrast($background, $c);
			if ($ratio >= 4.5) {
				return strtolower($c);
			}
			if (! $best || $ratio > $best[1]) {
				$best = [strtolower($c), $ratio];
			}
		}

		return $best[0] ?? '#ffffff';
	}

	public static function contrast(string $a, string $b): float
	{
		$lum = function (string $hex) {
			$c = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, sscanf(substr($hex, 0, 7), '#%02x%02x%02x'));
			return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
		};
		[$l1, $l2] = [$lum($a), $lum($b)];

		return round((max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05), 2);
	}
}
