<?php

namespace App\Clara\Creative;

use WP_Error;

/**
 * Mock generatora: deterministyczny obraz PNG (GD) odwzorowujący kompozycję i paletę kierunku.
 * Służy do testów i pracy bez dostawcy AI — nie jest wynikiem AI i tak jest oznaczany.
 */
class MockImageProvider implements ImageProvider
{
	public const WIDTH = 1200;

	public const HEIGHT = 675;

	public function id(): string
	{
		return 'mock';
	}

	public function model(): string
	{
		return 'mock-gd-v1';
	}

	public function isMock(): bool
	{
		return true;
	}

	public function estimateCost(): ?float
	{
		return 0.0;
	}

	public function generate(string $prompt, array $direction): array|WP_Error
	{
		if (! function_exists('imagecreatetruecolor')) {
			return new WP_Error('clara_image_mock_unavailable', __('Mock obrazów wymaga rozszerzenia GD.', 'clara'));
		}

		$w = self::WIDTH;
		$h = self::HEIGHT;
		$im = imagecreatetruecolor($w, $h);
		$palette = array_values(array_column($direction['palette'] ?? [], 'value')) ?: ['#0b1b3a', '#ffffff', '#2f6bff'];
		// Remiks w mocku: przesunięta paleta, żeby wersje różniły się wizualnie.
		$shift = max(0, (int) ($direction['remixVersion'] ?? 1) - 1) % count($palette);
		$palette = array_merge(array_slice($palette, $shift), array_slice($palette, 0, $shift));
		$color = function (int $i) use ($im, $palette) {
			[$r, $g, $b] = sscanf(substr($palette[$i % count($palette)], 0, 7), '#%02x%02x%02x');
			return imagecolorallocate($im, $r, $g, $b);
		};

		$bg = $color(0);
		$text = $color(1);
		$accent = $color(2);
		imagefilledrectangle($im, 0, 0, $w, $h, $bg);

		// Prostokąty jako [x1, y1, x2, y2] w ułamkach płótna: obraz (accent) i bloki tekstu (text).
		$shapes = match ($direction['composition']['layout'] ?? '') {
			'split' => [[$accent, .52, 0, 1, 1], [$text, .06, .3, .44, .42], [$text, .06, .48, .36, .54], [$text, .06, .62, .22, .72]],
			'full-bleed' => [[$accent, 0, 0, 1, 1], [$bg, .25, .25, .75, .75], [$text, .32, .36, .68, .46], [$text, .4, .56, .6, .64]],
			'centered-type' => [[$text, .15, .12, .85, .32], [$text, .3, .38, .7, .44], [$accent, .4, .56, .6, .9]],
			'asymmetric-collage' => [[$accent, .45, .06, .75, .61], [$accent, .78, .3, .96, .9], [$accent, .3, .65, .52, .95], [$text, .05, .2, .39, .32], [$text, .05, .38, .29, .44]],
			'editorial-grid' => [[$accent, .04, .08, .32, .92], [$text, .36, .08, .96, .22], [$accent, .36, .3, .64, .92], [$text, .68, .3, .96, .6]],
			default => [[$accent, .08, .18, .48, .82], [$text, .12, .28, .42, .4]],
		};

		foreach ($shapes as [$c, $x1, $y1, $x2, $y2]) {
			imagefilledrectangle($im, (int) ($x1 * $w), (int) ($y1 * $h), (int) ($x2 * $w) - 1, (int) ($y2 * $h) - 1, $c);
		}

		$version = (int) ($direction['remixVersion'] ?? 1);
		imagestring($im, 5, 16, $h - 28, 'MOCK - Clara - kierunek ' . ($direction['key'] ?? '?') . ($version > 1 ? " - remix v{$version}" : ''), $text);

		ob_start();
		imagepng($im);
		$body = (string) ob_get_clean();
		imagedestroy($im);

		return ['body' => $body, 'mime' => 'image/png', 'cost' => 0.0, 'meta' => ['width' => $w, 'height' => $h, 'promptHash' => md5($prompt)]];
	}
}
