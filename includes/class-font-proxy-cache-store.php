<?php
/**
 * Content-addressed object store in the persistent object cache (Redis). Nothing is written to disk.
 *
 * Keys (group "font_proxy_cache", prefixed with the purge generation):
 *   <gen>:obj:<sha256>  File body, keyed by the SHA-256 of its content. Identical bytes are stored
 *                       once, whatever URL(s) they were fetched from.
 *   <gen>:ref:<sha256>  Ref record of one canonical upstream URL (keyed by the URL's hash); read
 *                       on every proxy request.
 *   <gen>:index         Index of objects (registered when written), refs and upstream failures,
 *                       used by the Tools page, garbage collection and purging. Updated under
 *                       <gen>:lock.
 *
 * The generation is a tiny autoloaded option: purging switches to a new generation, which makes
 * every old key unreachable at once on every server.
 */

if (!defined('ABSPATH')) {
	exit;
}

final class Font_Proxy_Cache_Store {
	const CACHE_GROUP = 'font_proxy_cache';
	const GENERATION_OPTION = 'font_proxy_cache_generation';
	const LOCK_TIMEOUT = 10;
	const MAX_FAILURES = 50;
	const ORPHAN_GRACE = 300;

	/**
	 * Key prefix of the current purge generation.
	 *
	 * @var string|null
	 */
	private static $generation = null;

