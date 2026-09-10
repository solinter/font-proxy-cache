<?php
/**
 * WP-CLI commands.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Manages the font proxy cache.
 */
class Font_Proxy_Cache_CLI {
	/**
	 * Shows the endpoint and storage statistics.
	 *
	 * @return void
	 */
	public function status() {
		$stats = Font_Proxy_Cache_Store::stats();

		WP_CLI::log('Endpoint:      ' . Font_Proxy_Cache::get_base_url());
		WP_CLI::log('Hosts:         ' . implode(', ', Font_Proxy_Cache::get_hosts()));
		WP_CLI::log('Object cache:  ' . Font_Proxy_Cache_Store::describe_backend());
		WP_CLI::log(sprintf('Cached URLs:   %d (served from %d distinct objects)', $stats['refs'], $stats['served_objects']));
		WP_CLI::log(sprintf('Objects:       %d, %s in the object cache', $stats['objects'], size_format($stats['bytes'])));
		if (!Font_Proxy_Cache_Store::is_available()) {
			WP_CLI::warning('Inactive: ' . Font_Proxy_Cache_Store::get_unavailable_reason());
		}
		foreach (Font_Proxy_Cache_Store::get_failures() as $url => $failure) {
			WP_CLI::warning(sprintf('Upstream failure: %s: %s', $url, $failure['message']));
		}
	}

	/**
	 * Lists cached upstream resources.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function list_($args, $assoc_args) {
		$rows = array();
		foreach (Font_Proxy_Cache_Store::all_refs() as $ref) {
			$rows[] = array(
				'url' => $ref['url'],
				'type' => $ref['type'],
				'size' => (int) ($ref['size'] ?? 0),
				'object' => substr((string) $ref['object'], 0, 12),
				'fetched' => gmdate('Y-m-d H:i:s', (int) ($ref['fetched'] ?? 0)),
			);
		}

		WP_CLI\Utils\format_items($assoc_args['format'], $rows, array('url', 'type', 'size', 'object', 'fetched'));
	}

	/**
	 * Fetches upstream URLs, and the files their CSS references, into the cache.
	 *
	 * ## OPTIONS
	 *
	 * <url>...
	 * : Upstream URLs, e.g. "https://fonts.googleapis.com/css?family=Montserrat:regular".
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	public function warm($args) {
		foreach ($args as $url) {
			$parts = Font_Proxy_Cache_Url::canonicalize($url);
			if (null === $parts || !Font_Proxy_Cache::is_proxiable($parts)) {
				WP_CLI::warning('Not proxied: ' . $url);
				continue;
			}

			$this->warm_url($parts['url'], 0);
		}
	}

	/**
	 * Prints the proxy URL for an upstream URL.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Upstream URL.
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	public function url($args) {
		$proxied = Font_Proxy_Cache::proxy_url($args[0]);
		if (null === $proxied) {
			WP_CLI::error('Not proxied: ' . $args[0]);
		}

		WP_CLI::log($proxied);
	}

	/**
	 * Deletes every cached object.
	 *
	 * @return void
	 */
	public function purge() {
		Font_Proxy_Cache_Store::purge();
		WP_CLI::success('Font proxy cache purged on every server.');
	}

	/**
	 * Deletes objects that no cached URL points to.
	 *
	 * @return void
	 */
	public function gc() {
		WP_CLI::success(sprintf('%d orphaned object(s) removed.', Font_Proxy_Cache_Store::gc()));
	}

	/**
	 * Fetches one canonical URL and, for CSS, its dependencies.
	 *
	 * @param string $url   Canonical upstream URL.
	 * @param int    $depth Recursion depth.
	 * @return void
	 */
	private function warm_url($url, $depth) {
		$result = Font_Proxy_Cache_Proxy::obtain($url, true);
		if (is_wp_error($result)) {
			WP_CLI::warning($url . ': ' . $result->get_error_message());
			return;
		}

		WP_CLI::log(sprintf('%s%-10s %s', str_repeat('  ', $depth), $result['status'], $url));
		if ($depth < 2) {
			foreach ((array) ($result['ref']['deps'] ?? array()) as $dep) {
				$this->warm_url($dep, $depth + 1);
			}
		}
	}
}
