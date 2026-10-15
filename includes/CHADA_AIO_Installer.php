<?php
/** Versioned WordPress migrations and idempotent default seeds. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration DDL and validated identifiers use the shared database helper; row values use wpdb prepare.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Migration failures are stored for escaped administrator/REST display.

final class CHADA_AIO_Installer {
    const LEGACY_PREFIX = 'chada_aiobmo_';
    const RETIRED_PREFIX = 'chadaaiobmo_';

    public static function deactivate(): void {
        // Runtime data is retained on deactivation so an administrator can reactivate safely.
    }

    public static function activate(): void {
        if (is_multisite()) { wp_die(esc_html__('Chada AIO supports single-site WordPress only.', 'chada-aio')); }
        self::migrate();
        CHADA_AIO_Config::mark_setup_pending();
    }

    /** Run the active-schema upgrade without deleting a legacy namespace until every copy is verified. */
    public static function migrate(): void {
        if (self::migration_error() !== '') { return; }
        try {
            self::ensure_tables();
            if (self::needs_prefix_migration()) { self::migrate_legacy_prefix(); }
            self::normalize_feature_defaults();
            update_option(CHADA_AIO_Config::key('schema'), CHADA_AIO_DB_VERSION, false);
            self::purge_retired_identifiers();
            delete_option(CHADA_AIO_Config::key(CHADA_AIO_Config::PREFIX_MIGRATION_ERROR));
        } catch (\Throwable $error) {
            self::block_migration($error->getMessage());
        }
    }

    public static function migration_error(): string {
        return (string) get_option(CHADA_AIO_Config::key(CHADA_AIO_Config::PREFIX_MIGRATION_ERROR), '');
    }

    public static function block_migration(string $message): void {
        update_option(CHADA_AIO_Config::key(CHADA_AIO_Config::PREFIX_MIGRATION_ERROR), $message, false);
    }

    public static function assert_ready(): void {
        $error = self::migration_error();
        if ($error !== '') {
            // translators: %s is the administrator-facing storage migration error.
            throw new \RuntimeException(sprintf(__('Storage migration is paused: %s', 'chada-aio'), $error));
        }
    }

    private static function ensure_tables(): void {
        global $wpdb;
        if (self::table_exists(CHADA_AIO_Config::table('records')) && self::table_exists(CHADA_AIO_Config::table('jobs'))
            && get_option(CHADA_AIO_Config::key('schema')) === CHADA_AIO_DB_VERSION) { return; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = CHADA_AIO_Config::table('records');
        dbDelta("CREATE TABLE $table (
            id varchar(36) NOT NULL,
            operation varchar(24) NOT NULL,
            type varchar(24) NOT NULL,
            status varchar(24) NOT NULL,
            created_at datetime NOT NULL,
            protected tinyint(1) NOT NULL DEFAULT 0,
            metadata longtext NOT NULL,
            PRIMARY KEY  (id),
            KEY operation_date (operation,created_at)
        ) $charset;");
        $table = CHADA_AIO_Config::table('jobs');
        dbDelta("CREATE TABLE $table (
            id varchar(36) NOT NULL,
            operation varchar(24) NOT NULL,
            status varchar(24) NOT NULL,
            owner_id bigint(20) unsigned NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status_updated (status,updated_at)
        ) $charset;");
        if (get_option(CHADA_AIO_Config::OPTION, null) === null) {
            add_option(CHADA_AIO_Config::OPTION, CHADA_AIO_Config::defaults(), '', false);
        }
        CHADA_AIO_Config::identity();
    }

    private static function needs_prefix_migration(): bool {
        return get_option(CHADA_AIO_Config::key('schema')) !== CHADA_AIO_DB_VERSION || self::legacy_namespace_exists();
    }

    private static function legacy_namespace_exists(): bool {
        global $wpdb;
        foreach (['settings', 'schema', 'site_id', 'feature_defaults', 'setup_pending', 'setup_complete'] as $suffix) {
            if (get_option(self::legacy_key($suffix), null) !== null) { return true; }
        }
        foreach (['records', 'jobs'] as $suffix) {
            if (self::table_exists(self::legacy_table($suffix))) { return true; }
        }
        foreach ([$wpdb->postmeta => ['source', 'import_job', 'optimized', 'original'], $wpdb->usermeta => ['tour', 'wizard']] as $table => $suffixes) {
            foreach ($suffixes as $suffix) {
                if (self::meta_count($table, self::legacy_meta($suffix)) > 0) { return true; }
            }
        }
        return false;
    }

    private static function migrate_legacy_prefix(): void {
        $current_scaffold = self::is_current_scaffold();
        self::assert_option_conflicts($current_scaffold);
        self::assert_table_conflicts();
        self::assert_metadata_conflicts();
        self::migrate_table('records');
        self::migrate_table('jobs');
        self::migrate_options($current_scaffold);
        self::migrate_metadata();
        foreach (['settings', 'schema', 'site_id', 'feature_defaults', 'setup_pending', 'setup_complete'] as $suffix) {
            delete_option(self::legacy_key($suffix));
        }
        wp_cache_flush();
    }

    private static function is_current_scaffold(): bool {
        $settings = get_option(CHADA_AIO_Config::OPTION, null);
        return self::table_count(CHADA_AIO_Config::table('records')) === 0
            && self::table_count(CHADA_AIO_Config::table('jobs')) === 0
            && ($settings === null || self::settings_equal((array) $settings, CHADA_AIO_Config::defaults()));
    }

    private static function assert_option_conflicts(bool $current_scaffold): void {
        $legacy_settings = get_option(self::legacy_key('settings'), null);
        $current_settings = get_option(CHADA_AIO_Config::OPTION, null);
        if ($legacy_settings !== null && $current_settings !== null && !$current_scaffold
            && !self::settings_equal((array) $legacy_settings, (array) $current_settings)) {
                throw new \RuntimeException(__('The existing and current Chada settings contain different operational values. Resolve the conflict before retrying migration.', 'chada-aio'));
        }
        $legacy_id = get_option(self::legacy_key('site_id'), null);
        $current_id = get_option(CHADA_AIO_Config::key('site_id'), null);
        if ($legacy_id !== null && $current_id !== null && !$current_scaffold && (string) $legacy_id !== (string) $current_id) {
            throw new \RuntimeException(__('The existing and current Chada site identities conflict. Resolve the conflict before retrying migration.', 'chada-aio'));
        }
    }

    private static function assert_table_conflicts(): void {
        foreach (['records', 'jobs'] as $suffix) {
            if (self::table_count(self::legacy_table($suffix)) > 0 && self::table_count(CHADA_AIO_Config::table($suffix)) > 0) {
                throw new \RuntimeException(__('The existing and current Chada tables both contain operational rows. No data was removed.', 'chada-aio'));
            }
        }
    }

    private static function assert_metadata_conflicts(): void {
        global $wpdb;
        foreach ([$wpdb->postmeta => ['source', 'import_job', 'optimized', 'original'], $wpdb->usermeta => ['tour', 'wizard']] as $table => $suffixes) {
            foreach ($suffixes as $suffix) {
                if (self::meta_count($table, self::legacy_meta($suffix)) > 0 && self::meta_count($table, CHADA_AIO_Config::meta($suffix)) > 0) {
                throw new \RuntimeException(__('The existing and current Chada metadata both contain operational values. No data was removed.', 'chada-aio'));
                }
            }
        }
    }

    private static function migrate_table(string $suffix): void {
        global $wpdb;
        $legacy = self::legacy_table($suffix); $current = CHADA_AIO_Config::table($suffix);
        if (!self::table_exists($legacy)) { return; }
        $legacy_count = self::table_count($legacy); $current_count = self::table_count($current);
        if ($legacy_count > 0 && $current_count === 0) {
            if ($wpdb->query('INSERT INTO ' . CHADA_AIO_Database::identifier($current) . ' SELECT * FROM ' . CHADA_AIO_Database::identifier($legacy)) === false) {
                throw new \RuntimeException(__('Could not copy the existing Chada operational table.', 'chada-aio'));
            }
            if (self::table_count($current) !== $legacy_count) {
                throw new \RuntimeException(__('Chada table verification failed; no existing table was removed.', 'chada-aio'));
            }
        }
        if ($wpdb->query('DROP TABLE IF EXISTS ' . CHADA_AIO_Database::identifier($legacy)) === false) {
            throw new \RuntimeException(__('Could not retire the verified existing Chada operational table.', 'chada-aio'));
        }
    }

    private static function migrate_options(bool $current_scaffold): void {
        $legacy_settings = get_option(self::legacy_key('settings'), null);
        $current_settings = get_option(CHADA_AIO_Config::OPTION, null);
        if ($legacy_settings !== null && ($current_settings === null || $current_scaffold)) {
            update_option(CHADA_AIO_Config::OPTION, $legacy_settings, false);
        }
        $legacy_id = get_option(self::legacy_key('site_id'), null);
        $current_id = get_option(CHADA_AIO_Config::key('site_id'), null);
        if ($legacy_id !== null && ($current_id === null || $current_scaffold)) {
            update_option(CHADA_AIO_Config::key('site_id'), $legacy_id, false);
        }
        if (get_option(self::legacy_key('feature_defaults'), null) !== null) {
            delete_option(CHADA_AIO_Config::key(CHADA_AIO_Config::FEATURE_DEFAULTS));
        }
        if ((bool) get_option(self::legacy_key('setup_complete'), false)) {
            update_option(CHADA_AIO_Config::key(CHADA_AIO_Config::SETUP_COMPLETE), 1, false);
            delete_option(CHADA_AIO_Config::key(CHADA_AIO_Config::SETUP_PENDING));
        } elseif ((bool) get_option(self::legacy_key('setup_pending'), false)) {
            update_option(CHADA_AIO_Config::key(CHADA_AIO_Config::SETUP_PENDING), 1, false);
        }
    }

    private static function migrate_metadata(): void {
        global $wpdb;
        $groups = [[$wpdb->postmeta, 'post_id', ['source', 'import_job', 'optimized', 'original']],
            [$wpdb->usermeta, 'user_id', ['tour', 'wizard']]];
        if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException(__('Could not start migrated Chada metadata transaction.', 'chada-aio')); }
        try {
            foreach ($groups as [$table, $column, $suffixes]) {
                foreach ($suffixes as $suffix) {
                    $legacy_key = self::legacy_meta($suffix); $current_key = CHADA_AIO_Config::meta($suffix);
                    $legacy_count = self::meta_count($table, $legacy_key);
                    if ($legacy_count === 0) { continue; }
                    $query = $wpdb->prepare("INSERT INTO $table ($column, meta_key, meta_value) SELECT $column, %s, meta_value FROM $table WHERE meta_key = %s", $current_key, $legacy_key);
                    if ($wpdb->query($query) === false || self::meta_count($table, $current_key) !== $legacy_count) {
                        throw new \RuntimeException(__('Chada metadata verification failed; no existing metadata was removed.', 'chada-aio'));
                    }
                    if ($wpdb->query($wpdb->prepare("DELETE FROM $table WHERE meta_key = %s", $legacy_key)) === false) {
                        throw new \RuntimeException(__('Could not retire verified existing Chada metadata.', 'chada-aio'));
                    }
                }
            }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException(__('Could not commit migrated Chada metadata.', 'chada-aio')); }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    /** Convert only the retired default values; administrator-customized retention remains intact. */
    private static function normalize_feature_defaults(): void {
        if (get_option(CHADA_AIO_Config::key(CHADA_AIO_Config::FEATURE_DEFAULTS), false)) { return; }
        $settings = get_option(CHADA_AIO_Config::OPTION, null);
        if (is_array($settings)) {
            foreach (['backup_retention', 'migration_retention'] as $key) {
                if (isset($settings[$key]) && (int) $settings[$key] === 100) { $settings[$key] = 0; }
            }
            if (($settings['default_backup'] ?? '') === 'database') { $settings['default_backup'] = 'all'; }
            update_option(CHADA_AIO_Config::OPTION, array_merge(CHADA_AIO_Config::defaults(), $settings), false);
        }
        update_option(CHADA_AIO_Config::key(CHADA_AIO_Config::FEATURE_DEFAULTS), CHADA_AIO_DB_VERSION, false);
    }

    /** Permanently remove only the older already-retired identifier namespace. */
    private static function purge_retired_identifiers(): void {
        global $wpdb;
        foreach (['settings', 'schema', 'site_id', 'feature_defaults', 'setup_pending', 'setup_complete', 'pro_settings', 'pro_schema'] as $suffix) {
            delete_option(self::RETIRED_PREFIX . $suffix);
        }
        foreach (['records', 'jobs', 'pro_history'] as $suffix) {
            $table = $wpdb->prefix . self::RETIRED_PREFIX . $suffix;
            if ($wpdb->query('DROP TABLE IF EXISTS ' . CHADA_AIO_Database::identifier($table)) === false) {
                throw new \RuntimeException(__('Could not remove a retired Chada operational table.', 'chada-aio'));
            }
        }
        foreach ([$wpdb->postmeta => ['source', 'import_job', 'optimized', 'original'], $wpdb->usermeta => ['tour', 'wizard']] as $table => $suffixes) {
            foreach ($suffixes as $suffix) {
                if ($wpdb->query($wpdb->prepare("DELETE FROM $table WHERE meta_key = %s", '_' . self::RETIRED_PREFIX . $suffix)) === false) {
                    throw new \RuntimeException(__('Could not remove retired Chada metadata.', 'chada-aio'));
                }
            }
        }
    }

    private static function settings_equal(array $left, array $right): bool {
        return array_merge(CHADA_AIO_Config::defaults(), $left) == array_merge(CHADA_AIO_Config::defaults(), $right);
    }

    private static function legacy_key(string $suffix): string { return self::LEGACY_PREFIX . ltrim($suffix, '_'); }
    private static function legacy_meta(string $suffix): string { return '_' . self::legacy_key($suffix); }
    private static function legacy_table(string $suffix): string { global $wpdb; return $wpdb->prefix . self::legacy_key($suffix); }

    private static function table_exists(string $table): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    }

    private static function table_count(string $table): int {
        if (!self::table_exists($table)) { return 0; }
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . CHADA_AIO_Database::identifier($table));
    }

    private static function meta_count(string $table, string $key): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE meta_key = %s", $key));
    }
}