	/**
	 * Returns true when a persistent object cache is enabled and connected.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return '' === self::get_unavailable_reason();
	}

	/**
	 * Explains why the store cannot be used ('' when it can).
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		global $wp_object_cache;

		// WordPress sets this whenever an object-cache.php drop-in is installed.
		if (!wp_using_ext_object_cache()) {
			return __('No persistent object cache is enabled (the object-cache.php drop-in is missing).', 'font-proxy-cache');
		}

		// The Redis Object Cache drop-in silently falls back to per-request memory when Redis is down.
		if (is_object($wp_object_cache) && method_exists($wp_object_cache, 'redis_status') && !$wp_object_cache->redis_status()) {
			return __('The Redis object cache drop-in is installed but not connected to Redis.', 'font-proxy-cache');
		}

		if (is_object($wp_object_cache) && isset($wp_object_cache->ignored_groups) && is_array($wp_object_cache->ignored_groups) && in_array(self::CACHE_GROUP, $wp_object_cache->ignored_groups, true)) {
			/* translators: %s: cache group name. */
			return sprintf(__('The "%s" cache group is configured as non-persistent (WP_REDIS_IGNORED_GROUPS).', 'font-proxy-cache'), self::CACHE_GROUP);
		}

		return '';
	}

	/**
	 * Describes the object cache backend.
	 *
	 * @return string
	 */
	public static function describe_backend() {
		global $wp_object_cache;

		if (!wp_using_ext_object_cache()) {
			return __('None', 'font-proxy-cache');
		}

		if (is_object($wp_object_cache) && method_exists($wp_object_cache, 'redis_status')) {
			return $wp_object_cache->redis_status() ? __('Redis (connected)', 'font-proxy-cache') : __('Redis (not connected)', 'font-proxy-cache');
		}

		return is_object($wp_object_cache) ? get_class($wp_object_cache) : __('Persistent object cache', 'font-proxy-cache');
	}

	/**
	 * Returns the ref record for a canonical URL, or null.
	 *
	 * @param string $url Canonical upstream URL.
	 * @return array<string, mixed>|null
	 */
	public static function get_ref($url) {
		$ref = wp_cache_get(self::key('ref:' . hash('sha256', (string) $url)), self::CACHE_GROUP);

		return is_array($ref) && isset($ref['url'], $ref['object'], $ref['type']) && $url === $ref['url'] ? $ref : null;
	}

	/**
	 * Saves the ref record for a canonical URL, indexes it and drops objects nothing points to anymore.
	 *
	 * @param string               $url Canonical upstream URL.
	 * @param array<string, mixed> $ref Ref record.
	 * @return bool
	 */
	public static function put_ref($url, array $ref) {
		$ref['url'] = $url;
		if (!wp_cache_set(self::key('ref:' . hash('sha256', (string) $url)), $ref, self::CACHE_GROUP)) {
			return false;
		}

		self::update_index(
			function ($index) use ($url, $ref) {
				$index['refs'][$url] = array(
					'type' => $ref['type'],
					'object' => $ref['object'],
					'source' => (string) ($ref['source'] ?? ''),
					'size' => (int) ($ref['size'] ?? 0),
					'fetched' => (int) ($ref['fetched'] ?? 0),
				);
				$index = self::mark_referenced($index, $ref['object'], (int) ($ref['size'] ?? 0));
				if (!empty($ref['source'])) {
					$index = self::mark_referenced($index, $ref['source'], (int) ($ref['source_size'] ?? 0));
				}

				return self::collect_garbage($index);
			}
		);

		return true;
	}

	/**
	 * Stores a body and returns its object id (SHA-256). Existing identical content is reused.
	 *
	 * @param string $body File body.
	 * @return string|null
	 */
	public static function put_object($body) {
		$body = (string) $body;
		$sha = hash('sha256', $body);

		// Wrapped in an array so the cache always serializes it: a raw body that merely looks
		// serialized (e.g. CSS starting with "a:1:{") would otherwise be unserialized on read.
		// Not added means already stored (deduplicated) or a failed write; tell them apart.
		if (!wp_cache_add(self::key('obj:' . $sha), array('body' => $body), self::CACHE_GROUP) && null === self::get_object($sha)) {
			return null;
		}

		// Indexed right away, so a purge frees it even if no ref ends up pointing to it.
		$size = strlen($body);
		self::update_index(
			function ($index) use ($sha, $size) {
				$index['objects'][$sha] = array('size' => $size, 'added' => time(), 'referenced' => false);
				return $index;
			}
		);

		return $sha;
	}

	/**
	 * Returns an object body, or null when missing (never stored, purged or evicted).
	 *
	 * @param string $sha Object id.
	 * @return string|null
	 */
	public static function get_object($sha) {
		if (!preg_match('~^[a-f0-9]{64}$~', (string) $sha)) {
			return null;
		}

		$value = wp_cache_get(self::key('obj:' . $sha), self::CACHE_GROUP);
		return is_array($value) && isset($value['body']) && is_string($value['body']) ? $value['body'] : null;
	}

	/**
	 * Returns the indexed ref summaries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all_refs() {
		$refs = array();
		foreach (self::get_index()['refs'] as $url => $ref) {
			$ref['url'] = $url;
			$refs[] = $ref;
		}

		return $refs;
	}

	/**
	 * Returns storage statistics.
	 *
	 * @return array<string, int>
	 */
	public static function stats() {
		$index = self::get_index();
		$served = array();
		foreach ($index['refs'] as $ref) {
			$served[$ref['object']] = true;
		}

		$bytes = 0;
		foreach ($index['objects'] as $object) {
			$bytes += is_array($object) ? (int) $object['size'] : (int) $object;
		}

		return array(
			'refs' => count($index['refs']),
			'served_objects' => count($served),
			'objects' => count($index['objects']),
			'bytes' => $bytes,
		);
	}

	/**
	 * Deletes indexed objects no ref points to. Returns the number of removed objects.
	 *
	 * @return int
	 */
	public static function gc() {
		$removed = 0;
		self::update_index(
			function ($index) use (&$removed) {
				$before = count($index['objects']);
				$index = self::collect_garbage($index);
				$removed = $before - count($index['objects']);
				return $index;
			}
		);

		return $removed;
	}

	/**
	 * Returns recorded upstream failures keyed by canonical URL, most recent last.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_failures() {
		return self::get_index()['failures'];
	}

	/**
	 * Records an upstream failure.
	 *
	 * @param string               $url     Canonical upstream URL.
	 * @param array<string, mixed> $failure Failure details.
	 * @return void
	 */
	public static function record_failure($url, array $failure) {
		self::update_index(
			function ($index) use ($url, $failure) {
				unset($index['failures'][$url]);
				$index['failures'][$url] = $failure;
				$index['failures'] = array_slice($index['failures'], -self::MAX_FAILURES, null, true);
				return $index;
			}
		);
	}

	/**
	 * Forgets the recorded failure of a URL.
	 *
	 * @param string $url Canonical upstream URL.
	 * @return void
	 */
	public static function clear_failure($url) {
		if (!isset(self::get_index()['failures'][$url])) {
			return;
		}

		self::update_index(
			function ($index) use ($url) {
				unset($index['failures'][$url]);
				return $index;
			}
		);
	}

	/**
	 * Purges everything on every server: deletes the indexed keys to free memory right away, then
	 * switches to a new generation so keys the index missed become unreachable too.
	 *
	 * @return void
	 */
	public static function purge() {
		$index = self::get_index();
		foreach (array_keys($index['refs']) as $url) {
			wp_cache_delete(self::key('ref:' . hash('sha256', (string) $url)), self::CACHE_GROUP);
		}
		foreach (array_keys($index['objects']) as $sha) {
			wp_cache_delete(self::key('obj:' . $sha), self::CACHE_GROUP);
		}
		wp_cache_delete(self::key('index'), self::CACHE_GROUP);
		wp_cache_delete(self::key('lock'), self::CACHE_GROUP);

		update_option(self::GENERATION_OPTION, bin2hex(random_bytes(8)), true);
		self::$generation = null;
	}

	/**
	 * Deletes everything, including the generation option (uninstall).
	 *
	 * @return void
	 */
	public static function destroy() {
		self::purge();
		delete_option(self::GENERATION_OPTION);
		self::$generation = null;
	}

	/**
	 * Returns the cache key of a name in the current generation.
	 *
	 * @param string $name Key name.
	 * @return string
	 */
	private static function key($name) {
		if (null === self::$generation) {
			self::$generation = (string) get_option(self::GENERATION_OPTION, '0');
		}

		return self::$generation . ':' . $name;
	}

	/**
	 * Returns the index, read from the backend (bypassing the per-request runtime copy).
	 *
	 * @return array{refs: array<string, array<string, mixed>>, objects: array<string, int>, failures: array<string, array<string, mixed>>}
	 */
	private static function get_index() {
		$index = wp_cache_get(self::key('index'), self::CACHE_GROUP, true);

		return array_merge(array('refs' => array(), 'objects' => array(), 'failures' => array()), is_array($index) ? $index : array());
	}

	/**
	 * Read-modify-writes the index under a lock, so concurrent requests do not lose updates.
	 *
	 * @param callable $mutate Receives and returns the index.
	 * @return bool
	 */
	private static function update_index(callable $mutate) {
		$lock = self::key('lock');
		$locked = false;

		for ($attempt = 0; $attempt < 50; $attempt++) {
			if (wp_cache_add($lock, time() + self::LOCK_TIMEOUT, self::CACHE_GROUP, self::LOCK_TIMEOUT)) {
				$locked = true;
				break;
			}

			// The deadline lives in the value: WP_REDIS_MAXTTL=0 turns every TTL into "never
			// expires", so a lock left behind by a crashed request is taken over once it is due.
			if ((int) wp_cache_get($lock, self::CACHE_GROUP, true) < time()) {
				wp_cache_delete($lock, self::CACHE_GROUP);
				continue;
			}

			usleep(20000);
		}

		// Past ~1 s without the lock, write anyway: the index is rebuilt by later writes.
		$saved = wp_cache_set(self::key('index'), $mutate(self::get_index()), self::CACHE_GROUP);

		if ($locked) {
			wp_cache_delete($lock, self::CACHE_GROUP);
		}

		return $saved;
	}

	/**
	 * Marks an indexed object as referenced by a ref.
	 *
	 * @param array<string, mixed> $index Index.
	 * @param string               $sha   Object id.
	 * @param int                  $size  Object size.
	 * @return array<string, mixed>
	 */
	private static function mark_referenced(array $index, $sha, $size) {
		$entry = $index['objects'][$sha] ?? null;
		$index['objects'][$sha] = array(
			'size' => $size,
			'added' => is_array($entry) ? (int) $entry['added'] : time(),
			'referenced' => true,
		);

		return $index;
	}

	/**
	 * Removes indexed objects that no indexed ref points to. Objects replaced by a ref go right
	 * away; objects never referenced yet may belong to a store still in progress and are kept
	 * for a grace period.
	 *
	 * @param array<string, mixed> $index Index.
	 * @return array<string, mixed>
	 */
	private static function collect_garbage(array $index) {
		$live = array();
		foreach ($index['refs'] as $ref) {
			$live[$ref['object']] = true;
			if (!empty($ref['source'])) {
				$live[$ref['source']] = true;
			}
		}

		$cutoff = time() - self::ORPHAN_GRACE;
		foreach ($index['objects'] as $sha => $object) {
			$in_flight = is_array($object) && empty($object['referenced']) && (int) $object['added'] > $cutoff;
			if (isset($live[$sha]) || $in_flight) {
				continue;
			}

			wp_cache_delete(self::key('obj:' . $sha), self::CACHE_GROUP);
			unset($index['objects'][$sha]);
		}

		return $index;
	}
}
