<?php
/**
 * URL canonicalization and resolution.
 */

if (!defined('ABSPATH')) {
	exit;
}

final class Font_Proxy_Cache_Url {
	/**
	 * Canonicalizes an absolute or protocol-relative URL so every spelling of the same upstream
	 * resource maps to a single identifier.
	 *
	 * Normalizes: scheme (https), host case and trailing dot, default ports, dot segments,
	 * percent-encoding, query argument order and encoding, and drops ignored cache-busting
	 * arguments such as WordPress' "ver". The fragment is not part of the identity and is
	 * returned separately so it can be re-attached (e.g. "#iefix", "svg#fontawesome").
	 *
	 * @param string $url URL to canonicalize.
	 * @return array{url: string, host: string, path: string, query: string, fragment: string}|null
	 */
	public static function canonicalize($url) {
		$url = trim((string) $url);
		if (0 === strpos($url, '//')) {
			$url = 'https:' . $url;
		}

		$parts = wp_parse_url($url);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return null;
		}

		$scheme = strtolower($parts['scheme']);
		if (('http' !== $scheme && 'https' !== $scheme) || isset($parts['user']) || isset($parts['pass'])) {
			return null;
		}

		if (isset($parts['port']) && !in_array((int) $parts['port'], array(80, 443), true)) {
			return null;
		}

		$host = rtrim(strtolower($parts['host']), '.');
		if (!preg_match('~^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~', $host)) {
			return null;
		}

		$path = self::normalize_path(isset($parts['path']) ? $parts['path'] : '/');
		$query = isset($parts['query']) ? self::normalize_query($parts['query']) : '';
		$fragment = isset($parts['fragment']) ? preg_replace('~[^A-Za-z0-9._\~!$*+,;=:@/?%-]~', '', $parts['fragment']) : '';

		return array(
			'url' => 'https://' . $host . $path . ('' !== $query ? '?' . $query : ''),
			'host' => $host,
			'path' => $path,
			'query' => $query,
			'fragment' => '' !== $fragment ? '#' . $fragment : '',
		);
	}

	/**
	 * Resolves a reference (as found in CSS) against an absolute base URL (RFC 3986, section 5.2).
	 * Dot segments are left in place; canonicalize() removes them.
	 *
	 * @param string $base Absolute base URL.
	 * @param string $ref  Reference to resolve.
	 * @return string|null
	 */
	public static function resolve($base, $ref) {
		$ref = trim((string) $ref);
		if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $ref)) {
			return $ref;
		}

		$base_parts = wp_parse_url($base);
		if (!is_array($base_parts) || empty($base_parts['scheme']) || empty($base_parts['host'])) {
			return null;
		}

		if (0 === strpos($ref, '//')) {
			return $base_parts['scheme'] . ':' . $ref;
		}

		$origin = $base_parts['scheme'] . '://' . $base_parts['host'] . (isset($base_parts['port']) ? ':' . $base_parts['port'] : '');
		$base_path = isset($base_parts['path']) && '' !== $base_parts['path'] ? $base_parts['path'] : '/';

		if ('' === $ref || '#' === $ref[0]) {
			$query = isset($base_parts['query']) ? '?' . $base_parts['query'] : '';
			return $origin . $base_path . $query . $ref;
		}

		if ('?' === $ref[0]) {
			return $origin . $base_path . $ref;
		}

		if ('/' === $ref[0]) {
			return $origin . $ref;
		}

		return $origin . substr($base_path, 0, (int) strrpos($base_path, '/') + 1) . $ref;
	}

	/**
	 * Normalizes percent-encoding and removes dot segments from a path.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	private static function normalize_path($path) {
		// Decode escaped unreserved characters and uppercase the remaining escapes.
		$path = preg_replace_callback(
			'~%([0-9A-Fa-f]{2})~',
			function ($match) {
				$char = chr((int) hexdec($match[1]));
				return preg_match('~^[A-Za-z0-9._\~-]$~', $char) ? $char : '%' . strtoupper($match[1]);
			},
			$path
		);

		// Encode stray "%" and characters unsafe in HTML attributes or unquoted CSS url().
		$path = preg_replace_callback(
			'~%(?![0-9A-F]{2})|[^A-Za-z0-9._\~!$+,;=:@/%-]~',
			function ($match) {
				return rawurlencode($match[0]);
			},
			$path
		);

		if ('' === $path || '/' !== $path[0]) {
			$path = '/' . $path;
		}

		return self::remove_dot_segments($path);
	}

	/**
	 * Removes "." and ".." segments from an absolute path (RFC 3986, section 5.2.4).
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function remove_dot_segments($path) {
		$input = explode('/', $path);
		$last = count($input) - 1;
		$output = array();

		foreach ($input as $index => $segment) {
			if ('.' === $segment || '..' === $segment) {
				if ('..' === $segment && count($output) > 1) {
					array_pop($output);
				}
				if ($index === $last) {
					$output[] = '';
				}
				continue;
			}
			$output[] = $segment;
		}

		return implode('/', $output);
	}

	/**
	 * Normalizes a query string: drops ignored arguments, re-encodes consistently and sorts by
	 * key (stable, so repeated keys such as Google's "family" keep their relative order).
	 *
	 * @param string $query Raw query string.
	 * @return string
	 */
	private static function normalize_query($query) {
		$ignored = Font_Proxy_Cache::get_ignored_query_args();
		$pairs = array();

		foreach (explode('&', $query) as $index => $piece) {
			if ('' === $piece) {
				continue;
			}

			$separator = strpos($piece, '=');
			$key = urldecode(false === $separator ? $piece : substr($piece, 0, $separator));
			$value = false === $separator ? null : urldecode(substr($piece, $separator + 1));

			if ('' === $key || in_array(strtolower($key), $ignored, true)) {
				continue;
			}

			$pairs[] = array($key, $value, $index);
		}

		usort(
			$pairs,
			function ($a, $b) {
				$compare = strcmp($a[0], $b[0]);
				return 0 !== $compare ? $compare : $a[2] - $b[2];
			}
		);

		$encoded = array();
		foreach ($pairs as $pair) {
			$encoded[] = self::encode_query_component($pair[0]) . (null === $pair[1] ? '' : '=' . self::encode_query_component($pair[1]));
		}

		return implode('&', $encoded);
	}

	/**
	 * Encodes a query key or value, keeping the delimiters Google Fonts uses readable.
	 *
	 * @param string $value Decoded component.
	 * @return string
	 */
	private static function encode_query_component($value) {
		return strtr(
			rawurlencode($value),
			array(
				'%20' => '+',
				'%3A' => ':',
				'%2C' => ',',
				'%40' => '@',
				'%3B' => ';',
				'%2F' => '/',
			)
		);
	}
}
