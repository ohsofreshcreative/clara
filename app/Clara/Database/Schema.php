<?php

namespace App\Clara\Database;

/**
 * Tabele Clara. Migracje wyłącznie przez dbDelta (tylko dodaje/zmienia kolumny i indeksy,
 * nigdy nie usuwa tabel ani danych). Integralność relacji pilnuje warstwa aplikacji —
 * dbDelta nie obsługuje kluczy obcych.
 */
class Schema
{
	public const VERSION = '2';

	public const OPTION = 'clara_db_version';

	public const TABLES = [
		'projects',
		'design_systems',
		'sections',
		'inspirations',
		'generations',
		'generation_sources',
		'layout_documents',
		'jobs',
	];

	public static function table(string $name): string
	{
		global $wpdb;

		return $wpdb->prefix . 'clara_' . $name;
	}

	public static function isCurrent(): bool
	{
		return get_option(self::OPTION) === self::VERSION;
	}

	public static function maybeMigrate(): void
	{
		if (! self::isCurrent()) {
			self::migrate();
		}
	}

	/**
	 * @return array<string, string> zmiany zgłoszone przez dbDelta (pusta tablica = schemat aktualny)
	 */
	public static function migrate(): array
	{
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$changes = dbDelta(self::statements());

		update_option(self::OPTION, self::VERSION, false);

		return $changes;
	}

	/**
	 * @return array<string, bool>
	 */
	public static function status(): array
	{
		global $wpdb;

		$status = [];

		foreach (self::TABLES as $name) {
			$table = self::table($name);
			$status[$name] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
		}

		return $status;
	}

	/**
	 * Format zgodny z wymaganiami dbDelta: każda kolumna w osobnej linii,
	 * dwie spacje po PRIMARY KEY, KEY zamiast INDEX.
	 *
	 * @return string[]
	 */
	public static function statements(): array
	{
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$t = fn (string $name) => self::table($name);

		return [
			"CREATE TABLE {$t('projects')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_user_id bigint(20) unsigned NOT NULL,
				name varchar(191) NOT NULL,
				client_name varchar(191) NOT NULL DEFAULT '',
				industry varchar(191) NOT NULL DEFAULT '',
				website_url varchar(2048) NOT NULL DEFAULT '',
				figma_url varchar(2048) NOT NULL DEFAULT '',
				brief_json longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY owner_user_id (owner_user_id),
				KEY created_at (created_at)
			) {$charset};",

			"CREATE TABLE {$t('design_systems')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				version int(10) unsigned NOT NULL,
				tokens_json longtext NULL,
				assets_json longtext NULL,
				source_meta_json longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY project_version (project_id,version),
				KEY created_at (created_at)
			) {$charset};",

			"CREATE TABLE {$t('sections')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				type varchar(64) NOT NULL,
				name varchar(191) NOT NULL DEFAULT '',
				requirements_json longtext NULL,
				status varchar(32) NOT NULL DEFAULT 'draft',
				selected_generation_id bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY project_id (project_id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset};",

			"CREATE TABLE {$t('inspirations')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				section_id bigint(20) unsigned NULL,
				source_url varchar(2048) NOT NULL DEFAULT '',
				thumbnail_url varchar(2048) NOT NULL DEFAULT '',
				local_asset_id bigint(20) unsigned NULL,
				attribution_json longtext NULL,
				rights_json longtext NULL,
				metadata_json longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY project_id (project_id),
				KEY section_id (section_id),
				KEY created_at (created_at)
			) {$charset};",

			"CREATE TABLE {$t('generations')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				section_id bigint(20) unsigned NOT NULL,
				parent_id bigint(20) unsigned NULL,
				kind varchar(32) NOT NULL,
				art_direction_json longtext NULL,
				prompt_json longtext NULL,
				model_id varchar(191) NOT NULL DEFAULT '',
				provider varchar(64) NOT NULL DEFAULT '',
				result_asset_id bigint(20) unsigned NULL,
				status varchar(32) NOT NULL DEFAULT 'queued',
				estimated_cost decimal(10,4) NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY section_id (section_id),
				KEY parent_id (parent_id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset};",

			"CREATE TABLE {$t('generation_sources')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				generation_id bigint(20) unsigned NOT NULL,
				inspiration_id bigint(20) unsigned NOT NULL,
				role varchar(32) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY generation_id (generation_id),
				KEY inspiration_id (inspiration_id)
			) {$charset};",

			"CREATE TABLE {$t('layout_documents')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				generation_id bigint(20) unsigned NOT NULL,
				schema_version varchar(16) NOT NULL,
				layout_json longtext NULL,
				validation_json longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY generation_id (generation_id),
				KEY created_at (created_at)
			) {$charset};",

			"CREATE TABLE {$t('jobs')} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_type varchar(64) NOT NULL,
				payload_json longtext NULL,
				status varchar(32) NOT NULL DEFAULT 'queued',
				attempt_count int(10) unsigned NOT NULL DEFAULT 0,
				error_code varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset};",
		];
	}
}
