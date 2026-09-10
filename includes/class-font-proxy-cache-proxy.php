<?php
/**
 * Reverse-proxy endpoint.
 *
 * Request: /font-proxy/<signature>/<upstream-host>/<upstream-path>[?<upstream-query>]
 *
 * On a miss the upstream file is fetched, CSS is rewritten so every dependency it references
 * (fonts, @import) also points at this endpoint, and the result is stored in the object cache.
 */

if (!defined('ABSPATH')) {
	exit;
}

final class Font_Proxy_Cache_Proxy {
	/**
	 * Served content types by file extension.
	 */
	const TYPES = array(
		'css' => 'text/css',
		'woff2' => 'font/woff2',
		'woff' => 'font/woff',
		'ttf' => 'font/ttf',
		'otf' => 'font/otf',
		'eot' => 'application/vnd.ms-fontobject',
		'svg' => 'image/svg+xml',
	);

	/**
	 * Legacy upstream content types mapped to their standard equivalent.
	 */
	const TYPE_ALIASES = array(
		'application/font-woff' => 'font/woff',
		'application/x-font-woff' => 'font/woff',
		'application/font-woff2' => 'font/woff2',
		'application/x-font-woff2' => 'font/woff2',
		'application/x-font-ttf' => 'font/ttf',
		'application/x-font-truetype' => 'font/ttf',
		'application/font-sfnt' => 'font/ttf',
		'font/sfnt' => 'font/ttf',
		'application/x-font-otf' => 'font/otf',
		'application/x-font-opentype' => 'font/otf',
	);

	/**
	 * Upstream content types resolved from the file extension instead.
	 */
	const GENERIC_TYPES = array('', 'application/octet-stream', 'binary/octet-stream', 'text/plain');

	/**
	 * Google Fonts only returns woff2 with unicode-range subsets to modern browsers, so upstream
	 * requests use one fixed modern UA: a single CSS variant is cached and served to everyone.
	 */
	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

	const MAX_BYTES = 10485760;
	const RETRY_AFTER = 300;

	/**
	 * Serves the request when it targets the proxy route.
	 *
	 * @return void
	 */
	public static function maybe_serve() {
		$request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
		$route = Font_Proxy_Cache::get_route_path();
		if (0 !== strpos($request_uri, $route)) {
			return;
		}

		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';
		if ('GET' !== $method && 'HEAD' !== $method) {
			self::send_status(405);
		}

		$parts = self::parse_request(substr($request_uri, strlen($route)));
		if (null === $parts) {
			self::send_status(404);
		}

		// Without a persistent object cache nothing can be cached (and nothing is ever written
		// to disk): pages cached before it went away still get their fonts, from the CDN.
		$result = Font_Proxy_Cache_Store::is_available()
			? self::obtain($parts['url'])
			: new WP_Error('font_proxy_cache_unavailable', Font_Proxy_Cache_Store::get_unavailable_reason());

		if (is_wp_error($result)) {
			// Nothing cached and upstream unavailable: fall back to the CDN instead of breaking the page.
			nocache_headers();
			header('X-Font-Proxy-Cache: BYPASS');
			header('X-Font-Proxy-Cache-Error: ' . self::describe_error($result));
			wp_redirect($parts['url'], 302, 'Font Proxy Cache');
			exit;
		}

		self::send($result['ref'], $result['body'], $result['status'], 'HEAD' === $method);
	}

