<?php

namespace App\Clara\Creative;

use App\Clara\Database\Schema;
use App\Clara\Projects\Attachments;
use WP_Error;

/**
 * Asynchroniczne generowanie obrazów: 1 żądanie → partia (batch) do 4 generacji kind=image,
 * każda z własnym jobem w tabeli `jobs`. Przetwarza WP-Cron (`clara_image_worker`) lub
 * `wp clara worker`. To funkcja biznesowa aplikacji — nie runner ani retry Christie.
 *
 * Statusy: queued → running → succeeded | failed | cancelled.
 */
class ImageJobs
{
	public const JOB_TYPE = 'image_generation';

	public const KIND = 'image';

	public const HOOK = 'clara_image_worker';

	public const MAX_VARIANTS = 4;

	/** Ograniczony retry wyłącznie dla błędów przejściowych (limit zapytań, sieć). */
	public const MAX_ATTEMPTS = 2;

	/** Job „running” dłużej niż tyle sekund uznajemy za przerwany (np. zabity proces). */
	public const STALE_AFTER = 600;

	private const MAX_BYTES = 10 * 1024 * 1024;

	private const MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

	public static function provider(): ?ImageProvider
	{
		// Z kluczem OpenAI — prawdziwe obrazy; bez klucza mock (jawnie oznaczany w UI).
		$default = OpenAiImageProvider::apiKey() !== '' ? new OpenAiImageProvider() : new MockImageProvider();
		$provider = apply_filters('clara/image_provider', $default);

		return $provider instanceof ImageProvider ? $provider : null;
	}

	/**
	 * @param string[] $keys klucze kierunków (A–D); puste = wszystkie 4
	 */
	public static function enqueue(array $project, array $section, string $runId, array $keys): array|WP_Error
	{
		$provider = self::provider();
		if (! $provider) {
			return new WP_Error('clara_image_unconfigured', __('Generator obrazów nie jest skonfigurowany na serwerze.', 'clara'), ['status' => 409]);
		}

		$run = null;
		foreach (Generations::runs($section['id'], 50) as $candidate) {
			if ($candidate['runId'] === $runId) {
				$run = $candidate;
				break;
			}
		}
		if (! $run) {
			return new WP_Error('clara_not_found', __('Nie znaleziono kierunków artystycznych do wygenerowania.', 'clara'), ['status' => 404]);
		}

		$directions = $keys ? array_values(array_filter($run['directions'], fn ($d) => in_array($d['key'], $keys, true))) : $run['directions'];
		$directions = array_slice($directions, 0, self::MAX_VARIANTS);

		if ($error = self::guard($provider, $section['id'], count($directions))) {
			return $error;
		}

		$batchId = wp_generate_uuid4();
		$now = current_time('mysql', true);

		foreach ($directions as $direction) {
			self::insertQueued($provider, $section['id'], (int) $direction['id'], $direction, [
				'batchId' => $batchId,
				'artRunId' => $runId,
				'directionKey' => $direction['key'],
				'title' => $direction['title'],
				'prompt' => ImagePrompt::build($direction, $run['interpretation']),
				'designSystem' => $run['context']['designSystem'] ?? null,
				'projectId' => $project['id'],
				'createdBy' => get_current_user_id(),
				'mock' => $provider->isMock(),
			], $now);
		}

		self::schedule();

		return self::batch($section['id'], $batchId);
	}

