<?php

namespace App\Clara\Inspirations;

use App\Clara\Support\Validator;
use WP_Error;

/**
 * Wyszukiwanie inspiracji przez adapter: walidacja zapytania, limit zapytań na użytkownika, cache
 * wyników i sanityzacja odpowiedzi dostawcy. Każdy wynik dostaje token — zapis do kolekcji przyjmuje
 * wyłącznie token, więc dane (w tym licencja) pochodzą z serwera, a nie od klienta.
 */
class InspirationSearch
{
	public const PER_PAGE = 20;

	public const MAX_PAGE = 10;

	private const RATE_LIMIT = 20;

	private const RATE_WINDOW = 60;

	private const CACHE_TTL = HOUR_IN_SECONDS;

	private const TOKEN_TTL = 2 * HOUR_IN_SECONDS;

	public static function provider(): ?SearchProvider
	{
		// Stała ma pierwszeństwo; opcja pozwala testom E2E chwilowo przełączyć się na mock.
		$name = defined('CLARA_SEARCH_PROVIDER') ? (string) CLARA_SEARCH_PROVIDER : (string) get_option('clara_search_provider', 'openverse');
		$default = match ($name) {
			'mock' => new MockSearchProvider(),
			'openverse' => new OpenverseProvider(),
			default => null,
		};
		$provider = apply_filters('clara/search_provider', $default);

		return $provider instanceof SearchProvider ? $provider : null;
	}

	public static function search(array $params, int $userId): array|WP_Error
	{
		$v = new Validator();
		$query = $v->text('q', $params['q'] ?? '', 200, true);
		if ($query !== '' && mb_strlen($query) < 2) {
			$v->fail('q', 'Wpisz co najmniej 2 znaki.');
		}
		$page = (int) ($params['page'] ?? 1);
		if ($page < 1 || $page > self::MAX_PAGE) {
			$v->fail('page', sprintf('Strona od 1 do %d.', self::MAX_PAGE));
		}
		$perPage = (int) ($params['per_page'] ?? self::PER_PAGE);
		if ($perPage < 1 || $perPage > self::PER_PAGE) {
			$v->fail('per_page', sprintf('Od 1 do %d wyników na stronę.', self::PER_PAGE));
		}
		$filters = ['commercial' => filter_var($params['commercial'] ?? false, FILTER_VALIDATE_BOOLEAN)];

		if (! $v->ok()) {
			return $v->error();
		}

		$provider = self::provider();
		if (! $provider) {
			return new WP_Error('clara_search_unconfigured', __('Wyszukiwarka inspiracji nie jest skonfigurowana.', 'clara'), ['status' => 409]);
		}

		// Mock nie korzysta z tłumaczenia (testy bez kosztów i bez sieci).
		$searched = $provider->isMock() ? $query : QueryTranslator::toEnglish($query);
		$cacheKey = 'clara_search_' . md5(wp_json_encode([$provider->id(), mb_strtolower($searched), $page, $perPage, $filters]));
		$result = get_transient($cacheKey);

		if (! is_array($result)) {
			if (! self::allow($userId)) {
				return new WP_Error('clara_search_rate_limited', sprintf(__('Za dużo wyszukiwań — limit %d na minutę.', 'clara'), self::RATE_LIMIT), ['status' => 429]);
			}

			$result = $provider->search($searched, $page, $perPage, $filters);
			if (is_wp_error($result)) {
				return $result;
			}

			$result = ['items' => array_values(array_filter(array_map([self::class, 'sanitize'], $result['items']))), 'total' => max(0, (int) $result['total'])];
			set_transient($cacheKey, $result, self::CACHE_TTL);
		}

		$items = array_map(function ($item) use ($provider, $userId, $query) {
			$token = md5($provider->id() . '|' . $item['providerId'] . '|' . $userId);
			set_transient('clara_insp_' . $token, ['userId' => $userId, 'provider' => $provider->id(), 'query' => $query, 'item' => $item], self::TOKEN_TTL);
			return ['token' => $token] + $item;
		}, $result['items']);

		return [
			'provider' => $provider->id(),
			'providerLabel' => $provider->label(),
			'mock' => $provider->isMock(),
			'query' => $query,
			'searchedQuery' => $searched,
			'page' => $page,
			'perPage' => $perPage,
			'total' => $result['total'],
			'items' => $items,
		];
	}

	/**
	 * Wynik wyszukiwania po tokenie — tylko dla użytkownika, który go wyszukał.
	 */
	public static function resolve(string $token, int $userId): ?array
	{
		if (! preg_match('/^[0-9a-f]{32}$/', $token)) {
			return null;
		}

		$entry = get_transient('clara_insp_' . $token);

		return is_array($entry) && (int) $entry['userId'] === $userId ? $entry : null;
	}

	/**
	 * Wynik bez poprawnego linku źródłowego jest odrzucany; pozostałe pola są przycinane i oczyszczane.
	 */
	private static function sanitize(array $item): ?array
	{
		$v = new Validator();
		$source = $v->url('sourceUrl', $item['sourceUrl'] ?? '');
		if (! $v->ok() || $source === '') {
			return null;
		}

		$url = function ($value) {
			$v = new Validator();
			$url = $v->url('u', $value);
			return $v->ok() ? $url : '';
		};
		$text = fn ($value, $max) => mb_substr(sanitize_text_field((string) $value), 0, $max);
		$license = is_array($item['license'] ?? null) && ($item['license']['code'] ?? '') !== '' ? [
			'code' => $text($item['license']['code'], 40),
			'version' => $text($item['license']['version'] ?? '', 20),
			'name' => $text($item['license']['name'] ?? '', 80),
			'url' => $url($item['license']['url'] ?? ''),
		] : null;

		return [
			'providerId' => $text($item['providerId'] ?? '', 191),
			'title' => $text($item['title'] ?? '', 300),
			'sourceUrl' => $source,
			'thumbnailUrl' => $url($item['thumbnailUrl'] ?? ''),
			'creator' => $text($item['creator'] ?? '', 200),
			'creatorUrl' => $url($item['creatorUrl'] ?? ''),
			'sourceName' => $text($item['sourceName'] ?? '', 100),
			'indexedAt' => $text($item['indexedAt'] ?? '', 40),
			'width' => isset($item['width']) ? (int) $item['width'] : null,
			'height' => isset($item['height']) ? (int) $item['height'] : null,
			'license' => $license,
		];
	}

	private static function allow(int $userId): bool
	{
		$key = 'clara_search_rl_' . $userId;
		$now = time();
		$hits = array_filter((array) get_transient($key), fn ($t) => $t > $now - self::RATE_WINDOW);

		if (count($hits) >= self::RATE_LIMIT) {
			return false;
		}

		$hits[] = $now;
		set_transient($key, array_values($hits), self::RATE_WINDOW);

		return true;
	}
}
