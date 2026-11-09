<?php

namespace App\Clara\Creative;

use App\Clara\DesignSystems\TokenSchema;
use App\Clara\Support\Validator;
use WP_Error;

/**
 * Kontrakt odpowiedzi Art Directora: interpretacja briefu + dokładnie 4 kierunki o różnych
 * kompozycjach. Ten sam schemat idzie do LLM (structured output) i waliduje odpowiedź po stronie
 * serwera — structured output gwarantuje składnię, ale nie reguły biznesowe (pokrycie treści,
 * hard constraints, różnorodność, kolory z design systemu).
 */
class ArtDirectionSchema
{
	public const COUNT = 4;

	public const LAYOUTS = [
		'split' => 'Podział: tekst i obraz obok siebie',
		'full-bleed' => 'Pełnoekranowy obraz z nakładką tekstu',
		'centered-type' => 'Typografia w centrum, obraz w tle lub detalu',
		'asymmetric-collage' => 'Asymetryczny kolaż kilku kadrów',
		'editorial-grid' => 'Siatka edytorska z blokami treści',
		'framed-card' => 'Karta z treścią na tle obrazu',
	];

	public const FOCAL_POINTS = ['left', 'right', 'center', 'top', 'bottom'];

	public const TEXT_ALIGN = ['left', 'center', 'right'];

	public const IMAGE_TREATMENTS = ['photo', 'illustration', 'abstract', 'typographic', 'collage'];

	public const KEYS = ['A', 'B', 'C', 'D'];

	public static function jsonSchema(): array
	{
		$str = ['type' => 'string'];
		$strList = ['type' => 'array', 'items' => $str];
		$obj = fn (array $props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];

		return $obj([
			'interpretation' => $obj(['summary' => $str, 'audience' => $str, 'keyMessages' => $strList]),
			'directions' => ['type' => 'array', 'items' => $obj([
				'title' => $str,
				'concept' => $str,
				'mood' => $strList,
				'composition' => $obj([
					'layout' => ['type' => 'string', 'enum' => array_keys(self::LAYOUTS)],
					'focalPoint' => ['type' => 'string', 'enum' => self::FOCAL_POINTS],
					'textAlign' => ['type' => 'string', 'enum' => self::TEXT_ALIGN],
					'imageTreatment' => ['type' => 'string', 'enum' => self::IMAGE_TREATMENTS],
					'description' => $str,
				]),
				'palette' => ['type' => 'array', 'items' => $obj(['name' => $str, 'value' => $str, 'role' => $str])],
				'typography' => $str,
				'imagery' => $str,
				'copy' => $obj(['headline' => $str, 'subheadline' => $str, 'cta' => $str]),
				'requiredContent' => ['type' => 'array', 'items' => $obj(['item' => $str, 'placement' => $str])],
				'hardConstraints' => ['type' => 'array', 'items' => $obj(['constraint' => $str, 'how' => $str])],
				'softConstraints' => ['type' => 'array', 'items' => $obj(['constraint' => $str, 'applied' => ['type' => 'boolean'], 'note' => $str])],
			])],
		]);
	}

	/**
	 * @param array{requiredContent: string[], hardConstraints: string[], softConstraints: string[], dsColors: array<string, string>} $context
	 */
	public static function validate(mixed $data, array $context): array|WP_Error
	{
		$v = new Validator();

		if (! is_array($data) || ! is_array($data['directions'] ?? null) || ! array_is_list($data['directions'])) {
			$v->fail('directions', 'Brak listy kierunków.');
			return self::fail($v);
		}

		if (count($data['directions']) !== self::COUNT) {
			$v->fail('directions', sprintf('Oczekiwano dokładnie %d kierunków, otrzymano %d.', self::COUNT, count($data['directions'])));
			return self::fail($v);
		}

		$in = is_array($data['interpretation'] ?? null) ? $data['interpretation'] : [];
		$interpretation = [
			'summary' => $v->text('interpretation.summary', $in['summary'] ?? '', 800, true, true),
			'audience' => $v->text('interpretation.audience', $in['audience'] ?? '', 400, false, true),
			'keyMessages' => $v->textList('interpretation.keyMessages', $in['keyMessages'] ?? [], 8, 200),
		];

		$directions = [];
		foreach ($data['directions'] as $i => $d) {
			$directions[] = self::direction($v, "directions[{$i}]", is_array($d) ? $d : [], self::KEYS[$i], $context);
		}

		// Różnorodność: każda kompozycja inna, a punkty skupienia nie mogą być wszystkie takie same.
		$layouts = array_column(array_column($directions, 'composition'), 'layout');
		foreach (array_diff_key($layouts, array_unique($layouts)) as $i => $layout) {
			$v->fail("directions[{$i}].composition.layout", "Kompozycja „{$layout}” powtarza się — każdy kierunek musi mieć inną.");
		}
		if (count(array_unique(array_column(array_column($directions, 'composition'), 'focalPoint'))) < 2) {
			$v->fail('directions', 'Kierunki mają ten sam punkt skupienia — kompozycje są zbyt podobne.');
		}

		return $v->ok() ? ['interpretation' => $interpretation, 'directions' => $directions] : self::fail($v);
	}

