<?php
/**
 * Rewrites external font URLs in page output to the proxy.
 */

if (!defined('ABSPATH')) {
	exit;
}

final class Font_Proxy_Cache_Rewriter {
	/**
	 * Style handles whose source was rewritten during this request.
	 *
	 * @var array<string, bool>
	 */
	private static $proxied_handles = array();

	/**
	 * Registers rewriting hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Late priorities so sources/tags already altered by other plugins (e.g. the Font Awesome
		// plugin adding integrity attributes, WPBakery's local fonts) are seen in their final form.
		add_filter('style_loader_src', array(__CLASS__, 'filter_style_src'), 9999, 2);
		add_filter('style_loader_tag', array(__CLASS__, 'filter_style_tag'), 9999, 2);
		add_filter('wp_resource_hints', array(__CLASS__, 'filter_resource_hints'), 9999, 2);
		add_filter('wp_preload_resources', array(__CLASS__, 'filter_preload_resources'), 9999);
		add_action('template_redirect', array(__CLASS__, 'start_buffer'), 0);
	}

	/**
	 * Returns true when rewriting is enabled. Without a connected persistent object cache nothing
	 * can be cached, so pages keep their original CDN URLs.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return Font_Proxy_Cache_Store::is_available() && (bool) apply_filters('font_proxy_cache_enabled', true);
	}

	/**
	 * Rewrites enqueued stylesheet sources.
	 *
	 * @param string $src    Stylesheet URL (WordPress has already appended "ver").
	 * @param string $handle Style handle.
	 * @return string
	 */
	public static function filter_style_src($src, $handle = '') {
		if (!is_string($src) || '' === $src || !self::is_enabled()) {
			return $src;
		}

		$proxied = Font_Proxy_Cache::proxy_url($src);
		if (null === $proxied) {
			return $src;
		}

		self::$proxied_handles[(string) $handle] = true;
		return $proxied;
	}

	/**
	 * Drops Subresource Integrity from rewritten stylesheet tags: the proxied CSS has its
	 * dependency URLs rewritten, so the upstream hash no longer matches and would block it.
	 *
	 * @param string $tag    Link tag HTML.
	 * @param string $handle Style handle.
	 * @return string
	 */
	public static function filter_style_tag($tag, $handle = '') {
		if (!is_string($tag) || !isset(self::$proxied_handles[(string) $handle])) {
			return $tag;
		}

		return self::strip_integrity($tag);
	}

	/**
	 * Removes dns-prefetch/preconnect hints for hosts that are no longer contacted.
	 *
	 * @param array<int, string|array<string, string>> $urls     Resource hint URLs.
	 * @param string                                   $relation Relation type.
	 * @return array<int, string|array<string, string>>
	 */
	public static function filter_resource_hints($urls, $relation) {
		if (!is_array($urls) || !in_array($relation, array('dns-prefetch', 'preconnect'), true) || !self::is_enabled()) {
			return $urls;
		}

		foreach ($urls as $index => $url) {
			if (self::is_proxied_host(is_array($url) ? (string) ($url['href'] ?? '') : (string) $url)) {
				unset($urls[$index]);
			}
		}

		return array_values($urls);
	}

	/**
	 * Rewrites preload resources (e.g. preloaded font files).
	 *
	 * @param array<int, array<string, string>> $resources Preload resources.
	 * @return array<int, array<string, string>>
	 */
	public static function filter_preload_resources($resources) {
		if (!is_array($resources) || !self::is_enabled()) {
			return $resources;
		}

		foreach ($resources as $index => $resource) {
			if (!is_array($resource) || empty($resource['href'])) {
				continue;
			}

			$proxied = Font_Proxy_Cache::proxy_url((string) $resource['href']);
			if (null !== $proxied) {
				$resources[$index]['href'] = $proxied;
				unset($resources[$index]['integrity']);
			}
		}

		return $resources;
	}

	/**
	 * Buffers front-end HTML to catch hard-coded references that bypass wp_enqueue_style().
	 *
	 * @return void
	 */
	public static function start_buffer() {
		if (!self::is_enabled() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
			return;
		}

		ob_start(array(__CLASS__, 'rewrite_html'));
	}