	/**
	 * Returns the cached file for a canonical URL, fetching or refreshing it as needed.
	 *
	 * @param string $url            Canonical upstream URL.
	 * @param bool   $ignore_backoff Fetch even if a recent fetch failed.
	 * @return array{ref: array<string, mixed>, body: string, status: string}|WP_Error
	 */
	public static function obtain($url, $ignore_backoff = false) {
		$ref = Font_Proxy_Cache_Store::get_ref($url);
		$body = null === $ref ? null : Font_Proxy_Cache_Store::get_object($ref['object']);
		$now = time();

		// A ref whose object was evicted from the cache is handled as a miss.
		if (null !== $body) {
			$stale_context = self::is_css($ref) && Font_Proxy_Cache::get_css_context() !== (string) ($ref['context'] ?? '');
			if ($stale_context) {
				$rebuilt = self::rebuild($url, $ref);
				if (null !== $rebuilt) {
					return $rebuilt + array('status' => 'REBUILT');
				}
			}

			$ttl = self::get_ttl($ref);
			$fresh = 0 === $ttl || $now - (int) $ref['checked'] < $ttl;
			$backing_off = !$ignore_backoff && $now < (int) ($ref['retry_at'] ?? 0);
			if ((!$stale_context && $fresh) || $backing_off) {
				return array('ref' => $ref, 'body' => $body, 'status' => 'HIT');
			}

			$fetched = self::fetch($url, $stale_context ? null : $ref);
			if (!is_wp_error($fetched) && 304 === $fetched['code']) {
				unset($ref['retry_at']);
				$ref['checked'] = $now;
				Font_Proxy_Cache_Store::put_ref($url, $ref);
				Font_Proxy_Cache_Store::clear_failure($url);
				return array('ref' => $ref, 'body' => $body, 'status' => 'REVALIDATED');
			}

			$stored = is_wp_error($fetched) ? $fetched : self::store($url, $fetched);
			if (!is_wp_error($stored)) {
				Font_Proxy_Cache_Store::clear_failure($url);
				return $stored + array('status' => 'REFRESHED');
			}

			// Keep serving the stale copy; retry later.
			self::record_failure($url, $stored);
			$ref['retry_at'] = $now + self::RETRY_AFTER;
			Font_Proxy_Cache_Store::put_ref($url, $ref);
			return array('ref' => $ref, 'body' => $body, 'status' => 'STALE');
		}

		$failure = Font_Proxy_Cache_Store::get_failures()[$url] ?? null;
		if (!$ignore_backoff && is_array($failure) && $now < (int) $failure['retry_at']) {
			// Replay the recorded failure instead of hammering the upstream.
			return new WP_Error($failure['code'], $failure['message'], array('status' => (int) $failure['status'], 'retry_at' => (int) $failure['retry_at']));
		}

		$fetched = self::fetch($url, null);
		$stored = is_wp_error($fetched) ? $fetched : self::store($url, $fetched);
		if (is_wp_error($stored)) {
			self::record_failure($url, $stored);
			return $stored;
		}

		if (null !== $failure) {
			Font_Proxy_Cache_Store::clear_failure($url);
		}

		return $stored + array('status' => 'MISS');
	}

	/**
	 * Rewrites every url()/@import reference in a stylesheet: proxiable dependencies are pointed
	 * at the proxy, other relative references are made absolute (the CSS no longer lives upstream).
	 *
	 * @param string   $css      Upstream CSS.
	 * @param string   $base_url Canonical URL the CSS was fetched from.
	 * @param string[] $deps     Receives the canonical URLs of proxied dependencies.
	 * @return string
	 */
	public static function rewrite_css($css, $base_url, &$deps = array()) {
		$deps = array();
		$replace = function ($reference) use ($base_url, &$deps) {
			$reference = trim($reference);
			if ('' === $reference || '#' === $reference[0] || preg_match('~^(?:data|blob|about|javascript):~i', $reference)) {
				return null;
			}

			$absolute = Font_Proxy_Cache_Url::resolve($base_url, $reference);
			$parts = null === $absolute ? null : Font_Proxy_Cache_Url::canonicalize($absolute);
			if (null === $parts) {
				return $absolute;
			}

			$proxied = Font_Proxy_Cache::build_proxy_url($parts, true);
			if (null === $proxied) {
				return $parts['url'] . $parts['fragment'];
			}

			$deps[] = $parts['url'];
			return $proxied;
		};

		$rewritten = preg_replace_callback(
			'~@import\s+(["\'])(.*?)\1~i',
			function ($match) use ($replace) {
				$new = $replace($match[2]);
				return null === $new ? $match[0] : '@import ' . self::css_string($new, $match[1]);
			},
			$css
		);
		$css = null === $rewritten ? $css : $rewritten;

		$rewritten = preg_replace_callback(
			'~\burl\(\s*(["\']?)(.*?)\1\s*\)~is',
			function ($match) use ($replace) {
				$new = $replace($match[2]);
				return null === $new ? $match[0] : 'url(' . self::css_string($new, $match[1]) . ')';
			},
			$css
		);
		$css = null === $rewritten ? $css : $rewritten;

		// Keep source maps working: they are not proxied, so point them at the upstream copy.
		$rewritten = preg_replace_callback(
			'~(/\*[#@]\s*sourceMappingURL=)([^\s*]+)~',
			function ($match) use ($base_url) {
				$absolute = Font_Proxy_Cache_Url::resolve($base_url, $match[2]);
				return $match[1] . (null === $absolute ? $match[2] : $absolute);
			},
			$css
		);
		$css = null === $rewritten ? $css : $rewritten;

		$deps = array_values(array_unique($deps));
		return $css;
	}

