<?php

namespace App\Clara\Projects;

use App\Clara\Database\Schema;

/**
 * Jawny seed demonstracyjny H2Otwock (basen) dla PoC. Oznaczony `brief.demo = true`,
 * bez danych pobranych od klienta — treści są przykładowe i do uzupełnienia przez projektanta.
 */
class DemoSeed
{
	public const NAME = 'H2Otwock — basen (demo)';

	/**
	 * @return array{project: int, created: bool}
	 */
	public static function run(int $ownerId): array
	{
		global $wpdb;

		$existing = (int) $wpdb->get_var($wpdb->prepare(
			'SELECT id FROM ' . Schema::table('projects') . ' WHERE owner_user_id = %d AND name = %s LIMIT 1',
			$ownerId,
			self::NAME
		));

		if ($existing) {
			return ['project' => $existing, 'created' => false];
		}

		$projectId = Projects::create([
			'name' => self::NAME,
			'client_name' => 'H2Otwock',
			'industry' => 'Obiekt sportowo-rekreacyjny',
			'website_url' => '',
			'figma_url' => '',
			'brief' => [
				'demo' => true,
				'summary' => 'Przykładowy brief demonstracyjny: nowa strona krytej pływalni dla mieszkańców i rodzin. Uzupełnij faktami od klienta.',
				'audience' => 'Rodziny z dziećmi, osoby trenujące pływanie, seniorzy, szkoły.',
				'goals' => ['Pokazać ofertę basenu i stref rekreacji', 'Ułatwić sprawdzenie godzin otwarcia i cennika'],
				'tone' => ['Świeży', 'Przyjazny', 'Aktywny'],
				'mustHave' => ['Godziny otwarcia', 'Cennik', 'Zdjęcia obiektu'],
				'avoid' => ['Stockowe zdjęcia niepasujące do obiektu'],
			],
		], $ownerId);

		Sections::create($projectId, [
			'type' => 'hero',
			'name' => 'Hero strony głównej',
			'status' => 'ready',
			'requirements' => [
				'goal' => 'Pierwsze wrażenie: nowoczesny basen dla całej rodziny i szybkie przejście do oferty.',
				'content' => ['Nagłówek', 'Krótki opis', 'Główne CTA', 'Zdjęcie obiektu'],
				'hardConstraints' => ['Nagłówek i CTA widoczne bez przewijania', 'Tekst czytelny na zdjęciu (kontrast AA)', 'Wersja mobilna bez ucinania CTA'],
				'softConstraints' => ['Paleta wodnych błękitów', 'Rodzinny, aktywny nastrój', 'Drugie CTA do cennika'],
				'notes' => 'Sekcja PoC dla generowania 4 koncepcji.',
			],
		]);

		return ['project' => $projectId, 'created' => true];
	}
}