	private static function direction(Validator $v, string $p, array $d, string $key, array $context): array
	{
		$c = is_array($d['composition'] ?? null) ? $d['composition'] : [];
		$copy = is_array($d['copy'] ?? null) ? $d['copy'] : [];

		$direction = [
			'key' => $key,
			'title' => $v->text("{$p}.title", $d['title'] ?? '', 80, true),
			'concept' => $v->text("{$p}.concept", $d['concept'] ?? '', 800, true, true),
			'mood' => $v->textList("{$p}.mood", $d['mood'] ?? [], 6, 40),
			'composition' => [
				'layout' => self::enum($v, "{$p}.composition.layout", $c['layout'] ?? null, array_keys(self::LAYOUTS)),
				'focalPoint' => self::enum($v, "{$p}.composition.focalPoint", $c['focalPoint'] ?? null, self::FOCAL_POINTS),
				'textAlign' => self::enum($v, "{$p}.composition.textAlign", $c['textAlign'] ?? null, self::TEXT_ALIGN),
				'imageTreatment' => self::enum($v, "{$p}.composition.imageTreatment", $c['imageTreatment'] ?? null, self::IMAGE_TREATMENTS),
				'description' => $v->text("{$p}.composition.description", $c['description'] ?? '', 600, true, true),
			],
			'palette' => [],
			'typography' => $v->text("{$p}.typography", $d['typography'] ?? '', 300, true, true),
			'imagery' => $v->text("{$p}.imagery", $d['imagery'] ?? '', 500, true, true),
			'copy' => [
				'headline' => $v->text("{$p}.copy.headline", $copy['headline'] ?? '', 120, true),
				'subheadline' => $v->text("{$p}.copy.subheadline", $copy['subheadline'] ?? '', 240),
				'cta' => $v->text("{$p}.copy.cta", $copy['cta'] ?? '', 40, true),
			],
			'requiredContent' => self::coverage($v, "{$p}.requiredContent", $d['requiredContent'] ?? [], 'item', 'placement', $context['requiredContent'], 'wymaganej treści'),
			'hardConstraints' => self::coverage($v, "{$p}.hardConstraints", $d['hardConstraints'] ?? [], 'constraint', 'how', $context['hardConstraints'], 'hard constraint'),
			'softConstraints' => [],
		];

		$palette = is_array($d['palette'] ?? null) && array_is_list($d['palette']) ? $d['palette'] : [];
		if (count($palette) < 2 || count($palette) > 6) {
			$v->fail("{$p}.palette", 'Paleta musi mieć od 2 do 6 kolorów.');
		}
		$dsNames = array_change_key_case($context['dsColors'], CASE_LOWER);
		foreach (array_slice($palette, 0, 6) as $j => $color) {
			$name = $v->text("{$p}.palette[{$j}].name", $color['name'] ?? '', 80, true);
			if ($dsNames && ! isset($dsNames[mb_strtolower($name)])) {
				$v->fail("{$p}.palette[{$j}].name", "Kolor „{$name}” nie należy do design systemu projektu.");
			}
			$direction['palette'][] = [
				'name' => $name,
				// Przy design systemie wartość bierzemy z tokenu, nie z odpowiedzi modelu.
				'value' => $dsNames[mb_strtolower($name)] ?? TokenSchema::color($v, "{$p}.palette[{$j}].value", $color['value'] ?? null),
				'role' => $v->text("{$p}.palette[{$j}].role", $color['role'] ?? '', 80),
			];
		}

		$soft = array_map('mb_strtolower', $context['softConstraints']);
		foreach (is_array($d['softConstraints'] ?? null) ? array_slice($d['softConstraints'], 0, 20) : [] as $j => $s) {
			$constraint = $v->text("{$p}.softConstraints[{$j}].constraint", $s['constraint'] ?? '', 300, true);
			if (! in_array(mb_strtolower($constraint), $soft, true)) {
				$v->fail("{$p}.softConstraints[{$j}].constraint", "„{$constraint}” nie jest soft constraintem tej sekcji.");
			}
			$direction['softConstraints'][] = ['constraint' => $constraint, 'applied' => (bool) ($s['applied'] ?? false), 'note' => $v->text("{$p}.softConstraints[{$j}].note", $s['note'] ?? '', 300)];
		}

		return $direction;
	}

	/**
	 * Każda pozycja z listy wymaganej musi wystąpić (dosłownie, bez względu na wielkość liter) z opisem realizacji.
	 */
	private static function coverage(Validator $v, string $p, mixed $items, string $key, string $detail, array $required, string $label): array
	{
		$given = [];
		foreach (is_array($items) ? array_slice($items, 0, 40) : [] as $j => $item) {
			$name = $v->text("{$p}[{$j}].{$key}", $item[$key] ?? '', 300, true);
			$given[mb_strtolower($name)] = ['name' => $name, 'detail' => $v->text("{$p}[{$j}].{$detail}", $item[$detail] ?? '', 300, true)];
		}

		$out = [];
		foreach ($required as $req) {
			$match = $given[mb_strtolower($req)] ?? null;
			if (! $match) {
				$v->fail($p, "Brak {$label}: „{$req}”.");
				continue;
			}
			$out[] = [$key => $req, $detail => $match['detail']];
		}

		return $out;
	}

	private static function enum(Validator $v, string $path, mixed $value, array $allowed): string
	{
		if (! is_string($value) || ! in_array($value, $allowed, true)) {
			$v->fail($path, 'Dozwolone wartości: ' . implode(', ', $allowed) . '.');
			return '';
		}

		return $value;
	}

	private static function fail(Validator $v): WP_Error
	{
		$error = $v->error();

		return new WP_Error('clara_art_invalid', __('Odpowiedź modelu nie spełnia kontraktu kierunków artystycznych.', 'clara'), ['status' => 422] + $error->get_error_data());
	}
}
