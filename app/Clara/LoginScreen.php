<?php

namespace App\Clara;

use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * Ekran logowania w stylu panelu Clara. Zostaje standardowy wp-login.php (nonce, reset hasła,
 * wtyczki 2FA, limity prób) — dokładamy tylko styl, nagłówek i postać.
 */
class LoginScreen
{
	public const ENTRY = 'resources/css/clara-login.css';

	public function register(): void
	{
		add_action('login_head', [$this, 'styles'], 20);
		add_filter('login_body_class', fn (array $classes) => [...$classes, 'clara-login']);
		add_filter('login_headerurl', fn () => home_url('/'));
		add_filter('login_headertext', fn () => 'Clara');
		add_filter('login_title', fn () => __('Zaloguj się · Clara', 'clara'));
		add_action('login_header', [$this, 'intro']);
	}

	public function styles(): void
	{
		try {
			echo Vite::withEntryPoints([self::ENTRY])->toHtml();
		} catch (Throwable) {
			// Brak buildu — zostaje domyślny wygląd WordPressa.
		}
	}

	public function intro(): void
	{
		?>
		<div class="clara-login-art" aria-hidden="true">
			<div class="clara-login-figure"></div>
		</div>
		<div class="clara-login-intro">
			<p class="clara-login-logo">
				<svg viewBox="0 0 40 24" aria-hidden="true" focusable="false"><path d="M2 18c6-12 12-12 18-6s12 6 18-6" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" /></svg>
				Clara
			</p>
			<p class="clara-login-title"><?php echo wp_kses(__('Inspiracje dla Twoich <span>stron</span>', 'clara'), ['span' => []]); ?></p>
			<p class="clara-login-lead"><?php esc_html_e('Zaloguj się, aby generować i zbierać inspiracje sekcji dla swoich projektów.', 'clara'); ?></p>
		</div>
		<?php
	}
}
