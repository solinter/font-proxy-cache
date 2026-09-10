<?php
/**
 * Tools > Font Proxy Cache screen.
 */

if (!defined('ABSPATH')) {
	exit;
}

final class Font_Proxy_Cache_Admin {
	const PAGE_SLUG = 'font-proxy-cache';

	/**
	 * Bootstraps admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action('admin_menu', array(__CLASS__, 'register_page'));
		add_action('admin_notices', array(__CLASS__, 'render_unavailable_notice'));
		add_action('admin_post_font_proxy_cache_purge', array(__CLASS__, 'handle_purge'));
		add_action('admin_post_font_proxy_cache_gc', array(__CLASS__, 'handle_gc'));
		add_filter('plugin_action_links_' . plugin_basename(FONT_PROXY_CACHE_FILE), array(__CLASS__, 'add_action_link'));
	}

	/**
	 * Adds the page under WordPress "Tools".
	 *
	 * @return void
	 */
	public static function register_page() {
		add_management_page(
			__('Font Proxy Cache', 'font-proxy-cache'),
			__('Font Proxy Cache', 'font-proxy-cache'),
			'manage_options',
			self::PAGE_SLUG,
			array(__CLASS__, 'render_page')
		);
	}

	/**
	 * Adds a link to the page on the Plugins screen.
	 *
	 * @param string[] $links Plugin action links.
	 * @return string[]
	 */
	public static function add_action_link($links) {
		array_unshift($links, '<a href="' . esc_url(self::get_page_url()) . '">' . esc_html__('Cache', 'font-proxy-cache') . '</a>');
		return $links;
	}

