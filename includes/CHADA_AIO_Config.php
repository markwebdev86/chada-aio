<?php
/** Validated settings and version-independent operating defaults. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as REST JSON or escaped in CHADA_AIO_Admin_Menu::render; no exception message is printed as raw HTML.

final class CHADA_AIO_Config {
    const OPTION = \CHADA_AIO_PREFIX . 'settings';
    const FEATURE_DEFAULTS = 'feature_defaults';
    const SETUP_PENDING = 'setup_pending';
    const SETUP_COMPLETE = 'setup_complete';
    const PREFIX_MIGRATION_ERROR = 'prefix_migration_error';
    const ROW_BATCH = 250;
    const DB_QUERY_BYTES = 1048576;
    const FILE_BATCH = 100;
    const DB_BATCHES = 16;
    const STEP_SECONDS = 5;
    const STREAM_CHUNK = 1048576;
    const SOURCE_RETRIES = 2;
    const IMAGE_BATCH = 1;
    const MANUAL_IMAGES = 100;
    const UPLOAD_CHUNK = 2097152;
    const UPLOAD_TTL = 172800;
    const PREVIEW_TTL = 1800;
    const UPLOAD_RETRIES = 3;
    const MAX_ENTRIES = 100000;
    const MAX_EXPANDED = 21474836480;
    const MAX_ENTRY = 1073741824;
    const MAX_RATIO = 1000;
    const TABS = [
        'general' => ['default_backup'],
        'storage' => ['storage_path', 'backup_retention', 'migration_retention'],
        'backup' => ['exclude_paths'],
        'optimization' => ['quality', 'landscape_width', 'landscape_height', 'cleanup_days', 'keep_revisions'],
        'privacy' => ['remove_settings'],
    ];

    public static function defaults(): array {
        return ['storage_path' => '', 'backup_retention' => 0, 'migration_retention' => 0,
            'default_backup' => 'all', 'exclude_paths' => '', 'quality' => 80, 'landscape_width' => 1600,
            'landscape_height' => 1200, 'cleanup_days' => 30, 'keep_revisions' => 5, 'remove_settings' => false];
    }

    /** Return the administrator-facing image resolution presets; Custom is intentionally not persisted. */
    public static function resolution_presets(): array {
        return [
            '3840x2880' => ['width' => 3840, 'height' => 2880, 'label' => __('3840×2880 px', 'chada-aio')],
            '2560x1920' => ['width' => 2560, 'height' => 1920, 'label' => __('2560×1920 px', 'chada-aio')],
            '1920x1440' => ['width' => 1920, 'height' => 1440, 'label' => __('1920×1440 px', 'chada-aio')],
            '1600x1200' => ['width' => 1600, 'height' => 1200, 'label' => __('1600×1200 px', 'chada-aio')],
            '1366x1024' => ['width' => 1366, 'height' => 1024, 'label' => __('1366×1024 px', 'chada-aio')],
            '1024x768' => ['width' => 1024, 'height' => 768, 'label' => __('1024×768 px', 'chada-aio')],
            'custom' => ['width' => null, 'height' => null, 'label' => __('Custom', 'chada-aio')],
        ];
    }

    /** Return a stable select option list for the virtual resolution field. */
    public static function resolution_options(): array {
        $presets = self::resolution_presets();
        return array_map(static function (array $preset, string $value): array {
            return ['value' => $value, 'label' => $preset['label']];
        }, $presets, array_keys($presets));
    }

    /** Select the matching preset or Custom for saved dimensions that do not match a preset. */
    public static function selected_resolution(array $settings): string {
        $width = (int) ($settings['landscape_width'] ?? 0); $height = (int) ($settings['landscape_height'] ?? 0);
        foreach (self::resolution_presets() as $value => $preset) {
            if ($preset['width'] !== null && $width === $preset['width'] && $height === $preset['height']) { return $value; }
        }
        return 'custom';
    }

    /** Return the shared settings field contract used by validation and administration screens. */
    public static function field_schema(): array {
        return [
            'storage_path' => ['tab' => 'storage', 'label' => __('Private storage path', 'chada-aio'), 'description' => __('Absolute private directory outside the web document root. Leave blank for the private sibling-directory default. Existing archives must be moved deliberately before changing storage.', 'chada-aio'), 'type' => 'text', 'required' => false],
            'backup_retention' => ['tab' => 'storage', 'label' => __('Backup retention', 'chada-aio'), 'description' => __('Leave at zero to retain verified backup history. A positive value rotates older unprotected backups after successful verification; protected recovery backups remain.', 'chada-aio'), 'type' => 'number', 'required' => true, 'min' => 0, 'max' => 100000],
            'migration_retention' => ['tab' => 'storage', 'label' => __('Migration retention', 'chada-aio'), 'description' => __('Leave at zero to retain migration history. A positive value rotates older entries after successful verification; website content is never removed by history retention.', 'chada-aio'), 'type' => 'number', 'required' => true, 'min' => 0, 'max' => 100000],
            'default_backup' => ['tab' => 'general', 'label' => __('Default backup type', 'chada-aio'), 'description' => __('Choose the default scope shown when starting a backup.', 'chada-aio'), 'type' => 'select', 'required' => true, 'options' => [['value' => 'all', 'label' => __('Backup All', 'chada-aio')]]],
            'exclude_paths' => ['tab' => 'backup', 'label' => __('Excluded paths', 'chada-aio'), 'description' => __('One exact path beneath wp-content per line, for example uploads/private-cache. No wildcards or parent traversal.', 'chada-aio'), 'type' => 'textarea', 'required' => false, 'rows' => 3],
            'quality' => ['tab' => 'optimization', 'label' => __('Image quality', 'chada-aio'), 'description' => __('Quality used when supported images are re-encoded.', 'chada-aio'), 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 100],
            'resolution' => ['tab' => 'optimization', 'label' => __('Image resolution', 'chada-aio'), 'description' => __('Choose a standard image size or select Custom to enter width and height. The selection is a display preference; width and height remain the saved values.', 'chada-aio'), 'type' => 'select', 'required' => true, 'options' => self::resolution_options(), 'persisted' => false],
            'landscape_width' => ['tab' => 'optimization', 'label' => __('Landscape width', 'chada-aio'), 'description' => __('Maximum width used for landscape and square image fits.', 'chada-aio'), 'type' => 'number', 'required' => true, 'min' => 64, 'max' => 7680],
            'landscape_height' => ['tab' => 'optimization', 'label' => __('Landscape height', 'chada-aio'), 'description' => __('Maximum height used for portrait and square image fits.', 'chada-aio'), 'type' => 'number', 'required' => true, 'min' => 64, 'max' => 15360],
            'cleanup_days' => ['tab' => 'optimization', 'label' => __('Cleanup age in days', 'chada-aio'), 'description' => __('Only records older than this age are eligible for cleanup previews.', 'chada-aio'), 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 1095],
            'keep_revisions' => ['tab' => 'optimization', 'label' => __('Revisions to keep', 'chada-aio'), 'description' => __('Keep this many recent revisions per post before older revisions become eligible.', 'chada-aio'), 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 100],
            'remove_settings' => ['tab' => 'privacy', 'label' => __('Remove settings on uninstall', 'chada-aio'), 'description' => __('On uninstall, remove Chada options and database indexes. Archive files and retained image originals always remain for deliberate server cleanup.', 'chada-aio'), 'type' => 'checkbox', 'required' => false],
        ];
    }

    /** Return render-ready field definitions for one Settings tab or Wizard step. */
    public static function field_definitions(string $tab, array $settings): array {
        if (!isset(self::TABS[$tab])) { return []; }
        $schema = self::field_schema(); $fields = [];
        if ($tab === 'optimization') {
            $field = $schema['resolution']; $field['key'] = 'resolution'; $field['value'] = self::selected_resolution($settings); $fields[] = $field;
        }
        foreach (self::TABS[$tab] as $key) {
            $field = $schema[$key]; $field['key'] = $key; $field['value'] = $settings[$key] ?? '';
            if ($key === 'storage_path') {
                $field['guidance'] = CHADA_AIO_Storage::path_guidance();
                if ((string) $field['value'] === '') { $field['placeholder'] = $field['guidance']['default']; }
            }
            if ($tab === 'optimization' && in_array($key, ['landscape_width', 'landscape_height'], true)) { $field['readonly'] = self::selected_resolution($settings) !== 'custom'; }
            $fields[] = $field;
        }
        return $fields;
    }

    public static function all(): array {
        return array_merge(self::defaults(), (array) get_option(self::OPTION, []));
    }

    public static function get(string $key) { return self::all()[$key] ?? null; }

    public static function key(string $suffix): string { return CHADA_AIO_PREFIX . ltrim($suffix, '_'); }

    public static function meta(string $suffix): string { return '_' . self::key($suffix); }

    public static function setup_pending(): bool {
        return !self::setup_complete() && (bool) get_option(self::key(self::SETUP_PENDING), false);
    }

    public static function setup_complete(): bool { return (bool) get_option(self::key(self::SETUP_COMPLETE), false); }

    public static function mark_setup_pending(): void {
        if (!self::setup_complete()) { update_option(self::key(self::SETUP_PENDING), 1, false); }
    }

    public static function complete_setup(): void {
        update_option(self::key(self::SETUP_COMPLETE), 1, false);
        delete_option(self::key(self::SETUP_PENDING));
    }

    public static function table(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix . self::key($suffix);
    }

    /** Return field-level validation failures without changing persisted settings. */
    public static function validation_errors(?array $settings = null, ?string $tab = null): array {
        $schema = self::field_schema(); $values = array_merge(self::defaults(), $settings === null ? (array) get_option(self::OPTION, []) : $settings);
        $keys = $tab === null ? array_keys(self::defaults()) : (self::TABS[$tab] ?? []); $errors = [];
        foreach ($keys as $key) {
            try { self::normalize_value($key, $values[$key] ?? null); }
            catch (\Throwable $error) { $errors[] = ['key' => $key, 'tab' => $schema[$key]['tab'], 'label' => $schema[$key]['label'], 'message' => $error->getMessage()]; }
        }
        return $errors;
    }

    /** Return settings and effective private-storage readiness failures for Dashboard and Setup Wizard use. */
    public static function readiness_errors(): array {
        $errors = self::validation_errors(); $storage = CHADA_AIO_Storage::status();
        if (!$storage['ready'] && !array_filter($errors, static function (array $error): bool { return $error['key'] === 'storage_path'; })) {
            $schema = self::field_schema()['storage_path']; $errors[] = ['key' => 'storage_path', 'tab' => 'storage', 'label' => $schema['label'], 'message' => (string) ($storage['error'] ?? __('Private storage is not ready.', 'chada-aio'))];
        }
        return $errors;
    }

    /** Normalize and validate one settings value using the shared field contract.
     * @param mixed $value Raw value received from the Settings REST request or stored options.
     */
    private static function normalize_value(string $key, $value) {
        $schema = self::field_schema();
        if (!isset($schema[$key])) { throw new \RuntimeException(__('Unknown setting.', 'chada-aio')); }
        $field = $schema[$key]; $label = $field['label'];
        if (!empty($field['required']) && ($value === null || (is_string($value) && trim($value) === ''))) {
            // translators: %s is the settings field label.
            throw new \RuntimeException(sprintf(__('%s is required.', 'chada-aio'), $label));
        }
        if ($key === 'storage_path') {
            // translators: %s is the settings field label.
            if (!is_string($value)) { throw new \RuntimeException(sprintf(__('%s must be text.', 'chada-aio'), $label)); }
            $value = trim($value);
            if ($value !== '') { CHADA_AIO_Storage::validate_root($value); }
            return $value;
        }
        if ($key === 'exclude_paths') {
            // translators: %s is the settings field label.
            if (!is_string($value)) { throw new \RuntimeException(sprintf(__('%s must be text.', 'chada-aio'), $label)); }
            $paths = preg_split('/\r?\n/', $value); $normalized = [];
            foreach ($paths as $path) { if (trim($path) !== '') { $normalized[] = CHADA_AIO_Storage::relative(trim($path)); } }
            return implode("\n", $normalized);
        }
        if ($key === 'default_backup') {
            if (!is_string($value)) { throw new \RuntimeException(__('Default backup has an invalid value.', 'chada-aio')); }
            $allowed = array_map(static function (array $option): string { return (string) ($option['value'] ?? ''); }, $field['options'] ?? []);
            // translators: %s is the settings field label.
            if (!in_array((string) $value, $allowed, true)) { throw new \RuntimeException(sprintf(__('%s has an invalid value.', 'chada-aio'), $label)); }
            return (string) $value;
        }
        if ($key === 'resolution') {
            if (!is_string($value)) { throw new \RuntimeException(__('Image resolution has an invalid value.', 'chada-aio')); }
            $allowed = array_keys(self::resolution_presets());
            if (!in_array((string) $value, $allowed, true)) { throw new \RuntimeException(__('Image resolution has an invalid value.', 'chada-aio')); }
            return (string) $value;
        }
        if ($key === 'remove_settings') {
            if (is_bool($value)) { return $value; }
            if (in_array($value, [0, 1, '0', '1'], true)) { return (bool) $value; }
            // translators: %s is the settings field label.
            throw new \RuntimeException(sprintf(__('%s must be enabled or disabled.', 'chada-aio'), $label));
        }
        $candidate = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');
        // translators: %1$s is the settings field label, %2$d is the minimum, and %3$d is the maximum.
        if (!preg_match('/^-?\d+$/D', $candidate)) { throw new \RuntimeException(sprintf(__('%1$s must be a whole number between %2$d and %3$d.', 'chada-aio'), $label, $field['min'], $field['max'])); }
        $value = (int) $candidate;
        // translators: %1$s is the settings field label, %2$d is the minimum, and %3$d is the maximum.
        if ($value < $field['min'] || $value > $field['max']) { throw new \RuntimeException(sprintf(__('%1$s must be between %2$d and %3$d.', 'chada-aio'), $label, $field['min'], $field['max'])); }
        return $value;
    }

    /** Save only keys belonging to one tab; validate before changing any option. */
    public static function save(string $tab, array $input): array {
        if (!isset(self::TABS[$tab])) { throw new \RuntimeException(__('Unknown settings tab.', 'chada-aio')); }
        if (array_key_exists('resolution', $input)) {
            $resolution = self::normalize_value('resolution', $input['resolution']);
            $preset = self::resolution_presets()[$resolution];
            if ($resolution !== 'custom') {
                $input['landscape_width'] = $preset['width']; $input['landscape_height'] = $preset['height'];
            }
        }
        $settings = self::all(); $normalized = [];
        foreach (self::TABS[$tab] as $key) {
            if (!array_key_exists($key, $input)) { continue; }
            $value = self::normalize_value($key, $input[$key]);
            if ($key === 'storage_path') {
                if (defined('CHADA_AIO_STORAGE_DIR') && $value !== $settings[$key]) { throw new \RuntimeException(__('Private storage is configured by CHADA_AIO_STORAGE_DIR in wp-config.php.', 'chada-aio')); }
                if ($value !== $settings[$key] && CHADA_AIO_Catalog::count() > 0) {
                    throw new \RuntimeException(__('Storage cannot change while archives exist. Download and remove them first.', 'chada-aio'));
                }
                if ($value !== $settings[$key]) {
                    $status = CHADA_AIO_Storage::status();
                    if ($status['ready']) {
                        foreach (['archives', 'jobs', 'uploads', 'originals'] as $folder) {
                            if (count(scandir($status['path'] . '/' . $folder)) > 2) { throw new \RuntimeException(__('Existing archives, jobs, uploads or originals require a deliberate server storage move.', 'chada-aio')); }
                        }
                    }
                }
            }
            $normalized[$key] = $value;
        }
        foreach ($normalized as $key => $value) { $settings[$key] = $value; }
        $errors = self::validation_errors($settings);
        if ($errors) { throw new \RuntimeException((string) $errors[0]['message']); }
        update_option(self::OPTION, $settings, false);
        return $settings;
    }

    public static function identity(): string {
        $id = get_option(self::key('site_id'));
        if (!$id) { $id = wp_generate_uuid4(); add_option(self::key('site_id'), $id, '', false); }
        return (string) $id;
    }

    /** Half-open calendar ranges in the site's timezone. */
    public static function period(array $input): array {
        $mode = $input['period'] ?? 'all';
        if (!in_array($mode, ['all', 'year', 'quarter'], true)) { throw new \RuntimeException(__('Invalid period.', 'chada-aio')); }
        $year = self::period_integer($input['year'] ?? wp_date('Y'), 1970, 2200);
        $quarter = self::period_integer($input['quarter'] ?? 1, 1, 4);
        if ($mode === 'all') { return ['mode' => 'all', 'start' => null, 'end' => null]; }
        $month = $mode === 'quarter' ? (($quarter - 1) * 3 + 1) : 1;
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), wp_timezone());
        $end = $start->modify($mode === 'quarter' ? '+3 months' : '+1 year');
        return ['mode' => $mode, 'year' => $year, 'quarter' => $quarter,
            'start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')];
    }

    /** @param mixed $value */
    private static function period_integer($value, int $minimum, int $maximum): int {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^-?\d+$/D', (string) $value)) {
            throw new \RuntimeException(__('Invalid year or quarter.', 'chada-aio'));
        }
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) { throw new \RuntimeException(__('Invalid year or quarter.', 'chada-aio')); }
        return $integer;
    }
}
