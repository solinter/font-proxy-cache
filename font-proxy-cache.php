<?php
/**
 * Plugin Name: Font Proxy Cache
 * Description: Serves external font dependencies (Google Fonts, Font Awesome CDN) from this site through a signed reverse-proxy endpoint backed by a content-addressed store in the persistent object cache (Redis). Requires a persistent object cache; nothing is written to disk.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: font-proxy-cache
 */

if (!defined('ABSPATH')) {
	exit;
}

define('FONT_PROXY_CACHE_VERSION', '1.0.0');
define('FONT_PROXY_CACHE_FILE', __FILE__);

require_once __DIR__ . '/includes/class-font-proxy-cache-url.php';
require_once __DIR__ . '/includes/class-font-proxy-cache-store.php';
require_once __DIR__ . '/includes/class-font-proxy-cache-proxy.php';
require_once __DIR__ . '/includes/class-font-proxy-cache-rewriter.php';

final class Font_Proxy_Cache {
	const ROUTE = 'font-proxy';
	const SECRET_OPTION = 'font_proxy_cache_secret';
	const SIGNATURE_LENGTH = 16;
	const DEFAULT_HOSTS = array('fonts.googleapis.com', 'fonts.gstatic.com', 'use.fontawesome.com');

	/**
	 * Bootstraps plugin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		register_activation_hook(FONT_PROXY_CACHE_FILE, array(__CLASS__, 'activate'));

		// Proxy requests are answered before the theme loads, so filters that affect the
		// endpoint (hosts, TTLs...) must be registered from a plugin or mu-plugin.
		add_action('plugins_loaded', array('Font_Proxy_Cache_Proxy', 'maybe_serve'), 0);
		Font_Proxy_Cache_Rewriter::init();

		if (is_admin()) {
			require_once __DIR__ . '/includes/class-font-proxy-cache-admin.php';
			Font_Proxy_Cache_Admin::init();
		}

		if (defined('WP_CLI') && WP_CLI) {
			require_once __DIR__ . '/includes/class-font-proxy-cache-cli.php';
			WP_CLI::add_command('font-proxy-cache', 'Font_Proxy_Cache_CLI');
		}
	}

	/**
	 * Refuses activation without a connected persistent object cache, where everything is stored.
	 *
	 * @return void
	 */
	public static function activate() {
		$reason = Font_Proxy_Cache_Store::get_unavailable_reason();
		if ('' === $reason) {
			return;
		}

		deactivate_plugins(plugin_basename(FONT_PROXY_CACHE_FILE));
		wp_die(
			esc_html($reason) . '<br />' . esc_html__('Font Proxy Cache stores everything in the persistent object cache and requires Redis Object Cache (or another persistent object cache drop-in) to be enabled and connected. The plugin was not activated.', 'font-proxy-cache'),
			esc_html__('Font Proxy Cache not activated', 'font-proxy-cache'),
			array('back_link' => true)
		);
	}

	/**
	 * Returns the upstream hosts whose files are served through the proxy.
	 *
	 * @return string[]
	 */
	public static function get_hosts() {
		$hosts = (array) apply_filters('font_proxy_cache_hosts', self::DEFAULT_HOSTS);
		$hosts = array_map(
			function ($host) {
				return strtolower(trim((string) $host));
			},
			$hosts
		);

		return array_values(array_unique(array_filter($hosts)));
	}

	/**
	 * Returns query arguments that never change the upstream response (cache busters added by WordPress).
	 *
	 * @return string[]
	 */
	public static function get_ignored_query_args() {
		return array_map('strtolower', (array) apply_filters('font_proxy_cache_ignored_query_args', array('ver')));
	}

	/**
	 * Returns the request path prefix handled by the proxy, e.g. "/font-proxy/".
	 *
	 * @return string
	 */
	public static function get_route_path() {
		$home_path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);