	/**
	 * Parses "<signature>/<host>/<path>[?query]" and verifies the signature.
	 *
	 * @param string $tail Request URI after the route prefix.
	 * @return array<string, string>|null Canonical URL parts.
	 */
	private static function parse_request($tail) {
		$query = '';
		$query_pos = strpos($tail, '?');
		if (false !== $query_pos) {
			$query = substr($tail, $query_pos + 1);
			$tail = substr($tail, 0, $query_pos);
		}

		if (!preg_match('~^([A-Za-z0-9_-]{' . Font_Proxy_Cache::SIGNATURE_LENGTH . '})/([A-Za-z0-9.-]+)(/[^#]*)$~', $tail, $match)) {
			return null;
		}

		$parts = Font_Proxy_Cache_Url::canonicalize('https://' . $match[2] . $match[3] . ('' !== $query ? '?' . $query : ''));
		if (null === $parts || !Font_Proxy_Cache::is_proxiable($parts)) {
			return null;
		}

		// The signature covers the canonical URL, so only URLs this site generated can be fetched.
		return hash_equals(Font_Proxy_Cache::sign($parts['url']), $match[1]) ? $parts : null;
	}

	/**
	 * Re-processes cached CSS from its pristine upstream copy (after a base URL/secret/plugin change).
	 *
	 * @param string               $url Canonical upstream URL.
	 * @param array<string, mixed> $ref Current ref record.
	 * @return array{ref: array<string, mixed>, body: string}|null
	 */
	private static function rebuild($url, array $ref) {
		$source = empty($ref['source']) ? null : Font_Proxy_Cache_Store::get_object($ref['source']);
		if (null === $source) {
			return null;
		}

		$stored = self::store(
			$url,
			array(
				'body' => $source,
				'type' => $ref['type'],
				'etag' => (string) ($ref['etag'] ?? ''),
				'last_modified' => (string) ($ref['last_modified'] ?? ''),
				'fetched' => (int) $ref['fetched'],
				'checked' => (int) $ref['checked'],
			)
		);

		return is_wp_error($stored) ? null : $stored;
	}

	/**
	 * Stores a fetched body and its ref record. CSS is rewritten before it is cached.
	 *
	 * @param string               $url     Canonical upstream URL.
	 * @param array<string, mixed> $fetched Fetched response (body, type, etag, last_modified).
	 * @return array{ref: array<string, mixed>, body: string}|WP_Error
	 */
	private static function store($url, array $fetched) {
		$now = time();
		$body = (string) $fetched['body'];
		$ref = array(
			'url' => $url,
			'type' => $fetched['type'],
			'object' => '',
			'source' => '',
			'size' => 0,
			'source_size' => 0,
			'context' => '',
			'deps' => array(),
			'etag' => $fetched['etag'],
			'last_modified' => $fetched['last_modified'],
			'fetched' => isset($fetched['fetched']) ? (int) $fetched['fetched'] : $now,
			'checked' => isset($fetched['checked']) ? (int) $fetched['checked'] : $now,
		);

		if (self::is_css($ref)) {
			// Keep the pristine upstream CSS so it can be re-processed without refetching.
			$ref['source'] = (string) Font_Proxy_Cache_Store::put_object($body);
			$ref['source_size'] = strlen($body);
			$body = self::rewrite_css($body, $url, $ref['deps']);
			$ref['context'] = Font_Proxy_Cache::get_css_context();
		}

		$ref['object'] = (string) Font_Proxy_Cache_Store::put_object($body);
		$ref['size'] = strlen($body);

		// Objects replaced by this ref are garbage-collected when it is indexed.
		if ('' === $ref['object'] || (self::is_css($ref) && '' === $ref['source']) || !Font_Proxy_Cache_Store::put_ref($url, $ref)) {
			return new WP_Error('font_proxy_cache_storage', __('Could not write the file to the object cache (is Redis reachable and not out of memory?).', 'font-proxy-cache'));
		}

		return array('ref' => $ref, 'body' => $body);
	}