	/**
	 * Warns administrators (Dashboard, Plugins, Tools page) when the plugin cannot work.
	 *
	 * @return void
	 */
	public static function render_unavailable_notice() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!current_user_can('manage_options') || null === $screen || !in_array($screen->id, array('dashboard', 'plugins', 'tools_page_' . self::PAGE_SLUG), true)) {
			return;
		}

		$reason = Font_Proxy_Cache_Store::get_unavailable_reason();
		if ('' === $reason) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__('Font Proxy Cache is inactive.', 'font-proxy-cache') . '</strong> ' . esc_html($reason) . ' ' . esc_html__('Font URLs are not rewritten, so the site keeps loading fonts from the external CDNs until a persistent object cache such as Redis is enabled.', 'font-proxy-cache') . '</p></div>';
	}

	/**
	 * Purges the cache.
	 *
	 * @return void
	 */
	public static function handle_purge() {
		self::authorize('font_proxy_cache_purge');
		Font_Proxy_Cache_Store::purge();
		self::redirect_back('purged');
	}

	/**
	 * Removes orphaned objects.
	 *
	 * @return void
	 */
	public static function handle_gc() {
		self::authorize('font_proxy_cache_gc');
		$removed = Font_Proxy_Cache_Store::gc();
		self::redirect_back('gc', $removed);
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$refs = Font_Proxy_Cache_Store::all_refs();
		$stats = Font_Proxy_Cache_Store::stats();
		$shared = array();
		foreach ($refs as $ref) {
			$shared[$ref['object']] = isset($shared[$ref['object']]) ? $shared[$ref['object']] + 1 : 1;
		}
		usort(
			$refs,
			function ($a, $b) {
				return strcmp($a['url'], $b['url']);
			}
		);

		$backend = esc_html(Font_Proxy_Cache_Store::describe_backend());
		if (!Font_Proxy_Cache_Store::is_available()) {
			$backend .= ' <strong style="color:#b32d2e;">' . esc_html__('(unavailable: the plugin is inactive)', 'font-proxy-cache') . '</strong>';
		}

		$rows = array(
			// Makes a page served from a browser/proxy cache, or by another server, easy to spot.
			/* translators: 1: date and time, 2: server host name. */
			__('Page rendered', 'font-proxy-cache') => esc_html(sprintf(__('%1$s by server %2$s', 'font-proxy-cache'), wp_date('Y-m-d H:i:s'), (string) gethostname())),
			__('Proxy endpoint', 'font-proxy-cache') => '<code>' . esc_html(Font_Proxy_Cache::get_base_url()) . '</code>',
			__('Proxied hosts', 'font-proxy-cache') => '<code>' . esc_html(implode(', ', Font_Proxy_Cache::get_hosts())) . '</code>',
			__('Object cache', 'font-proxy-cache') => $backend,
			/* translators: 1: number of URLs, 2: number of objects. */
			__('Cached URLs', 'font-proxy-cache') => esc_html(sprintf(__('%1$s URLs served from %2$s distinct objects', 'font-proxy-cache'), number_format_i18n($stats['refs']), number_format_i18n($stats['served_objects']))),
			/* translators: 1: number of objects, 2: memory usage. */
			__('Stored objects', 'font-proxy-cache') => esc_html(sprintf(__('%1$s objects, %2$s in the object cache (including pristine CSS copies)', 'font-proxy-cache'), number_format_i18n($stats['objects']), size_format($stats['bytes']))),
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('Font Proxy Cache', 'font-proxy-cache') . '</h1>';
		self::render_notice();

		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ($rows as $label => $value) {
			// Values are escaped when built above.
			echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $value . '</td></tr>';
		}
		echo '</tbody></table>';

		self::render_action_button('font_proxy_cache_gc', __('Remove orphaned objects', 'font-proxy-cache'), 'secondary');
		self::render_action_button('font_proxy_cache_purge', __('Purge cache', 'font-proxy-cache'), 'delete');

		self::render_failures();

		echo '<h2>' . esc_html__('Cached resources', 'font-proxy-cache') . '</h2>';
		if (empty($refs)) {
			echo '<p>' . esc_html__('Nothing cached yet. Files are fetched on their first request.', 'font-proxy-cache') . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__('Upstream URL', 'font-proxy-cache') . '</th>';
		echo '<th>' . esc_html__('Type', 'font-proxy-cache') . '</th>';
		echo '<th>' . esc_html__('Size', 'font-proxy-cache') . '</th>';
		echo '<th>' . esc_html__('Object', 'font-proxy-cache') . '</th>';
		echo '<th>' . esc_html__('Fetched', 'font-proxy-cache') . '</th>';
		echo '</tr></thead><tbody>';

		foreach ($refs as $ref) {
			$object = '<code>' . esc_html(substr((string) $ref['object'], 0, 12)) . '</code>';
			if ($shared[$ref['object']] > 1) {
				/* translators: %d: number of URLs sharing the object. */
				$object .= ' ' . esc_html(sprintf(__('shared by %d URLs', 'font-proxy-cache'), $shared[$ref['object']]));
			}

			echo '<tr>';
			echo '<td><code style="word-break:break-all;">' . esc_html($ref['url']) . '</code></td>';
			echo '<td>' . esc_html($ref['type']) . '</td>';
			echo '<td>' . esc_html(size_format((int) ($ref['size'] ?? 0))) . '</td>';
			echo '<td>' . $object . '</td>';
			/* translators: %s: human-readable time difference. */
			echo '<td>' . esc_html(sprintf(__('%s ago', 'font-proxy-cache'), human_time_diff((int) ($ref['fetched'] ?? 0)))) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renders recorded upstream failures.
	 *
	 * @return void
	 */
	private static function render_failures() {
		$failures = Font_Proxy_Cache_Store::get_failures();
		if (empty($failures)) {
			return;
		}

		echo '<h2>' . esc_html__('Upstream failures', 'font-proxy-cache') . '</h2>';
		echo '<p>' . esc_html__('These files could not be fetched by the server, so browsers are redirected to the original CDN. Fetches are retried every 5 minutes; "Purge cache" retries on the next request.', 'font-proxy-cache') . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__('Upstream URL', 'font-proxy-cache') . '</th>';
		echo '<th>' . esc_html__('Error', 'font-proxy-cache') . '</th>';
		echo '<th>' . esc_html__('Last attempt', 'font-proxy-cache') . '</th>';
		echo '</tr></thead><tbody>';

		foreach (array_reverse($failures, true) as $url => $failure) {
			echo '<tr>';
			echo '<td><code style="word-break:break-all;">' . esc_html($url) . '</code></td>';
			echo '<td><code>' . esc_html((string) $failure['code']) . '</code> ' . esc_html((string) $failure['message']) . '</td>';
			/* translators: %s: human-readable time difference. */
			echo '<td>' . esc_html(sprintf(__('%s ago', 'font-proxy-cache'), human_time_diff((int) $failure['time']))) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the result notice of the last action.
	 *
	 * @return void
	 */
	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = isset($_GET['fpc_notice']) ? sanitize_key(wp_unslash($_GET['fpc_notice'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$count = isset($_GET['fpc_count']) ? absint($_GET['fpc_count']) : 0;

		$class = 'notice-success';
		if ('purged' === $notice) {
			$message = __('Cache purged on every server. Files will be fetched again on their next request.', 'font-proxy-cache');
		} elseif ('gc' === $notice) {
			/* translators: %d: number of removed objects. */
			$message = sprintf(_n('%d orphaned object removed.', '%d orphaned objects removed.', $count, 'font-proxy-cache'), $count);
		} else {
			return;
		}

		echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
	}

	/**
	 * Renders a single-button admin-post form.
	 *
	 * @param string $action Admin-post action (also the nonce action).
	 * @param string $label  Button label.
	 * @param string $type   Button type for submit_button().
	 * @return void
	 */
	private static function render_action_button($action, $label, $type) {
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
		echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
		wp_nonce_field($action);
		submit_button($label, $type, 'submit', false);
		echo '</form>';
	}

	/**
	 * Checks capability and nonce for an admin-post action.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function authorize($action) {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to manage the font proxy cache.', 'font-proxy-cache'), '', array('response' => 403));
		}

		check_admin_referer($action);
	}

	/**
	 * Redirects back to the page with a notice.
	 *
	 * @param string $notice Notice id.
	 * @param int    $count  Optional count shown in the notice.
	 * @return void
	 */
	private static function redirect_back($notice, $count = 0) {
		// Unique URL per action: a proxy that forces Cache-Control on admin pages must not be
		// able to show a cached copy of an earlier result page.
		wp_safe_redirect(add_query_arg(array('fpc_notice' => $notice, 'fpc_count' => (int) $count, 'fpc_t' => (int) (microtime(true) * 1000)), self::get_page_url()));
		exit;
	}

	/**
	 * Returns the page URL.
	 *
	 * @return string
	 */
	private static function get_page_url() {
		return admin_url('tools.php?page=' . self::PAGE_SLUG);
	}
}
