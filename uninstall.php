<?php
/** Optional index/settings removal; private archives and originals are deliberately preserved. */
defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/includes/CHADA_AIO_Installer.php';
$chada_aio_legacy_prefix = \CHADA_AIO\CHADA_AIO_Installer::LEGACY_PREFIX;
$chada_aio_retired_prefix = \CHADA_AIO\CHADA_AIO_Installer::RETIRED_PREFIX;
$chada_aio_prefixes = ['chada_aio_', $chada_aio_legacy_prefix, $chada_aio_retired_prefix];
$chada_aio_settings = get_option('chada_aio_settings', get_option($chada_aio_legacy_prefix . 'settings', get_option($chada_aio_retired_prefix . 'settings', [])));
if (empty($chada_aio_settings['remove_settings'])) { return; }
global $wpdb;
foreach (['jobs', 'records'] as $chada_aio_table_suffix) {
    foreach ($chada_aio_prefixes as $chada_aio_prefix) {
        $chada_aio_table = $wpdb->prefix . $chada_aio_prefix . $chada_aio_table_suffix;
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $chada_aio_table));
    }
}
foreach (['settings', 'schema', 'feature_defaults', 'setup_pending', 'setup_complete'] as $chada_aio_option_suffix) {
    foreach ($chada_aio_prefixes as $chada_aio_prefix) {
        delete_option($chada_aio_prefix . $chada_aio_option_suffix);
    }
}
// Preserve site UUID options: they identify existing private archives and must not change on reinstall.
