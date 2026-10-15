<?php
/** Structured migration-import safety checks used before an administrator can apply a package. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are returned as REST JSON and escaped by the administrator UI.

final class CHADA_AIO_Import_Preflight {
    /** Return hard blockers, acknowledgement warnings, and measurable resource requirements for an import preview. */
    public static function run(string $archive, array $manifest, string $operation, string $type, array $files, int $collisions, bool $overwrite): array {
        $errors = []; $warnings = []; $checks = [];
        $add_error = static function (string $code, string $message, string $remedy = '') use (&$errors): void {
            $errors[] = ['code' => $code, 'message' => $message, 'remedy' => $remedy];
        };
        $add_warning = static function (string $code, string $message, string $remedy = '') use (&$warnings): void {
            $warnings[] = ['code' => $code, 'message' => $message, 'remedy' => $remedy];
        };
        $checks['single_site'] = !is_multisite();
        if (is_multisite()) { $add_error('multisite', __('Migration import requires a single-site WordPress installation.', 'chada-aio'), __('Use a single-site destination or export the required data manually.', 'chada-aio')); }
        $checks['zip'] = class_exists('ZipArchive');
        if (!class_exists('ZipArchive')) { $add_error('zip', __('The PHP ZIP extension is required to inspect and apply migration packages.', 'chada-aio'), __('Enable ZipArchive in PHP and retry the import.', 'chada-aio')); }
        $storage = CHADA_AIO_Storage::status(); $checks['storage'] = !empty($storage['ready']);
        if (!$checks['storage']) { $add_error('storage', (string) ($storage['error'] ?? __('Private storage is not ready.', 'chada-aio')), __('Resolve the private storage setting before importing.', 'chada-aio')); }
        $checks['archive_readable'] = is_file($archive) && is_readable($archive);
        if (!$checks['archive_readable']) { $add_error('archive', __('The uploaded migration package is missing or unreadable.', 'chada-aio'), __('Upload the package again and keep the browser open until upload sealing completes.', 'chada-aio')); }
        try { CHADA_AIO_Plugin::provider($operation, $type); CHADA_AIO_Plugin::file_permission($type); $checks['provider'] = true; }
        catch (\Throwable $error) { $checks['provider'] = false; $add_error('provider', $error->getMessage(), __('Install or activate the provider required by this package.', 'chada-aio')); }
        $archive_size = (int) (is_file($archive) ? filesize($archive) : 0); $expanded_size = (int) ($manifest['expanded_size'] ?? 0);
        $required_bytes = $archive_size + $expanded_size + 67108864;
        $free_bytes = (int) ($storage['free'] ?? (is_file($archive) ? disk_free_space(dirname($archive)) : 0));
        $checks['disk_space'] = $free_bytes >= $required_bytes;
        // translators: %s is the minimum free space required for the import.
        if (!$checks['disk_space']) { $add_error('disk_space', __('The destination does not have enough verified free disk space for the package and recovery journal.', 'chada-aio'), sprintf(__('Free at least %s before importing.', 'chada-aio'), size_format($required_bytes))); }
        if ($operation === 'import' && $type === 'all') {
            if (empty($manifest['entries']['database/schema.json'])) { $add_error('database_schema', __('The database schema payload is missing from this package.', 'chada-aio'), __('Create a new migration package from the source site.', 'chada-aio')); }
            else {
                try { $schema = json_decode(CHADA_AIO_Package::read_entry($archive, 'database/schema.json'), true, 512, JSON_THROW_ON_ERROR); CHADA_AIO_Database::validate_schema_data($schema, $manifest); }
                catch (\Throwable $error) { $add_error('database_schema', $error->getMessage(), __('Create a new migration package from the source site or repair its database payload.', 'chada-aio')); }
            }
        }
        $source_wordpress = (string) ($manifest['wordpress'] ?? ''); $destination_wordpress = (string) get_bloginfo('version');
        $source_php = (string) ($manifest['php'] ?? ''); $destination_php = PHP_VERSION;
        if ($source_wordpress === '' || $source_php === '') { $add_warning('source_runtime_unknown', __('The package does not include complete source runtime version metadata.', 'chada-aio'), __('Test the imported site and review plugin compatibility after completion.', 'chada-aio')); }
        elseif (version_compare($destination_wordpress, $source_wordpress, '<') || version_compare($destination_php, $source_php, '<')) {
            // translators: %1$s and %2$s are source versions; %3$s and %4$s are destination versions.
            $add_warning('runtime_mismatch', sprintf(__('The destination runtime is older than the source package (%1$s / PHP %2$s to %3$s / PHP %4$s).', 'chada-aio'), $source_wordpress, $source_php, $destination_wordpress, $destination_php), __('Update the destination runtime when possible and test the site after import.', 'chada-aio'));
        }
        if ($operation === 'import' && ($manifest['source_id'] ?? '') === CHADA_AIO_Config::identity()) {
            $add_warning('same_site', __('This package was created by the same site identity as the destination.', 'chada-aio'), __('Use Restore for same-site recovery, or continue only when this is an intentional repeat import.', 'chada-aio'));
        }
        // translators: %d is the number of existing destination file paths.
        if ($collisions > 0) { $add_warning('existing_paths', sprintf(__('%d destination file path(s) already exist.', 'chada-aio'), $collisions), __('Existing media is preserved. Component files are replaced only when overwrite is enabled.', 'chada-aio')); }
        if (($manifest['coverage']['mode'] ?? '') === 'complete') { $add_warning('complete_coverage', __('This package declares complete component coverage and may replace a broad set of destination files.', 'chada-aio'), __('Confirm the recovery archive before applying the package.', 'chada-aio')); }
        $component = apply_filters('chada_aio_import_preflight_component', ['errors' => [], 'warnings' => []], $archive, $manifest, $operation, $type, $files, $collisions, $overwrite);
        if (is_array($component)) {
            foreach ((array) ($component['errors'] ?? []) as $item) {
                if (is_array($item) && isset($item['code'], $item['message']) && is_string($item['code']) && is_string($item['message'])) {
                    $errors[] = ['code' => sanitize_key($item['code']), 'message' => sanitize_text_field($item['message']), 'remedy' => sanitize_text_field((string) ($item['remedy'] ?? ''))];
                }
            }
            foreach ((array) ($component['warnings'] ?? []) as $item) {
                if (is_array($item) && isset($item['code'], $item['message']) && is_string($item['code']) && is_string($item['message'])) {
                    $warnings[] = ['code' => sanitize_key($item['code']), 'message' => sanitize_text_field($item['message']), 'remedy' => sanitize_text_field((string) ($item['remedy'] ?? ''))];
                }
            }
        }
        $status = $errors ? 'blocked' : ($warnings ? 'warning' : 'ready');
        return ['status' => $status, 'errors' => $errors, 'warnings' => $warnings, 'checks' => $checks,
            'archive_bytes' => $archive_size, 'expanded_bytes' => $expanded_size, 'required_bytes' => $required_bytes, 'free_bytes' => $free_bytes,
            'source' => ['wordpress' => $source_wordpress, 'php' => $source_php, 'home' => (string) ($manifest['home'] ?? '')],
            'destination' => ['wordpress' => $destination_wordpress, 'php' => $destination_php, 'home' => home_url(), 'siteurl' => site_url()],
            'files' => count($files), 'existing_paths' => $collisions];
    }
}
