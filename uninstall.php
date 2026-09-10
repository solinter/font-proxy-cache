<?php
/**
 * Removes the signing secret, the purge generation and every cached object.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

require_once __DIR__ . '/includes/class-font-proxy-cache-store.php';

Font_Proxy_Cache_Store::destroy();
delete_option('font_proxy_cache_secret');
delete_option('font_proxy_cache_failures'); // Left by versions that recorded failures in the database.