	/**
	 * Wspólne ograniczenia zlecenia: brak trwającej partii w sekcji, limit kosztu partii i dzienny budżet.
	 */
	public static function guard(ImageProvider $provider, int $sectionId, int $count, bool $checkBusy = true): ?WP_Error
	{
		global $wpdb;

		$active = $checkBusy ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('generations') . " WHERE section_id = %d AND kind = %s AND status IN ('queued', 'running')", $sectionId, self::KIND)) : 0;
		if ($active > 0) {
			return new WP_Error('clara_image_busy', __('Poprzednia partia obrazów dla tej sekcji jeszcze trwa — poczekaj lub ją anuluj.', 'clara'), ['status' => 409]);
		}

		// Limity kosztów: na partię i dzienny budżet użytkownika (USD, szacunek dostawcy).
		$unit = $provider->estimateCost();
		$batchCost = $unit === null ? null : round($unit * $count, 4);
		$maxBatch = (float) apply_filters('clara/image_max_batch_cost', 2.0);
		$daily = (float) apply_filters('clara/image_daily_budget', 10.0);

		if ($unit === null && ! apply_filters('clara/image_allow_unknown_cost', false)) {
			return new WP_Error('clara_image_cost_unknown', __('Dostawca nie podaje szacunku kosztu — generowanie zablokowane limitem kosztów.', 'clara'), ['status' => 402]);
		}
		if ($batchCost !== null && $batchCost > $maxBatch) {
			return new WP_Error('clara_image_cost_limit', sprintf(__('Szacowany koszt partii $%1$.2f przekracza limit $%2$.2f.', 'clara'), $batchCost, $maxBatch), ['status' => 402]);
		}
		$spent = self::spentToday(get_current_user_id());
		if ($batchCost !== null && $spent + $batchCost > $daily) {
			return new WP_Error('clara_image_budget', sprintf(__('Dzienny budżet obrazów $%1$.2f zostałby przekroczony (wykorzystano $%2$.2f).', 'clara'), $daily, $spent), ['status' => 402]);
		}

		return null;
	}

	/** Nowa generacja kind=image w kolejce + job. Zawsze nowy wiersz — istniejących generacji nie nadpisujemy. */
	public static function insertQueued(ImageProvider $provider, int $sectionId, int $parentId, array $direction, array $meta, string $now): int
	{
		global $wpdb;

		$wpdb->insert(Schema::table('generations'), [
			'section_id' => $sectionId,
			'parent_id' => $parentId,
			'kind' => self::KIND,
			'art_direction_json' => wp_json_encode($direction),
			'prompt_json' => wp_json_encode($meta),
			'model_id' => $provider->model(),
			'provider' => $provider->id(),
			'status' => 'queued',
			'estimated_cost' => $provider->estimateCost(),
			'created_at' => $now,
		]);
		$id = (int) $wpdb->insert_id;

		$wpdb->insert(Schema::table('jobs'), [
			'job_type' => self::JOB_TYPE,
			'payload_json' => wp_json_encode(['generationId' => $id, 'batchId' => $meta['batchId']]),
			'status' => 'queued',
			'created_at' => $now,
			'updated_at' => $now,
		]);

		return $id;
	}

	public static function schedule(): void
	{
		if (! wp_next_scheduled(self::HOOK)) {
			wp_schedule_single_event(time(), self::HOOK);
		}

		if (apply_filters('clara/image_spawn_cron', true)) {
			spawn_cron();
		}
	}

	/**
	 * Przetwarza do $limit jobów. Bezpieczne przy równoległych wywołaniach (atomowe przejęcie joba).
	 *
	 * @return array{processed: int, succeeded: int, failed: int, requeued: int, recovered: int}
	 */
	public static function process(int $limit = 8): array
	{
		global $wpdb;

		$jobs = Schema::table('jobs');
		$stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'requeued' => 0, 'recovered' => self::recoverStale()];

		while ($stats['processed'] < $limit) {
			$id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$jobs} WHERE job_type = %s AND status = 'queued' ORDER BY id ASC LIMIT 1", self::JOB_TYPE));
			if (! $id) {
				break;
			}

			$claimed = $wpdb->query($wpdb->prepare(
				"UPDATE {$jobs} SET status = 'running', attempt_count = attempt_count + 1, updated_at = %s WHERE id = %d AND status = 'queued'",
				current_time('mysql', true),
				$id
			));
			if (! $claimed) {
				continue;
			}

			$stats['processed']++;
			$result = self::runJob($id);
			$stats[$result]++;
		}

		$remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$jobs} WHERE job_type = %s AND status = 'queued'", self::JOB_TYPE));
		if ($remaining > 0 && ! wp_next_scheduled(self::HOOK)) {
			wp_schedule_single_event(time() + 5, self::HOOK);
		}

		return $stats;
	}

	/**
	 * @return 'succeeded'|'failed'|'requeued'
	 */
	private static function runJob(int $jobId): string
	{
		global $wpdb;

		$job = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
		$payload = json_decode((string) $job['payload_json'], true) ?: [];
		$generation = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('generations') . ' WHERE id = %d', (int) ($payload['generationId'] ?? 0)), ARRAY_A);

		if (! $generation || $generation['status'] === 'cancelled') {
			self::finishJob($jobId, 'cancelled', $generation ? 'clara_image_cancelled' : 'clara_image_missing');
			return 'failed';
		}

		self::setGeneration((int) $generation['id'], ['status' => 'running']);
		$meta = json_decode((string) $generation['prompt_json'], true) ?: [];
		$direction = json_decode((string) $generation['art_direction_json'], true) ?: [];
		$provider = self::provider();

		if (! $provider || $provider->id() !== $generation['provider']) {
			return self::fail($jobId, $generation, new WP_Error('clara_image_provider_changed', __('Dostawca obrazów zmienił się od zlecenia — zleć generowanie ponownie.', 'clara')));
		}

		$result = $provider->generate((string) ($meta['prompt'] ?? ''), $direction);

		if (is_wp_error($result)) {
			$retryable = (bool) ($result->get_error_data()['retryable'] ?? false);
			if ($retryable && (int) $job['attempt_count'] < self::MAX_ATTEMPTS) {
				self::finishJob($jobId, 'queued', $result->get_error_code());
				self::setGeneration((int) $generation['id'], ['status' => 'queued'], ['lastError' => self::error($result)]);
				return 'requeued';
			}
			return self::fail($jobId, $generation, $result);
		}

		$stored = self::storeResult($generation, $meta, $result);
		if (is_wp_error($stored)) {
			return self::fail($jobId, $generation, $stored);
		}
		self::finishJob($jobId, 'succeeded', '');

		return 'succeeded';
	}

	/**
	 * Zapis gotowego obrazu do biblioteki projektu i oznaczenie generacji jako succeeded
	 * (wspólne dla kolejki i bezpośredniego generowania z dashboardu).
	 */
	public static function storeResult(array $generation, array $meta, array $result): ?WP_Error
	{
		$ext = self::MIMES[$result['mime'] ?? ''] ?? null;
		$size = strlen((string) $result['body']);
		if (! $ext) {
			return new WP_Error('clara_image_format', __('Dostawca zwrócił nieobsługiwany format obrazu.', 'clara'));
		}
		if ($size === 0 || $size > self::MAX_BYTES) {
			return new WP_Error('clara_image_size', __('Obraz od dostawcy jest pusty lub większy niż 10 MB.', 'clara'));
		}

		$stored = Attachments::store((int) $meta['projectId'], (int) $meta['createdBy'], $result['body'], "clara-hero-{$meta['directionKey']}" . (! empty($meta['remixOf']) ? '-remix' : '') . "-{$generation['id']}.{$ext}");
		if (is_wp_error($stored)) {
			return new WP_Error('clara_image_store', __('Nie udało się zapisać obrazu: ', 'clara') . $stored->get_error_message());
		}

		$cost = $result['cost'] ?? null;
		$dims = @getimagesizefromstring($result['body']) ?: [null, null];
		self::setGeneration((int) $generation['id'], [
			'status' => 'succeeded',
			'result_asset_id' => $stored['id'],
			'estimated_cost' => $cost ?? $generation['estimated_cost'],
		], [
			'lastError' => null,
			'error' => null,
			'result' => ['mime' => $stored['mime'], 'size' => $stored['size'], 'width' => $dims[0], 'height' => $dims[1], 'costSource' => $cost === null ? 'estimate' : 'provider', 'providerMeta' => array_slice((array) ($result['meta'] ?? []), 0, 20)],
		]);

		return null;
	}

	/** Generacja nieudana poza kolejką (bez joba). */
	public static function failGeneration(int $id, WP_Error $error): void
	{
		self::setGeneration($id, ['status' => 'failed', 'estimated_cost' => null], ['error' => self::error($error)]);
	}

	private static function fail(int $jobId, array $generation, WP_Error $error): string
	{
		self::finishJob($jobId, 'failed', $error->get_error_code());
		self::setGeneration((int) $generation['id'], ['status' => 'failed', 'estimated_cost' => null], ['error' => self::error($error)]);

		return 'failed';
	}

	/** Joby „running” porzucone przez przerwany proces → failed z czytelnym powodem. */
	private static function recoverStale(): int
	{
		global $wpdb;

		$jobs = Schema::table('jobs');
		$cutoff = gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER);
		$stale = $wpdb->get_results($wpdb->prepare("SELECT id, payload_json FROM {$jobs} WHERE job_type = %s AND status = 'running' AND updated_at < %s", self::JOB_TYPE, $cutoff), ARRAY_A);

		foreach ($stale ?: [] as $job) {
			$payload = json_decode((string) $job['payload_json'], true) ?: [];
			self::finishJob((int) $job['id'], 'failed', 'clara_image_interrupted');
			self::setGeneration((int) ($payload['generationId'] ?? 0), ['status' => 'failed'], ['error' => ['code' => 'clara_image_interrupted', 'message' => 'Generowanie zostało przerwane (przekroczony czas). Zleć je ponownie.']]);
		}

		return count($stale ?: []);
	}

	public static function cancel(int $sectionId, string $batchId): array|WP_Error
	{
		global $wpdb;

		$batch = self::batch($sectionId, $batchId);
		if (is_wp_error($batch)) {
			return $batch;
		}

		$cancelled = 0;
		foreach ($batch['items'] as $item) {
			if ($item['status'] !== 'queued') {
				continue;
			}
			$cancelled += (int) $wpdb->query($wpdb->prepare('UPDATE ' . Schema::table('generations') . " SET status = 'cancelled', estimated_cost = NULL WHERE id = %d AND status = 'queued'", $item['id']));
		}

		$wpdb->query($wpdb->prepare(
			'UPDATE ' . Schema::table('jobs') . " SET status = 'cancelled', error_code = 'clara_image_cancelled', updated_at = %s WHERE job_type = %s AND status = 'queued' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.batchId')) = %s",
			current_time('mysql', true),
			self::JOB_TYPE,
			$batchId
		));

		if ($cancelled === 0) {
			return new WP_Error('clara_image_not_cancellable', __('Nie ma obrazów oczekujących w kolejce — trwające generowanie nie może zostać przerwane.', 'clara'), ['status' => 409]);
		}

		return self::batch($sectionId, $batchId);
	}

	/**
	 * Partie obrazów sekcji, najnowsze pierwsze.
	 */
	public static function batches(int $sectionId, int $limit = 10): array
	{
		global $wpdb;

		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM ' . Schema::table('generations') . ' WHERE section_id = %d AND kind = %s ORDER BY id DESC LIMIT %d',
			$sectionId,
			self::KIND,
			$limit * self::MAX_VARIANTS
		), ARRAY_A);

		$batches = [];
		foreach ($rows ?: [] as $row) {
			$meta = json_decode((string) $row['prompt_json'], true) ?: [];
			$id = $meta['batchId'] ?? 'legacy-' . $row['id'];
			$batches[$id] ??= [
				'batchId' => $id,
				'artRunId' => $meta['artRunId'] ?? null,
				'provider' => $row['provider'],
				'model' => $row['model_id'],
				'mock' => (bool) ($meta['mock'] ?? false),
				'designSystem' => $meta['designSystem'] ?? null,
				'created_at' => mysql_to_rfc3339($row['created_at']),
				'items' => [],
			];
			$batches[$id]['items'][] = self::item($row, $meta);
		}

		return array_map([self::class, 'summarize'], array_values($batches));
	}

	public static function batch(int $sectionId, string $batchId): array|WP_Error
	{
		foreach (self::batches($sectionId, 50) as $batch) {
			if ($batch['batchId'] === $batchId) {
				return $batch;
			}
		}

		return new WP_Error('clara_not_found', __('Nie znaleziono.', 'clara'), ['status' => 404]);
	}

	private static function item(array $row, array $meta): array
	{
		$assetId = (int) $row['result_asset_id'];
		$labels = ['queued' => 'W kolejce', 'running' => 'Generowanie', 'succeeded' => 'Gotowe', 'failed' => 'Błąd', 'cancelled' => 'Anulowano'];

		return [
			'id' => (int) $row['id'],
			'parent_id' => (int) $row['parent_id'],
			'directionKey' => $meta['directionKey'] ?? '',
			'title' => $meta['title'] ?? '',
			'status' => $row['status'],
			'statusLabel' => $labels[$row['status']] ?? $row['status'],
			'error' => $meta['error'] ?? null,
			'lastError' => $meta['lastError'] ?? null,
			'cost' => $row['estimated_cost'] === null ? null : (float) $row['estimated_cost'],
			'costSource' => $meta['result']['costSource'] ?? 'estimate',
			'prompt' => $meta['prompt'] ?? '',
			'remixOf' => isset($meta['remixOf']) ? (int) $meta['remixOf'] : null,
			'quality' => $meta['quality'] ?? null,
			'refineOf' => isset($meta['refineOf']) ? (int) $meta['refineOf'] : null,
			'remixPrompt' => $meta['remixPrompt'] ?? null,
			'version' => (int) ($meta['version'] ?? 1),
			'rootId' => (int) ($meta['rootId'] ?? $row['id']),
			'sources' => $meta['sources'] ?? [],
			'created_at' => mysql_to_rfc3339($row['created_at']),
			'image' => $assetId && ($url = wp_get_attachment_url($assetId)) ? ['id' => $assetId, 'url' => $url, 'width' => $meta['result']['width'] ?? null, 'height' => $meta['result']['height'] ?? null] : null,
		];
	}

	private static function summarize(array $batch): array
	{
		usort($batch['items'], fn ($a, $b) => strcmp($a['directionKey'], $b['directionKey']));
		$statuses = array_column($batch['items'], 'status');
		$count = fn ($s) => count(array_keys($statuses, $s, true));

		$batch['status'] = match (true) {
			$count('running') > 0 => 'running',
			$count('queued') > 0 => 'queued',
			$count('succeeded') === count($statuses) => 'succeeded',
			$count('succeeded') > 0 => 'partial',
			$count('cancelled') === count($statuses) => 'cancelled',
			default => 'failed',
		};
		$costs = array_filter(array_column($batch['items'], 'cost'), fn ($c) => $c !== null);
		$batch['cost'] = $costs ? round(array_sum($costs), 4) : null;
		$batch['counts'] = array_count_values($statuses);

		return $batch;
	}

	private static function spentToday(int $userId): float
	{
		global $wpdb;

		return (float) $wpdb->get_var($wpdb->prepare(
			'SELECT COALESCE(SUM(g.estimated_cost), 0) FROM ' . Schema::table('generations') . ' g
			JOIN ' . Schema::table('sections') . ' s ON s.id = g.section_id
			JOIN ' . Schema::table('projects') . ' p ON p.id = s.project_id
			WHERE g.kind = %s AND p.owner_user_id = %d AND g.created_at >= %s',
			self::KIND,
			$userId,
			gmdate('Y-m-d 00:00:00')
		));
	}

	private static function setGeneration(int $id, array $columns, array $metaPatch = []): void
	{
		global $wpdb;

		if ($metaPatch) {
			$meta = json_decode((string) $wpdb->get_var($wpdb->prepare('SELECT prompt_json FROM ' . Schema::table('generations') . ' WHERE id = %d', $id)), true) ?: [];
			$columns['prompt_json'] = wp_json_encode(array_merge($meta, $metaPatch));
		}

		$wpdb->update(Schema::table('generations'), $columns, ['id' => $id]);
	}

	private static function finishJob(int $id, string $status, string $code): void
	{
		global $wpdb;

		$wpdb->update(Schema::table('jobs'), ['status' => $status, 'error_code' => $code, 'updated_at' => current_time('mysql', true)], ['id' => $id]);
	}

	private static function error(WP_Error $error): array
	{
		return ['code' => $error->get_error_code(), 'message' => $error->get_error_message()];
	}
}
