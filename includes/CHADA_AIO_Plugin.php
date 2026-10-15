<?php
/** Free provider registry and optional add-on extension entry point. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as REST JSON or passed as raw data to escaped templates.

final class CHADA_AIO_Plugin {
    private static $instance;
    private static $providers = [];

    public static function instance(): self {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function run(): void {
        if (is_multisite()) { return; }
        CHADA_AIO_Installer::migrate();
        self::register('backup', 'all', ['CHADA_AIO_Operations', 'backup']);
        self::register('export', 'all', ['CHADA_AIO_Operations', 'backup']);
        self::register('import', 'all', ['CHADA_AIO_Operations', 'apply']);
        self::register('restore', 'all', ['CHADA_AIO_Operations', 'apply']);
        self::register('files', 'all', ['CHADA_AIO_Operations', 'valid_files']);
        self::register('permission', 'all', ['CHADA_AIO_Plugin', 'all_file_permission']);
        self::register('optimize', 'selected', ['CHADA_AIO_Images', 'step']);
        self::register('cleanup', 'database', ['CHADA_AIO_Cleanup', 'step']);
        foreach (['all', 'selected', 'database'] as $type) {
            self::register('release', $type, ['CHADA_AIO_Operations', 'release']);
            self::register('rollback', $type, ['CHADA_AIO_Operations', 'rollback']);
        }
        do_action('chada_aio_register_providers');
        add_filter('plugin_action_links_' . plugin_basename(CHADA_AIO_PLUGIN_FILE), [self::class, 'plugin_action_links']);
        add_action('admin_notices', [self::class, 'setup_notice']);
        add_action('admin_notices', [self::class, 'migration_notice']);
        CHADA_AIO_Rest::register(); CHADA_AIO_Admin_Menu::register();
    }

    /** Show a non-blocking first-activation reminder without redirecting administrators or WP-CLI. */
    public static function setup_notice(): void {
        if (!is_admin() || !current_user_can('manage_options') || !CHADA_AIO_Config::setup_pending()) { return; }
        global $pagenow;
        $page = CHADA_AIO_Admin_Menu::page();
        if ($pagenow !== 'plugins.php' && !in_array($page, ['dashboard', 'settings'], true)) { return; }
        $url = add_query_arg(['page' => CHADA_AIO_ADMIN_PREFIX . 'settings', 'setup' => '1'], admin_url('admin.php'));
        CHADA_AIO_Template::output('admin/shared/setup-notice', ['chada_aio_url' => $url]);
    }

    public static function migration_notice(): void {
        if (!is_admin() || !current_user_can('manage_options')) { return; }
        $error = CHADA_AIO_Installer::migration_error();
        if ($error === '') { return; }
        CHADA_AIO_Template::output('admin/shared/migration-notice', ['chada_aio_error' => $error]);
    }

    public static function selected_image_limit(): int {
        return max(1, (int) apply_filters('chada_aio_selected_image_limit', self::default_image_limit()));
    }

    private static function default_image_limit(): int { return CHADA_AIO_Config::MANUAL_IMAGES; }

    /** Add direct Settings and Dashboard links after WordPress's Deactivate action. */
    public static function plugin_action_links(array $links): array {
        $actions = [
            'chada-aio-settings' => CHADA_AIO_Template::render('admin/shared/plugin-action-link', ['chada_aio_url' => admin_url('admin.php?page=' . CHADA_AIO_ADMIN_PREFIX . 'settings'), 'chada_aio_label' => __('Settings', 'chada-aio')]),
            'chada-aio-dashboard' => CHADA_AIO_Template::render('admin/shared/plugin-action-link', ['chada_aio_url' => admin_url('admin.php?page=' . CHADA_AIO_ADMIN_PREFIX . 'dashboard'), 'chada_aio_label' => __('Dashboard', 'chada-aio')]),
        ];
        return array_merge($links, $actions);
    }

    public static function register(string $operation, string $type, array $callback): void {
        if (strpos($callback[0], '\\') === false) { $callback[0] = __NAMESPACE__ . '\\' . $callback[0]; }
        self::$providers[$operation][$type] = $callback;
    }

    public static function types(string $operation): array { return array_keys(self::$providers[$operation] ?? []); }

    public static function package_types(): array {
        $types = self::types('backup');
        foreach (['export', 'import', 'restore'] as $operation) { $types = array_values(array_intersect($types, self::types($operation))); }
        return $types;
    }

    public static function maybe_provider(string $operation, string $type): ?callable {
        $provider = self::$providers[$operation][$type] ?? null;
        return is_callable($provider) ? $provider : null;
    }

    public static function provider(string $operation, string $type): callable {
        $provider = self::maybe_provider($operation, $type);
        if (!$provider) {
            throw new \RuntimeException(__('This operation provider is not installed or its dependency is unavailable.', 'chada-aio'));
        }
        return $provider;
    }

    public static function file_permission(string $type): void {
        $provider = self::maybe_provider('permission', $type);
        if ($provider) { $provider($type); }
    }

    public static function lifecycle(string $operation, array &$job): void {
        $provider = self::maybe_provider($operation, (string) ($job['type'] ?? 'all'));
        if (!$provider && in_array($operation, ['release', 'rollback'], true)) {
            $provider = [CHADA_AIO_Operations::class, $operation];
        }
        if ($provider) { $provider($job); }
    }

    public static function provider_label(string $type, string $operation): string {
        $labels = ['all' => $operation === 'backup' ? __('Backup All', 'chada-aio') : __('Migrate All', 'chada-aio'),
            'selected' => __('Selected images', 'chada-aio'), 'database' => __('Database cleanup', 'chada-aio')];
        $label = $labels[$type] ?? ucfirst($type);
        return (string) apply_filters('chada_aio_provider_type_label', $label, $type, $operation);
    }

    public static function all_file_permission(string $type): void {
        if ($type !== 'all') { return; }
        if (!wp_is_file_mod_allowed('chada_aio_restore')) { throw new \RuntimeException(__('WordPress file modifications are disabled.', 'chada-aio')); }
        $caps = ['install_plugins', 'update_plugins', 'install_themes', 'update_themes'];
        foreach ($caps as $cap) { if (!current_user_can($cap)) { throw new \RuntimeException(__('Insufficient file installation permissions.', 'chada-aio')); } }
    }
}
