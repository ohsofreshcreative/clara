<?php

namespace App\Clara;

use WP_Error;

class Access
{
	/**
	 * Uprawnienie do panelu Clara. Filtr pozwala zawęzić dostęp bez zmian w rolach w bazie.
	 */
	public static function capability(): string
	{
		return (string) apply_filters('clara/capability', 'edit_posts');
	}

	public static function canUse(?int $userId = null): bool
	{
		$userId ??= get_current_user_id();

		return $userId > 0 && user_can($userId, self::capability());
	}

	/**
	 * permission_callback dla REST: 401 dla niezalogowanych, 403 dla braku uprawnień.
	 */
	public static function restPermission(): bool|WP_Error
	{
		if (! is_user_logged_in()) {
			return new WP_Error('clara_unauthorized', __('Wymagane zalogowanie.', 'clara'), ['status' => 401]);
		}

		if (! self::canUse()) {
			return new WP_Error('clara_forbidden', __('Brak uprawnień do Clara.', 'clara'), ['status' => 403]);
		}

		return true;
	}
}