	/**
	 * Fetches an upstream URL, conditionally when a previous ref is given.
	 *
	 * @param string                    $url      Canonical upstream URL.
	 * @param array<string, mixed>|null $previous Previous ref record for revalidation.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function fetch($url, $previous) {
		$headers = array('Accept' => '*/*');
		if (is_array($previous)) {
			if (!empty($previous['etag'])) {
				$headers['If-None-Match'] = $previous['etag'];
			}
			if (!empty($previous['last_modified'])) {
				$headers['If-Modified-Since'] = $previous['last_modified'];
			}
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => (int) apply_filters('font_proxy_cache_timeout', 10),
				'redirection' => 3,
				'user-agent' => (string) apply_filters('font_proxy_cache_user_agent', self::USER_AGENT, $url),
				'headers' => $headers,
				'limit_response_size' => self::MAX_BYTES,
			)
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if (304 === $code && is_array($previous)) {
			return array('code' => 304);
		}

		if (200 !== $code) {
			/* translators: %d: HTTP status code. */
			$message = sprintf(__('Upstream returned HTTP %d.', 'font-proxy-cache'), $code);
			$mitigated = self::get_header($response, 'cf-mitigated');
			if ('' !== $mitigated) {
				/* translators: %s: value of Cloudflare's cf-mitigated header. */
				$message .= ' ' . sprintf(__('Blocked by Cloudflare bot protection (cf-mitigated: %s).', 'font-proxy-cache'), $mitigated);
			}

			return new WP_Error('font_proxy_cache_http', $message, array('status' => $code));
		}

		$body = (string) wp_remote_retrieve_body($response);
		if (strlen($body) >= self::MAX_BYTES) {
			return new WP_Error('font_proxy_cache_size', __('Upstream response is too large.', 'font-proxy-cache'));
		}

		$content_type = self::get_header($response, 'content-type');
		$type = self::detect_type($url, $content_type, $body);
		if (null === $type) {
			/* translators: %s: upstream Content-Type header. */
			return new WP_Error('font_proxy_cache_type', sprintf(__('Upstream response is not a supported stylesheet or font (Content-Type: %s).', 'font-proxy-cache'), '' !== $content_type ? $content_type : 'none'));
		}