	/**
	 * Rewrites proxiable URLs inside <link> tags and <style> blocks. Other markup (scripts,
	 * post content mentioning a URL...) is left untouched.
	 *
	 * @param string $html Buffered page HTML.
	 * @return string
	 */
	public static function rewrite_html($html) {
		if (!is_string($html) || '' === $html || !self::is_html_response() || !self::mentions_proxied_host($html)) {
			return $html;
		}

		$rewritten = preg_replace_callback(
			'~<link\b[^>]*>|(<style\b[^>]*>)(.*?)(</style>)~is',
			function ($match) {
				if (!empty($match[1])) {
					return $match[1] . self::rewrite_urls($match[2], false) . $match[3];
				}

				// Hard-coded hints would still open connections to (and leak visitor IPs to) the CDN.
				if (preg_match('~\brel\s*=\s*["\']?\s*(?:dns-prefetch|preconnect)\b~i', $match[0])
					&& preg_match('~\bhref\s*=\s*(["\']?)([^"\'\s>]+)\1~i', $match[0], $href)
					&& self::is_proxied_host(html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
					return '';
				}

				$tag = self::rewrite_urls($match[0], true);
				return $tag === $match[0] ? $tag : self::strip_integrity($tag);
			},
			$html
		);

		return null === $rewritten ? $html : $rewritten;
	}

	/**
	 * Replaces proxiable URLs in a fragment of markup or CSS.
	 *
	 * @param string $text         Markup (attribute context) or raw CSS.
	 * @param bool   $is_attribute Whether URLs are HTML-attribute encoded.
	 * @return string
	 */
	private static function rewrite_urls($text, $is_attribute) {
		$pattern = self::get_url_pattern();
		if ('' === $pattern) {
			return $text;
		}

		$rewritten = preg_replace_callback(
			$pattern,
			function ($match) use ($is_attribute) {
				$url = $is_attribute ? html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8') : $match[0];
				$proxied = Font_Proxy_Cache::proxy_url($url);
				if (null === $proxied) {
					return $match[0];
				}

				return $is_attribute ? esc_url($proxied) : $proxied;
			},
			$text
		);

		return null === $rewritten ? $text : $rewritten;
	}

	/**
	 * Returns the regex matching absolute or protocol-relative URLs on proxied hosts.
	 *
	 * @return string
	 */
	private static function get_url_pattern() {
		$hosts = Font_Proxy_Cache::get_hosts();
		if (empty($hosts)) {
			return '';
		}

		$hosts = implode(
			'|',
			array_map(
				function ($host) {
					return preg_quote($host, '~');
				},
				$hosts
			)
		);

		// Not preceded by URL characters, so URLs nested inside other URLs are left alone.
		return '~(?<![\w.:/%-])(?:https?:)?//(?:' . $hosts . ')(?::\d+)?(?=[/?#])[^\s"\'<>()\\\\`]*~i';
	}

	/**
	 * Returns true when a URL or bare host ("fonts.googleapis.com", as passed to resource hints)
	 * belongs to a proxied host.
	 *
	 * @param string $url URL, protocol-relative URL or bare host.
	 * @return bool
	 */
	private static function is_proxied_host($url) {
		if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
			$url = 'https://' . ltrim($url, '/');
		}

		$host = rtrim(strtolower((string) wp_parse_url($url, PHP_URL_HOST)), '.');
		return '' !== $host && in_array($host, Font_Proxy_Cache::get_hosts(), true);
	}

	/**
	 * Cheap pre-check before running regexes over the whole page.
	 *
	 * @param string $html Page HTML.
	 * @return bool
	 */
	private static function mentions_proxied_host($html) {
		foreach (Font_Proxy_Cache::get_hosts() as $host) {
			if (false !== stripos($html, $host)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns true unless a non-HTML Content-Type was sent.
	 *
	 * @return bool
	 */
	private static function is_html_response() {
		foreach (headers_list() as $header) {
			if (0 === stripos($header, 'content-type:')) {
				return false !== stripos($header, 'text/html');
			}
		}

		return true;
	}

	/**
	 * Removes integrity attributes from a tag.
	 *
	 * @param string $tag Tag HTML.
	 * @return string
	 */
	private static function strip_integrity($tag) {
		return (string) preg_replace('~\s+integrity\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $tag);
	}
}
