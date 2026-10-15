<?php
/** Native WordPress administration screens and their template data contracts. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameters select read-only admin pages.

final class CHADA_AIO_Admin_Menu {
    public static function menus(): array {
        return ['dashboard' => __('Dashboard', 'chada-aio'), 'backup' => __('Backup', 'chada-aio'), 'migration' => __('Migration', 'chada-aio'),
            'optimization' => __('Optimization', 'chada-aio'), 'settings' => __('Settings', 'chada-aio'), 'features' => __('Features', 'chada-aio'),
            'instructions' => __('Instructions', 'chada-aio')];
    }

    public static function register(): void {
        add_action('admin_menu', static function () {
            add_menu_page(CHADA_AIO_PROJECT_NAME, __('Chada AIO', 'chada-aio'), 'manage_options', CHADA_AIO_ADMIN_PREFIX . 'dashboard', [self::class, 'render'], 'dashicons-backup');
            foreach (self::menus() as $slug => $label) { add_submenu_page(CHADA_AIO_ADMIN_PREFIX . 'dashboard', $label, $label, 'manage_options', CHADA_AIO_ADMIN_PREFIX . $slug, [self::class, 'render']); }
        });
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_init', [CHADA_AIO_Runner::class, 'guard']);
        add_action('template_redirect', [CHADA_AIO_Runner::class, 'guard'], -100);
        add_action('rest_api_init', [CHADA_AIO_Runner::class, 'guard'], -100);
    }

    public static function page(): string {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return self::page_from_slug($page);
    }

    public static function page_from_slug(string $page): string {
        if (strpos($page, CHADA_AIO_ADMIN_PREFIX) !== 0) { return ''; }
        return substr($page, strlen(CHADA_AIO_ADMIN_PREFIX));
    }

    private static function page_description(string $page): string {
        $descriptions = ['dashboard' => __('A quick view of storage readiness, recent work, and the safest next action.', 'chada-aio'),
            'backup' => __('Create a verified local recovery package for this WordPress site.', 'chada-aio'),
            'migration' => __('Create a package on the source site, then preview and import it on the destination site.', 'chada-aio'),
            'optimization' => __('Optimize supported JPEG, PNG, and WebP images in repeatable batches, then preview database cleanup before deletion.', 'chada-aio'),
            'settings' => __('Configure private storage, retention, backup behavior, optimization defaults, and privacy.', 'chada-aio'),
            'features' => has_action('chada_aio_pro_loaded') !== false ? __('Compare the complete Free workflow with the additional Pro providers and optimization modes.', 'chada-aio') : __('Review the complete workflow included with this installation.', 'chada-aio'),
            'instructions' => __('Follow guided backup, restore, migration, optimization, recovery, and troubleshooting workflows.', 'chada-aio')];
        return $descriptions[$page] ?? __('Local tools for safer WordPress backup, migration, and optimization.', 'chada-aio');
    }

    public static function assets(): void {
        if (!isset(self::menus()[self::page()])) { return; }
        $asset_version = static function (string $path): string {
            $modified = is_file($path) ? filemtime($path) : false;
            return false === $modified ? CHADA_AIO_VERSION : CHADA_AIO_VERSION . '.' . $modified;
        };
        $style_path = CHADA_AIO_PLUGIN_DIR . 'assets/css/chada-aio-admin.css';
        $script_path = CHADA_AIO_PLUGIN_DIR . 'assets/js/chada-aio-admin.js';
        wp_enqueue_style('chada-aio-admin', CHADA_AIO_PLUGIN_URL . 'assets/css/chada-aio-admin.css', [], $asset_version($style_path));
        wp_enqueue_script('chada-aio-admin', CHADA_AIO_PLUGIN_URL . 'assets/js/chada-aio-admin.js', ['wp-i18n'], $asset_version($script_path), true);
        wp_set_script_translations('chada-aio-admin', 'chada-aio', CHADA_AIO_PLUGIN_DIR . 'languages');
        $config = ['rest' => esc_url_raw(rest_url(CHADA_AIO_REST_NAMESPACE . '/')), 'nonce' => wp_create_nonce('wp_rest'),
            'page' => self::page(), 'admin' => admin_url('admin.php?page=' . CHADA_AIO_ADMIN_PREFIX),
            'providers' => ['backup' => CHADA_AIO_Plugin::types('backup'), 'export' => CHADA_AIO_Plugin::types('export'), 'optimize' => CHADA_AIO_Plugin::types('optimize')],
            'proAvailable' => has_action('chada_aio_pro_loaded') !== false,
            'selectedImageLimit' => CHADA_AIO_Plugin::selected_image_limit(),
            'autoOpenWizard' => isset($_GET['setup']) && is_string($_GET['setup']) && sanitize_key(wp_unslash($_GET['setup'])) === '1',
            'tour' => get_user_meta(get_current_user_id(), CHADA_AIO_Config::meta('tour'), true),
            'wizard' => get_user_meta(get_current_user_id(), CHADA_AIO_Config::meta('wizard'), true)];
        $json_flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        $json_config = wp_json_encode($config, $json_flags);
        wp_add_inline_script('chada-aio-admin', 'window.ChadaAio = ' . ($json_config === false ? '{}' : $json_config) . ';', 'before');
    }

    public static function render(): void {
        if (!CHADA_AIO_Rest::permission()) { wp_die(esc_html__('Administrator access is required.', 'chada-aio')); }
        $page = self::page();
        CHADA_AIO_Template::output('admin/shared/page-header', ['chada_aio_title' => self::menus()[$page] ?? __('Chada AIO', 'chada-aio'),
            'chada_aio_badge' => has_action('chada_aio_pro_loaded') ? __('Free + Pro', 'chada-aio') : __('Free', 'chada-aio'),
            'chada_aio_description' => self::page_description($page)]);
        CHADA_AIO_Template::output('admin/shared/job');
        try {
            if ($page === 'dashboard') { CHADA_AIO_Admin_Dashboard::render(); }
            elseif ($page === 'backup') { CHADA_AIO_Admin_Backup::render(); }
            elseif ($page === 'migration') { CHADA_AIO_Admin_Migration::render(); }
            elseif ($page === 'optimization') { CHADA_AIO_Admin_Optimization::render(); }
            elseif ($page === 'settings') { CHADA_AIO_Admin_Settings::render(); }
            elseif ($page === 'features') { CHADA_AIO_Admin_Features::render(); }
            else { CHADA_AIO_Admin_Instructions::render(); }
        } catch (\Throwable $error) {
            CHADA_AIO_Template::output('admin/shared/notice', ['chada_aio_type' => 'error', 'chada_aio_message' => $error->getMessage()]);
        }
        CHADA_AIO_Template::output('admin/shared/dialogs');
    }

    public static function dashboard(): void {
        $runtime = CHADA_AIO_Runtime::status(); $runtime['message'] = CHADA_AIO_Runtime::message($runtime); $storage = CHADA_AIO_Storage::status();
        $records = $storage['ready'] ? CHADA_AIO_Catalog::all('backup') : [];
        $migration_records = $storage['ready'] ? array_merge(CHADA_AIO_Catalog::all('export'), CHADA_AIO_Catalog::all('import')) : [];
        $setting_errors = CHADA_AIO_Config::readiness_errors();
        foreach ($setting_errors as &$setting_error) {
            $setting_error['url'] = add_query_arg(['page' => CHADA_AIO_ADMIN_PREFIX . 'settings', 'tab' => $setting_error['tab']], admin_url('admin.php'));
        }
        unset($setting_error);
        $cards = [['label' => __('Storage', 'chada-aio'), 'value' => $storage['ready'] ? __('Ready', 'chada-aio') : __('Needs setup', 'chada-aio')],
            ['label' => __('Retained backups', 'chada-aio'), 'value' => count($records)], ['label' => __('Migration activity', 'chada-aio'), 'value' => count($migration_records)],
            ['label' => __('Archive storage', 'chada-aio'), 'value' => size_format(array_sum(array_column($records, 'bytes')))],
            ['label' => __('Latest verified backup', 'chada-aio'), 'value' => $records[0]['created_at'] ?? __('No backups yet', 'chada-aio')]];
        $actions = [];
        foreach (['backup' => __('Create Backup All', 'chada-aio'), 'migration' => __('Open Migration', 'chada-aio'), 'settings' => __('Review settings', 'chada-aio'), 'instructions' => __('Read Instructions', 'chada-aio')] as $page => $label) {
            $actions[] = ['label' => $label, 'url' => admin_url('admin.php?page=' . CHADA_AIO_ADMIN_PREFIX . $page), 'primary' => $page === 'backup'];
        }
        CHADA_AIO_Template::output('admin/dashboard/page', ['chada_aio_cards' => $cards, 'chada_aio_actions' => $actions,
            'chada_aio_setting_errors' => $setting_errors, 'chada_aio_storage' => $storage, 'chada_aio_zip_available' => class_exists('ZipArchive'),
            'chada_aio_image_editor_available' => wp_image_editor_supports(['mime_type' => 'image/jpeg']), 'chada_aio_runtime' => $runtime]);
        CHADA_AIO_Admin_Records::render('backup');
    }

    private static function selector_data(string $operation): array {
        $types = [];
        foreach (CHADA_AIO_Plugin::types($operation) as $type) { $types[] = ['value' => $type, 'label' => self::type_label($type, $operation)]; }
        $themes = [];
        foreach (wp_get_themes() as $slug => $theme) { $themes[] = ['value' => $slug, 'label' => $theme->get('Name'), 'selected' => $slug === get_stylesheet()]; }
        return ['operation' => $operation, 'types' => $types, 'default_type' => $operation === 'backup' ? CHADA_AIO_Config::get('default_backup') : '',
            'year' => wp_date('Y'), 'themes' => $themes, 'show_plugins' => in_array('plugin', CHADA_AIO_Plugin::types($operation), true),
            'inventory' => apply_filters('chada_aio_plugin_inventory', ['normal' => [], 'must_use' => []])];
    }

    private static function type_label(string $type, string $operation): string {
        return CHADA_AIO_Plugin::provider_label($type, $operation);
    }

    public static function backup(): void {
        CHADA_AIO_Template::output('admin/backup/page', ['chada_aio_selector' => self::selector_data('backup')]);
        CHADA_AIO_Admin_Records::render('backup');
    }

    public static function grid(string $operation): void {
        $is_migration = strpos($operation, 'export') !== false || strpos($operation, 'import') !== false;
        $is_optimization = $operation === 'optimize';
        $provider_operation = $is_migration ? 'export' : $operation;
        $types = [];
        foreach (CHADA_AIO_Plugin::types($provider_operation) as $type) { $types[] = ['value' => $type, 'label' => self::type_label($type, $provider_operation)]; }
        CHADA_AIO_Template::output('admin/shared/records', ['chada_aio_operation' => $operation, 'chada_aio_title' => $is_migration ? __('Migration activity', 'chada-aio') : ($is_optimization ? __('Optimization history', 'chada-aio') : __('Backup records', 'chada-aio')),
            'chada_aio_description' => $is_migration ? __('Migration exports and imports are listed here. Backup records, including protected recovery backups, remain on the Backup page.', 'chada-aio') : ($is_optimization ? __('Completed image and cleanup operations are listed here for reference.', 'chada-aio') : __('Verified local backups and protected recovery backups are listed here.', 'chada-aio')),
            'chada_aio_types' => $types]);
    }

    public static function migration(): void {
        CHADA_AIO_Template::output('admin/migration/page', ['chada_aio_selector' => self::selector_data('export')]);
        CHADA_AIO_Admin_Records::render('export,import');
    }

    public static function optimization(): void {
        $limit = CHADA_AIO_Plugin::selected_image_limit();
        $images = [];
        foreach (get_posts(['post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'posts_per_page' => $limit, 'orderby' => 'ID', 'order' => 'DESC']) as $post) {
            $images[] = ['id' => (int) $post->ID, 'title' => (string) $post->post_title];
        }
        $types = [];
        foreach (CHADA_AIO_Plugin::types('optimize') as $type) { if ($type !== 'selected') { $types[] = ['value' => $type, 'label' => self::type_label($type, 'optimize')]; } }
        $categories = ['revisions' => __('Old revisions', 'chada-aio'), 'trash' => __('Old trashed posts/pages', 'chada-aio'),
            'comments' => __('Old spam/trashed comments', 'chada-aio'), 'transients' => __('Expired transients', 'chada-aio')];
        $limit_description = __('Select JPEG, PNG, or WebP attachments for a repeatable optimization operation. Originals are retained for undo, while formats, metadata, and URLs remain unchanged.', 'chada-aio');
        $limit_label = __('Selected images', 'chada-aio');
        CHADA_AIO_Template::output('admin/optimization/page', ['chada_aio_limit' => $limit, 'chada_aio_limit_description' => $limit_description,
            'chada_aio_limit_label' => $limit_label, 'chada_aio_types' => $types, 'chada_aio_year' => wp_date('Y'), 'chada_aio_images' => $images,
            'chada_aio_categories' => $categories, 'chada_aio_tables' => CHADA_AIO_Cleanup::tables()]);
        CHADA_AIO_Admin_Records::render('optimize');
    }

    public static function settings(): void {
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        $labels = ['general' => __('General', 'chada-aio'), 'storage' => __('Storage & Retention', 'chada-aio'), 'backup' => __('Backup & Restore', 'chada-aio'),
            'optimization' => __('Optimization', 'chada-aio'), 'privacy' => __('Data & Privacy', 'chada-aio')];
        if (!isset($labels[$tab])) { $tab = 'general'; }
        $settings = CHADA_AIO_Config::all();
        $wizard_tabs = ['storage', 'general', 'optimization'];
        $wizard_steps = [];
        foreach ($wizard_tabs as $index => $wizard_tab) { $wizard_steps[] = ['step' => $index, 'tab' => $wizard_tab, 'fields' => self::setting_fields_data($wizard_tab, $settings)]; }
        CHADA_AIO_Template::output('admin/settings/page', ['chada_aio_tab' => $tab, 'chada_aio_labels' => $labels,
            'chada_aio_fields' => self::setting_fields_data($tab, $settings), 'chada_aio_wizard_steps' => $wizard_steps]);
    }

    private static function setting_fields_data(string $tab, array $settings): array {
        return CHADA_AIO_Config::field_definitions($tab, $settings);
    }

    public static function features(): void {
        $pro_available = has_action('chada_aio_pro_loaded') !== false;
        CHADA_AIO_Template::output('admin/features/page', ['chada_aio_groups' => self::feature_groups($pro_available), 'chada_aio_pro_available' => $pro_available]);
    }

    /** Build the feature catalog for the currently available editions. */
    public static function feature_groups(bool $pro_available): array {
        $groups = [
            ['label' => __('Backup and recovery', 'chada-aio'), 'features' => [
                ['name' => __('Backup All', 'chada-aio'), 'description' => __('Verified runtime-only backup with protected recovery, download, and same-site restore.', 'chada-aio'), 'free' => true, 'pro' => true],
                ['name' => __('Selective Database, Page, Post, Media, Theme and Plugin backups', 'chada-aio'), 'description' => __('Pro-only component backups with bounded capture, native content mapping, theme settings and explicit plugin selection.', 'chada-aio'), 'free' => false, 'pro' => true],
                ['name' => __('Backup history retention', 'chada-aio'), 'description' => __('Retention settings rotate older unprotected history after successful verification; protected recovery records remain available.', 'chada-aio'), 'free' => true, 'pro' => true]]],
            ['label' => __('Migration and optimization', 'chada-aio'), 'features' => [
                ['name' => __('Migrate All', 'chada-aio'), 'description' => __('Verified runtime-only migration with URL and table-prefix remapping, targeted recovery, and administrator-access warning.', 'chada-aio'), 'free' => true, 'pro' => true],
                ['name' => __('Selective Database, Page, Post, Media, Theme and Plugin migration', 'chada-aio'), 'description' => __('Pro-only component export/import with previews, mapped IDs, isolated media paths and explicit plugin selection.', 'chada-aio'), 'free' => false, 'pro' => true],
                ['name' => __('Selected image optimization', 'chada-aio'), 'description' => __('Optimize selected JPEG, PNG, and WebP attachments in repeatable batches while retaining originals for undo.', 'chada-aio'), 'free' => true, 'pro' => true],
                ['name' => __('Library, Quarter and Year image optimization', 'chada-aio'), 'description' => __('Pro-only whole-library or calendar-period processing using successive bounded requests.', 'chada-aio'), 'free' => false, 'pro' => true],
                ['name' => __('Database cleanup', 'chada-aio'), 'description' => __('Both editions preview old revisions, trash, spam comments and expired transients while leaving uncertain tables untouched.', 'chada-aio'), 'free' => true, 'pro' => true]]],
            ['label' => __('Administration and guidance', 'chada-aio'), 'features' => [
                ['name' => __('Dashboard and settings', 'chada-aio'), 'description' => __('Operational summary, storage readiness, percentage progress, independent settings tabs and safe defaults.', 'chada-aio'), 'free' => true, 'pro' => true],
                ['name' => __('Setup Wizard', 'chada-aio'), 'description' => __('Activation notice launches a guided first-time setup; administrators can reopen it manually whenever settings need review.', 'chada-aio'), 'free' => true, 'pro' => true],
                ['name' => __('Instructions and walkthrough', 'chada-aio'), 'description' => __('Detailed edition guidance, recovery workflows and a read-only keyboard-accessible tour.', 'chada-aio'), 'free' => true, 'pro' => true],
                ['name' => __('Private local processing', 'chada-aio'), 'description' => __('Processing stays on this WordPress server; runtime assets are bundled locally and no remote service is required.', 'chada-aio'), 'free' => true, 'pro' => true]]],
        ];
        if (!$pro_available) {
            foreach ($groups as &$group) {
                $group['features'] = array_values(array_filter($group['features'], static function (array $feature): bool { return $feature['free']; }));
            }
            unset($group);
            $groups = array_values(array_filter($groups, static function (array $group): bool { return $group['features'] !== []; }));
        }
        return $groups;
    }

    public static function instructions(): void {
        $tabs = ['getting-started' => __('Getting Started', 'chada-aio'), 'backup-restore' => __('Backup & Restore', 'chada-aio'), 'migration' => __('Migration', 'chada-aio'),
            'optimization' => __('Optimization', 'chada-aio'), 'troubleshooting' => __('Troubleshooting', 'chada-aio'), 'glossary' => __('Glossary', 'chada-aio'), 'walkthrough' => __('Interactive Walkthrough', 'chada-aio')];
        $pro_available = has_action('chada_aio_pro_loaded') !== false;
        $backup_steps = [['text' => __('Choose Backup All, review the scope, and start the operation when the site is ready.', 'chada-aio')],
            ['text' => __('Wait for percentage verification and review exclusions in View Details.', 'chada-aio')], ['text' => __('Restore opens a preview. Confirm only after reviewing affected data.', 'chada-aio')],
            ['text' => __('A verified recovery backup precedes changes. Use Rollback if a job needs recovery.', 'chada-aio')]];
        if ($pro_available) {
            array_unshift($backup_steps, ['text' => __('Choose Backup All or a listed component provider. For Plugin backups, select the normal plugins to include; active plugins are preselected and must-use plugins require explicit opt-in.', 'chada-aio')]);
        }
        $migration_steps = [['text' => __('Create a Migrate All package on the source site, then download it from Migration activity.', 'chada-aio')],
            ['text' => __('Upload the Chada ZIP on the destination site, review the destination impact, and confirm the import.', 'chada-aio')],
            ['text' => __('Verify content, links, media, administrator access, and the completed job details afterwards.', 'chada-aio')]];
        if ($pro_available) {
            $migration_steps[0] = ['text' => __('Create a Migrate All package or choose a listed component provider on the source site, then download it from Migration activity.', 'chada-aio')];
        }
        $optimization_paragraphs = [__('Image operations preserve JPEG/PNG/WebP formats, attachment metadata, and existing URLs. Landscape fits 1600×1200, portrait 1200×1600, and square 1200×1200 by default, without crop or upscale. Choose a predefined resolution in Settings or enter a Custom width and height. The saved quality setting is applied during re-encoding. Originals remain private for undo; download site backups before deliberate original cleanup.', 'chada-aio')];
        if ($pro_available) {
            $optimization_paragraphs[] = __('Selected images can be processed in repeatable batches. Library, Quarter, and Year selection are also available. Missing, offloaded, animated, and unsupported images are reported for review.', 'chada-aio');
        } else {
            $optimization_paragraphs[] = __('Select images in repeatable batches. Missing, offloaded, animated, and unsupported images are reported for review.', 'chada-aio');
        }
        $optimization_paragraphs[] = __('Database cleanup requires category selection, preview, and a verified recovery backup. Unknown tables are inspection-only and never dropped.', 'chada-aio');
        $contents = [
            'getting-started' => ['intro' => __('Configure the plugin in this order:', 'chada-aio'), 'steps' => [
                ['before' => __('Open ', 'chada-aio'), 'link' => __('Settings → Setup Wizard', 'chada-aio'), 'url' => admin_url('admin.php?page=' . CHADA_AIO_ADMIN_PREFIX . 'settings'), 'after' => __(' and choose protected storage.', 'chada-aio')],
                ['text' => __('Review backup and migration retention settings before creating history.', 'chada-aio')],
                ['text' => __('Create Backup All. It discovers runtime paths, streams files and database rows in bounded batches, calculates checksums during capture, and verifies the ZIP structure.', 'chada-aio')],
                ['text' => __('Inspect the completed metrics, download a separate copy from Backup history, and test restore on an isolated site before relying on it.', 'chada-aio')],
                ['text' => __('Review image and database cleanup defaults before starting optimization.', 'chada-aio')]]],
            'backup-restore' => ['steps' => $backup_steps, 'paragraphs' => [__('Backup All includes site-prefixed tables, uploads, must-use plugins, active plugin roots, the active and parent themes, and present runtime drop-ins. It excludes inactive plugins/themes, arbitrary custom wp-content paths, core, wp-config.php, server configuration, operational storage, caches, and development secrets. Running Chada plugin files are preserved during restore. Plugin settings and custom tables require the applicable backup scope. Destination-only files remain. Backups are live batched captures: pause outside writers for cross-table consistency.', 'chada-aio')]],
            'migration' => ['steps' => $migration_steps, 'paragraphs' => [__('Page, Post, and media imports allocate mapped IDs and isolated media paths; repeated source imports skip existing mapped records. Unknown authors map to the importing administrator. Covered tables and users may replace destination values, so source administrator credentials may be needed afterwards. Destination connection configuration remains. Serialized objects, foreign-key schemas, partitions, views, and unsupported packages are rejected for manual handling. Migrate All uses the same runtime discovery, bounded work windows, streaming archive, and metrics as Backup All. Migration imports stage only metadata and database payloads, then stream changed runtime files directly into destination-local temporary files while checking checksums; unchanged files are skipped.', 'chada-aio')]],
            'optimization' => ['paragraphs' => $optimization_paragraphs],
            'troubleshooting' => ['definitions' => [['term' => __('Storage is not ready', 'chada-aio'), 'description' => __('Use an absolute, writable private directory outside the web document root. Ask the server administrator to provision it; do not put archives in a publicly accessible folder.', 'chada-aio')], ['term' => __('Job stopped or browser closed', 'chada-aio'), 'description' => __('Return and Resume. If live changes have started and failed, use Rollback. Keep the targeted recovery archive and journal for All or Database jobs.', 'chada-aio')], ['term' => __('Login changed after database import', 'chada-aio'), 'description' => __('Sign in with an administrator from the source database. Configuration stays on the destination.', 'chada-aio')], ['term' => __('Unsupported or very large input', 'chada-aio'), 'description' => __('Current safety ceilings are 100,000 package entries, 1 GiB per entry, 20 GiB expanded data, 16 MiB per database row, and 60 megapixels per decoded image. Host disk/memory limits also apply. Use server-level tools for unsupported cases.', 'chada-aio')]]],
            'glossary' => ['definitions' => [['term' => __('Backup', 'chada-aio'), 'description' => __('A verified runtime-scope package for the current site.', 'chada-aio')], ['term' => __('Migration package', 'chada-aio'), 'description' => __('A verified package exported from one site and imported into another installation with supported mappings.', 'chada-aio')], ['term' => __('Recovery backup', 'chada-aio'), 'description' => __('A protected snapshot created before destructive operations. It provides a safe rollback path when a supported operation changes site data.', 'chada-aio')], ['term' => __('Retention', 'chada-aio'), 'description' => __('A setting that controls how verified history is kept after processing.', 'chada-aio')], ['term' => __('Quarter', 'chada-aio'), 'description' => __('Q1 January–March, Q2 April–June, Q3 July–September, Q4 October–December in the site timezone.', 'chada-aio')]]],
            'walkthrough' => ['paragraphs' => [__('Review the menus and workflows without changing records or settings. Previous/Next navigate guidance; Close saves your position.', 'chada-aio')], 'walkthrough' => true],
        ];
        CHADA_AIO_Template::output('admin/instructions/page', ['chada_aio_tabs' => $tabs, 'chada_aio_contents' => $contents]);
    }
}