		return '/' . ltrim(trailingslashit($home_path), '/') . self::ROUTE . '/';
	}

	/**
	 * Returns the absolute base URL used in page markup.
	 *
	 * @return string
	 */
	public static function get_base_url() {
		return trailingslashit((string) apply_filters('font_proxy_cache_base_url', home_url(self::ROUTE . '/')));
	}

	/**
	 * Returns the base URL written into cached CSS. Root-relative when the proxy lives on the site
	 * host, so cached stylesheets stay valid across http/https and host aliases.
	 *
	 * @return string
	 */
	public static function get_css_base_url() {
		$base = self::get_base_url();
		$base_host = (string) wp_parse_url($base, PHP_URL_HOST);
		$home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

		if ('' !== $base_host && strtolower($base_host) === strtolower($home_host)) {
			return (string) wp_parse_url($base, PHP_URL_PATH);
		}

		return $base;
	}

	/**
	 * Fingerprint of everything that affects rewritten CSS; cached CSS built under a different
	 * fingerprint is re-processed from its pristine upstream copy.
	 *
	 * @return string
	 */
	public static function get_css_context() {
		return substr(hash('sha256', FONT_PROXY_CACHE_VERSION . '|' . self::get_css_base_url() . '|' . self::sign('css-context')), 0, 16);
	}

	/**
	 * Returns true when a canonical URL may be fetched through the proxy.
	 *
	 * @param array<string, string> $parts Canonical URL parts from Font_Proxy_Cache_Url::canonicalize().
	 * @return bool
	 */
	public static function is_proxiable(array $parts) {
		if (!in_array($parts['host'], self::get_hosts(), true) || '/' === $parts['path']) {
			return false;
		}

		// Stylesheets and fonts only; extensionless paths (e.g. Google's /css2) are validated by content type.
		$extension = strtolower((string) pathinfo($parts['path'], PATHINFO_EXTENSION));
		$allowed = '' === $extension || isset(Font_Proxy_Cache_Proxy::TYPES[$extension]);

		return (bool) apply_filters('font_proxy_cache_is_proxiable', $allowed, $parts['url']);
	}

	/**
	 * Returns the proxy URL for an upstream URL, or null when it is not proxied.
	 *
	 * @param string $url     Upstream URL (absolute or protocol-relative).
	 * @param bool   $for_css Whether the URL is written into cached CSS.
	 * @return string|null
	 */
	public static function proxy_url($url, $for_css = false) {
		$parts = Font_Proxy_Cache_Url::canonicalize($url);

		return null === $parts ? null : self::build_proxy_url($parts, $for_css);
	}

	/**
	 * Builds the signed proxy URL for canonical URL parts. Every spelling of an upstream URL
	 * canonicalizes to the same parts, so it always gets the same proxy URL.
	 *
	 * @param array<string, string> $parts   Canonical URL parts.
	 * @param bool                  $for_css Whether the URL is written into cached CSS.
	 * @return string|null
	 */
	public static function build_proxy_url(array $parts, $for_css = false) {
		if (!self::is_proxiable($parts)) {
			return null;
		}

		$base = $for_css ? self::get_css_base_url() : self::get_base_url();
		$query = '' !== $parts['query'] ? '?' . $parts['query'] : '';

		return $base . self::sign($parts['url']) . '/' . $parts['host'] . $parts['path'] . $query . $parts['fragment'];
	}

	/**
	 * Signs a value with the site secret (base64url, truncated).
	 *
	 * @param string $value Value to sign.
	 * @return string
	 */
	public static function sign($value) {
		$raw = hash_hmac('sha256', (string) $value, self::get_secret(), true);

		return substr(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), 0, self::SIGNATURE_LENGTH);
	}

	/**
	 * Returns the signing secret, generating it on first use.
	 *
	 * @return string
	 */
	private static function get_secret() {
		static $secret = null;

		if (null !== $secret) {
			return $secret;
		}

		if (defined('FONT_PROXY_CACHE_SECRET') && '' !== (string) FONT_PROXY_CACHE_SECRET) {
			$secret = (string) FONT_PROXY_CACHE_SECRET;
			return $secret;
		}

		$stored = get_option(self::SECRET_OPTION, '');
		if (!is_string($stored) || strlen($stored) < 32) {
			$generated = bin2hex(random_bytes(32));
			if (add_option(self::SECRET_OPTION, $generated, '', true)) {
				$stored = $generated;
			} else {
				// Another request created it concurrently; bypass the stale option caches.
				wp_cache_delete('notoptions', 'options');
				wp_cache_delete(self::SECRET_OPTION, 'options');
				$stored = get_option(self::SECRET_OPTION, '');
			}
		}

		$secret = is_string($stored) && strlen($stored) >= 32 ? $stored : hash_hmac('sha256', 'font-proxy-cache', wp_salt('secure'));

		return $secret;
	}
}

Font_Proxy_Cache::init();
