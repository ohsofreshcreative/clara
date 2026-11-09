<?php

namespace App\Clara\Cli;

use App\Clara\Creative\ImageJobs;
use App\Clara\Database\Schema;
use App\Clara\Projects\DemoSeed;
use WP_CLI;

/**
 * Narzędzia Clara: `wp clara migrate`, `wp clara status`.
 */
class ClaraCommand
{
	/**
	 * Tworzy lub aktualizuje tabele Clara (idempotentnie, bez usuwania danych).
	 */
	public function migrate(): void
	{
		$changes = Schema::migrate();

		foreach ($changes as $change) {
			WP_CLI::log($change);
		}

		WP_CLI::success(sprintf('Schemat Clara v%s, zmian: %d.', Schema::VERSION, count($changes)));
	}

	/**
	 * Tworzy jawny projekt demonstracyjny H2Otwock z sekcją Hero (idempotentnie).
	 *
	 * ## OPTIONS
	 *
	 * --owner=<id|login>
	 * : Właściciel projektu.
	 *
	 * @subcommand seed-demo
	 */
	public function seedDemo(array $args, array $assoc): void
	{
		$user = is_numeric($assoc['owner']) ? get_user_by('id', (int) $assoc['owner']) : get_user_by('login', $assoc['owner']);

		if (! $user) {
			WP_CLI::error('Nie znaleziono użytkownika.');
		}

		Schema::maybeMigrate();
		$result = DemoSeed::run($user->ID);

		WP_CLI::success($result['created']
			? "Utworzono projekt demo #{$result['project']}."
			: "Projekt demo już istnieje (#{$result['project']}), bez zmian.");
	}

	/**
	 * Pokazuje wersję schematu i obecność tabel.
	 */
	public function status(): void
	{
		WP_CLI::log('Wersja schematu: ' . (get_option(Schema::OPTION) ?: 'brak'));

		$rows = [];

		foreach (Schema::status() as $name => $exists) {
			$rows[] = ['table' => Schema::table($name), 'exists' => $exists ? 'tak' : 'nie'];
		}

		WP_CLI\Utils\format_items('table', $rows, ['table', 'exists']);
	}

	/**
	 * Przetwarza kolejkę generowania obrazów (alternatywa dla WP-Cron).
	 *
	 * [--limit=<n>]
	 * : Maksymalna liczba zadań w tym uruchomieniu.
	 * ---
	 * default: 20
	 * ---
	 */
	public function worker(array $args, array $assoc): void
	{
		$stats = ImageJobs::process(max(1, (int) ($assoc['limit'] ?? 20)));

		WP_CLI::success(sprintf(
			'Przetworzono %d (gotowe: %d, błędy: %d, ponowione: %d, przerwane wcześniej: %d).',
			$stats['processed'],
			$stats['succeeded'],
			$stats['failed'],
			$stats['requeued'],
			$stats['recovered']
		));
	}
}