		return array(
			'code' => 200,
			'body' => $body,
			'type' => $type,
			'etag' => self::get_header($response, 'etag'),
			'last_modified' => self::get_header($response, 'last-modified'),
		);
	}

	/**
	 * Determines the served content type, rejecting anything that is not a stylesheet or font
	 * (e.g. an HTML error page returned with status 200).
	 *
	 * @param string $url    Canonical upstream URL.
	 * @param string $header Upstream Content-Type header.
	 * @param string $body   Upstream body.
	 * @return string|null
	 */
	private static function detect_type($url, $header, $body) {
		$header = strtolower(trim(explode(';', $header)[0]));
		$header = self::TYPE_ALIASES[$header] ?? $header;
		$extension = strtolower((string) pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

		$type = null;
		if (in_array($header, self::TYPES, true)) {
			$type = $header;
		} elseif (in_array($header, self::GENERIC_TYPES, true) && isset(self::TYPES[$extension])) {
			$type = self::TYPES[$extension];
		}

		return null !== $type && self::body_matches_type($type, $body) ? $type : null;
	}

	/**
	 * Sanity-checks a body against its content type.
	 *
	 * @param string $type Content type.
	 * @param string $body Body.
	 * @return bool
	 */
	private static function body_matches_type($type, $body) {
		if ('' === $body) {
			return false;
		}

		$magic = substr($body, 0, 4);
		switch ($type) {
			case 'font/woff2':
				return 'wOF2' === $magic;
			case 'font/woff':
				return 'wOFF' === $magic;
			case 'font/ttf':
			case 'font/otf':
				return in_array($magic, array("\x00\x01\x00\x00", 'true', 'OTTO', 'ttcf', 'typ1'), true);
			case 'image/svg+xml':
				return false !== stripos($body, '<svg');
			case 'text/css':
				return !preg_match('~^\s*<(?:!doctype\s+html|html)~i', $body);
			default:
				return true;
		}
	}

	/**
	 * Sends a cached file.
	 *
	 * @param array<string, mixed> $ref       Ref record.
	 * @param string               $body      File body.
	 * @param string               $status    Cache status for the X-Font-Proxy-Cache header.
	 * @param bool                 $head_only Whether to omit the body.
	 * @return void
	 */
	private static function send(array $ref, $body, $status, $head_only) {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		// The object id is a content hash, i.e. a perfect ETag (mod_deflate may append "-gzip").
		$etag = substr($ref['object'], 0, 32);

		header_remove('Set-Cookie');
		header_remove('Pragma');
		header_remove('Expires');
		header('Content-Type: ' . $ref['type'] . (self::is_css($ref) ? '; charset=utf-8' : ''));
		header('Cache-Control: ' . self::get_cache_control($ref));
		header('ETag: "' . $etag . '"');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) $ref['fetched']) . ' GMT');
		header('Access-Control-Allow-Origin: *');
		header('Cross-Origin-Resource-Policy: cross-origin');
		header('X-Content-Type-Options: nosniff');
		header("Content-Security-Policy: default-src 'none'; sandbox");
		header('X-Font-Proxy-Cache: ' . $status);

		$if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string) wp_unslash($_SERVER['HTTP_IF_NONE_MATCH']) : '';
		if ('' !== $if_none_match && false !== strpos($if_none_match, $etag)) {
			status_header(304);
			exit;
		}

		status_header(200);
		header('Content-Length: ' . strlen($body));
		if (!$head_only) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Stylesheet/font bytes, validated on fetch.
		}
		exit;
	}

	/**
	 * Sends a bodyless error status and stops.
	 *
	 * @param int $code HTTP status code.
	 * @return void
	 */
	private static function send_status($code) {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		status_header($code);
		nocache_headers();
		header('Content-Type: text/plain; charset=utf-8');
		header('X-Robots-Tag: noindex');
		echo esc_html(get_status_header_desc($code));
		exit;
	}

	/**
	 * Records an upstream failure (shown on the Tools page, and replayed until the retry time).
	 *
	 * @param string   $url   Canonical upstream URL.
	 * @param WP_Error $error Failure.
	 * @return void
	 */
	private static function record_failure($url, WP_Error $error) {
		$data = $error->get_error_data();

		Font_Proxy_Cache_Store::record_failure(
			$url,
			array(
				'code' => (string) $error->get_error_code(),
				'message' => $error->get_error_message(),
				'status' => is_array($data) && isset($data['status']) ? (int) $data['status'] : 0,
				'time' => time(),
				'retry_at' => time() + self::RETRY_AFTER,
			)
		);
	}

	/**
	 * Describes a failure for the X-Font-Proxy-Cache-Error header. Codes and status only: the
	 * full message may reveal network details and is shown on the Tools page instead.
	 *
	 * @param WP_Error $error Failure.
	 * @return string
	 */
	private static function describe_error(WP_Error $error) {
		$data = $error->get_error_data();
		$description = (string) $error->get_error_code();

		if (is_array($data) && !empty($data['status'])) {
			$description .= ' ' . (int) $data['status'];
		}
		if (is_array($data) && !empty($data['retry_at'])) {
			$description .= sprintf('; retry in %ds', max(0, (int) $data['retry_at'] - time()));
		}

		return $description;
	}

	/**
	 * Returns the refresh interval of a ref (0 = never refresh).
	 *
	 * Fonts are immutable (versioned URLs); stylesheets such as Google's /css can change.
	 *
	 * @param array<string, mixed> $ref Ref record.
	 * @return int
	 */
	private static function get_ttl(array $ref) {
		$ttl = self::is_css($ref) ? WEEK_IN_SECONDS : 0;

		return max(0, (int) apply_filters('font_proxy_cache_ttl', $ttl, $ref['type'], $ref['url']));
	}

	/**
	 * Returns the Cache-Control header sent to browsers.
	 *
	 * @param array<string, mixed> $ref Ref record.
	 * @return string
	 */
	private static function get_cache_control(array $ref) {
		$value = self::is_css($ref) ? 'public, max-age=86400, stale-while-revalidate=604800' : 'public, max-age=31536000, immutable';

		return (string) apply_filters('font_proxy_cache_cache_control', $value, $ref['type'], $ref['url']);
	}

	/**
	 * Returns true for stylesheet refs.
	 *
	 * @param array<string, mixed> $ref Ref record.
	 * @return bool
	 */
	private static function is_css(array $ref) {
		return 'text/css' === $ref['type'];
	}

	/**
	 * Formats a URL as a CSS url()/@import value, quoting when required.
	 *
	 * @param string $value URL.
	 * @param string $quote Original quote character ('' when unquoted).
	 * @return string
	 */
	private static function css_string($value, $quote) {
		if ('' === $quote) {
			if (!preg_match('~[\s"\'()\\\\]~', $value)) {
				return $value;
			}
			$quote = '"';
		}

		return $quote . addcslashes($value, $quote . '\\') . $quote;
	}

	/**
	 * Returns a single response header value.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @param string         $name     Header name.
	 * @return string
	 */
	private static function get_header($response, $name) {
		$value = wp_remote_retrieve_header($response, $name);
		if (is_array($value)) {
			$value = end($value);
		}

		return trim((string) $value);
	}
}
