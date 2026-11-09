<?php

namespace App\Clara\Projects;

use WP_Error;

/**
 * Załączniki briefu jako załączniki biblioteki mediów z metą `_clara_project_id`.
 * Przyjmujemy surowe body (jak core /wp/v2/media); typ weryfikowany po zawartości pliku.
 */
class Attachments
{
	public const META = '_clara_project_id';

	public const MIMES = [
		'jpg|jpeg' => 'image/jpeg',
		'png' => 'image/png',
		'webp' => 'image/webp',
		'pdf' => 'application/pdf',
	];

	public static function maxBytes(): int
	{
		return (int) apply_filters('clara/attachment_max_bytes', 10 * MB_IN_BYTES);
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function store(int $projectId, int $userId, string $body, string $filename): array|WP_Error
	{
		$filename = sanitize_file_name(wp_basename($filename));
		$size = strlen($body);

		if ($filename === '' || $size === 0) {
			return new WP_Error('clara_upload_empty', __('Brak pliku lub nazwy pliku.', 'clara'), ['status' => 400]);
		}

		if ($size > self::maxBytes()) {
			return new WP_Error('clara_upload_too_large', sprintf(__('Plik jest za duży (limit %s).', 'clara'), size_format(self::maxBytes())), ['status' => 413]);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = wp_tempnam($filename);
		file_put_contents($tmp, $body);

		// Rozszerzenie i rzeczywista zawartość (finfo) muszą zgadzać się z listą dozwolonych typów.
		$check = wp_check_filetype_and_ext($tmp, $filename, self::MIMES);

		if (! $check['type'] || ! in_array($check['type'], self::MIMES, true)) {
			wp_delete_file($tmp);
			return new WP_Error('clara_upload_type', __('Niedozwolony typ pliku. Dozwolone: JPG, PNG, WebP, PDF.', 'clara'), ['status' => 415]);
		}

		$file = [
			'name' => $check['proper_filename'] ?: $filename,
			'type' => $check['type'],
			'tmp_name' => $tmp,
			'error' => 0,
			'size' => $size,
		];

		$result = wp_handle_sideload($file, ['test_form' => false, 'mimes' => self::MIMES]);

		if (isset($result['error'])) {
			wp_delete_file($tmp);
			return new WP_Error('clara_upload_failed', $result['error'], ['status' => 400]);
		}

		$attachmentId = wp_insert_attachment([
			'post_mime_type' => $result['type'],
			'post_title' => sanitize_text_field(pathinfo($file['name'], PATHINFO_FILENAME)),
			'post_status' => 'inherit',
			'post_author' => $userId,
		], $result['file'], 0, true);

		if (is_wp_error($attachmentId)) {
			wp_delete_file($result['file']);
			return $attachmentId;
		}

		update_post_meta($attachmentId, self::META, $projectId);

		return [
			'id' => $attachmentId,
			'name' => $file['name'],
			'mime' => $result['type'],
			'size' => $size,
			'url' => $result['url'],
		];
	}

	/**
	 * Usuwa plik tylko, jeśli należy do wskazanego projektu.
	 */
	public static function deleteFile(int $attachmentId, int $projectId): bool
	{
		if ((int) get_post_meta($attachmentId, self::META, true) !== $projectId) {
			return false;
		}

		return (bool) wp_delete_attachment($attachmentId, true);
	}
}
