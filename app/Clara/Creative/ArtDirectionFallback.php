<?php

namespace App\Clara\Creative;

/**
 * Deterministyczny fallback, gdy LLM jest niedostępny lub zwrócił dane niezgodne z kontraktem.
 * Te same wejścia → ten sam wynik. Cztery stałe archetypy kompozycji gwarantują różnorodność,
 * a wymagane treści i hard constraints są przenoszone dosłownie. To szablon, nie praca kreatywna —
 * UI oznacza go jako fallback.
 */
class ArtDirectionFallback
{
	public const MODEL = 'deterministic-v1';

	private const ARCHETYPES = [
		[
			'title' => 'Klarowny podział',
			'layout' => 'split', 'focalPoint' => 'right', 'textAlign' => 'left', 'imageTreatment' => 'photo',
			'description' => 'Tekst w lewej kolumnie, duże zdjęcie po prawej. Czytelna hierarchia: nagłówek, opis, CTA.',
			'placements' => ['nagłówek w lewej kolumnie', 'pod nagłówkiem w lewej kolumnie', 'w lewej kolumnie nad CTA'],
			'imagery' => 'Jedno mocne zdjęcie przedstawiające miejsce lub doświadczenie, kadr pionowy.',
			'mood' => ['uporządkowany', 'rzeczowy'],
		],
		[
			'title' => 'Pełny kadr',
			'layout' => 'full-bleed', 'focalPoint' => 'center', 'textAlign' => 'center', 'imageTreatment' => 'photo',
			'description' => 'Zdjęcie na całą szerokość, treść wyśrodkowana na półprzezroczystej nakładce dla kontrastu.',
			'placements' => ['wyśrodkowany nagłówek na nakładce', 'pod nagłówkiem na nakładce', 'na dole nakładki obok CTA'],
			'imagery' => 'Szeroki, immersyjny kadr z przestrzenią na tekst w centrum.',
			'mood' => ['immersyjny', 'emocjonalny'],
		],
		[
			'title' => 'Typografia na pierwszym planie',
			'layout' => 'centered-type', 'focalPoint' => 'top', 'textAlign' => 'center', 'imageTreatment' => 'typographic',
			'description' => 'Duży nagłówek w górnej części, obraz jako drobny detal lub tło niskiego kontrastu.',
			'placements' => ['duży nagłówek u góry', 'pod nagłówkiem, węższa kolumna', 'w pasku pod tekstem'],
			'imagery' => 'Obraz w roli akcentu: detal, tekstura lub wycinek kadru.',
			'mood' => ['wyrazisty', 'nowoczesny'],
		],
		[
			'title' => 'Kolaż doświadczeń',
			'layout' => 'asymmetric-collage', 'focalPoint' => 'left', 'textAlign' => 'left', 'imageTreatment' => 'collage',
			'description' => 'Asymetryczny układ kilku kadrów różnej wielkości, treść w wolnym polu siatki.',
			'placements' => ['nagłówek w wolnym polu kolażu', 'obok największego kadru', 'w podpisie przy jednym z kadrów'],
			'imagery' => 'Trzy–cztery kadry pokazujące różne momenty doświadczenia.',
			'mood' => ['dynamiczny', 'różnorodny'],
		],
	];

	/** Paleta zastępcza tylko bez design systemu — jawnie opisana jako propozycja. */
	private const NEUTRAL = [['Tło', '#0b1b3a'], ['Tekst', '#ffffff'], ['Akcent', '#2f6bff']];

	public static function generate(array $context): array
	{
		$name = $context['project']['name'] ?: $context['section']['name'];
		$tone = implode(', ', array_slice($context['brief']['tone'], 0, 3));
		$goal = $context['section']['goal'] ?: ($context['brief']['goals'][0] ?? '');
		$colors = $context['dsColors'];
		$palette = count($colors) >= 2
			? array_map(fn ($n, $v, $i) => ['name' => (string) $n, 'value' => $v, 'role' => ['tło', 'tekst', 'akcent', 'uzupełniający'][$i] ?? 'uzupełniający'], array_keys(array_slice($colors, 0, 4, true)), array_slice($colors, 0, 4, true), range(0, min(3, count($colors) - 1)))
			: array_map(fn ($c) => ['name' => $c[0], 'value' => $c[1], 'role' => 'propozycja — brak design systemu'], self::NEUTRAL);

		$directions = [];
		foreach (self::ARCHETYPES as $i => $a) {
			$directions[] = [
				'title' => $a['title'],
				'concept' => trim("Kierunek „{$a['title']}” dla sekcji {$context['section']['name']}. " . ($goal ? "Cel: {$goal}. " : '') . ($tone ? "Ton: {$tone}." : '')),
				'mood' => $a['mood'],
				'composition' => array_intersect_key($a, array_flip(['layout', 'focalPoint', 'textAlign', 'imageTreatment', 'description'])),
				'palette' => $palette,
				'typography' => $context['designSystem']['typography'][0] ?? 'Krój bezszeryfowy, mocny kontrast wielkości nagłówka i treści.',
				'imagery' => $a['imagery'],
				'copy' => [
					'headline' => mb_substr($context['requiredContent'][0] ?? $name, 0, 120),
					'subheadline' => mb_substr($goal, 0, 240),
					'cta' => 'Dowiedz się więcej',
				],
				'requiredContent' => array_map(fn ($item, $j) => ['item' => $item, 'placement' => $a['placements'][min($j, 2)]], $context['requiredContent'], array_keys($context['requiredContent'])),
				'hardConstraints' => array_map(fn ($c) => ['constraint' => $c, 'how' => 'Uwzględnione w kompozycji — do weryfikacji przez projektanta.'], $context['hardConstraints']),
				'softConstraints' => array_map(fn ($c) => ['constraint' => $c, 'applied' => true, 'note' => 'Przyjęte jako preferencja.'], $context['softConstraints']),
			];
		}

		return [
			'interpretation' => [
				'summary' => mb_substr($context['brief']['summary'] ?: "Sekcja {$context['section']['name']} projektu {$name}.", 0, 800),
				'audience' => mb_substr($context['brief']['audience'], 0, 400),
				'keyMessages' => array_map(fn ($g) => mb_substr($g, 0, 200), array_slice($context['brief']['goals'], 0, 8)),
			],
			'directions' => $directions,
		];
	}
}
