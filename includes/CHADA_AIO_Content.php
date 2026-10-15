<?php
/** Shared coverage helpers and safe WordPress uploads-path resolution. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These translated messages become REST errors or are escaped by the administrator UI; this helper never prints HTML.

final class CHADA_AIO_Content {
    /** Build the minimum runtime file scope for an All backup or migration package. */
    public static function runtime_scope(array $args = []): array {
        if (array_key_exists('scope_paths', $args)) {
            $paths = [];
            foreach ((array) $args['scope_paths'] as $path) { $path = CHADA_AIO_Storage::relative(wp_normalize_path((string) $path)); $paths[$path] = true; }
            $paths = array_keys($paths); sort($paths);
            $mode = ($args['scope_mode'] ?? '') === 'complete' ? 'complete' : 'targeted';
            return ['paths' => $paths, 'coverage' => ['mode' => $mode, 'paths' => $paths]];
        }
        $paths = []; $plugins = []; $themes = []; $dropins = [];
        if (self::available_uploads_root()) { $paths['uploads'] = true; }
        if (is_dir(CHADA_AIO_Package::content_path('mu-plugins'))) { $paths['mu-plugins'] = true; }
        foreach ((array) get_option('active_plugins', []) as $plugin) {
            $parts = explode('/', trim(wp_normalize_path((string) $plugin), '/')); $root = $parts[0] ?? '';
            if ($root === '') { continue; }
            $path = 'plugins/' . $root; CHADA_AIO_Storage::relative($path);
            if (file_exists(CHADA_AIO_Package::content_path($path))) { $paths[$path] = true; $plugins[] = $path; }
        }
        $theme = wp_get_theme(get_stylesheet(), CHADA_AIO_Locations::theme_root(get_stylesheet()));
        if ($theme->exists() && !$theme->errors()) {
            foreach (array_filter([$theme->get_stylesheet(), $theme->get_template()]) as $slug) {
                $path = 'themes/' . sanitize_key($slug); CHADA_AIO_Storage::relative($path);
                if (is_dir(CHADA_AIO_Package::content_path($path))) { $paths[$path] = true; $themes[] = $path; }
            }
        }
        foreach (['advanced-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'object-cache.php', 'php-error.php',
            'sunrise.php', 'fatal-error-handler.php', 'blog-deleted.php', 'blog-inactive.php'] as $file) {
            if (is_file(CHADA_AIO_Package::content_path($file))) { $paths[$file] = true; $dropins[] = $file; }
        }
        $paths = array_keys($paths); sort($paths); sort($plugins); sort($themes); sort($dropins);
        return ['paths' => $paths, 'coverage' => ['mode' => 'runtime', 'paths' => $paths, 'plugins' => $plugins, 'themes' => $themes, 'dropins' => $dropins]];
    }

    /** Build the broader scope used to protect complete recovery captures. */
    public static function complete_scope(): array {
        $paths = [];
        foreach (new \FilesystemIterator(CHADA_AIO_Locations::content_root(), \FilesystemIterator::SKIP_DOTS) as $file) { $paths[] = $file->getFilename(); }
        foreach (['plugins', 'mu-plugins', 'themes'] as $kind) { if (is_dir(CHADA_AIO_Package::content_path($kind))) { $paths[] = $kind; } }
        foreach (array_keys(wp_get_themes()) as $slug) {
            if (CHADA_AIO_Locations::theme_root((string) $slug) !== CHADA_AIO_Locations::theme_root()) { $paths[] = 'themes/' . $slug; }
        }
        if (self::available_uploads_root() && !in_array('uploads', $paths, true)) { $paths[] = 'uploads'; }
        $paths = array_values(array_unique($paths)); sort($paths);
        return ['paths' => $paths, 'coverage' => ['mode' => 'complete', 'paths' => $paths]];
    }

    /** Verify that a manifest path belongs to its declared runtime or targeted recovery scope. */
    public static function coverage_allows(string $relative, array $coverage): bool {
        foreach ((array) ($coverage['paths'] ?? []) as $path) {
            $path = rtrim((string) $path, '/');
            if ($relative === $path || strpos($relative, $path . '/') === 0) { return true; }
        }
        return false;
    }

    /** Return existing attachment originals and derivatives relative to the resolved uploads root. */
    public static function attachment_files(int $id): array {
        $primary = get_attached_file($id, true);
        if (!$primary) { throw new \RuntimeException(__('An attachment has no local file.', 'chada-aio')); }
        $root = self::uploads_root(); $primary = self::validated_upload_file((string) $primary, $root);
        $metadata = wp_get_attachment_metadata($id); $paths = [$primary];
        foreach (($metadata['sizes'] ?? []) as $size) { if (!empty($size['file'])) { $paths[] = dirname($primary) . '/' . basename((string) $size['file']); } }
        if (!empty($metadata['original_image'])) { $paths[] = dirname($primary) . '/' . basename((string) $metadata['original_image']); }
        $backups = get_post_meta($id, '_wp_attachment_backup_sizes', true);
        foreach (is_array($backups) ? $backups : [] as $size) { if (!empty($size['file'])) { $paths[] = dirname($primary) . '/' . basename((string) $size['file']); } }
        $files = [];
        foreach (array_unique($paths) as $path) {
            $path = self::validated_upload_file((string) $path, $root);
            $relative = ltrim(substr($path, strlen($root)), '/');
            $files[] = CHADA_AIO_Storage::relative($relative);
        }
        return $files;
    }

    /** Resolve an uploads-relative file, including previously saved originals using uploads/ prefixes. */
    public static function uploads_path(string $relative): string {
        $relative = self::uploads_relative($relative);
        if ($relative === '') { throw new \RuntimeException(__('Invalid uploads-relative path.', 'chada-aio')); }
        return CHADA_AIO_Storage::path(CHADA_AIO_Locations::uploads_root(), $relative);
    }

    /** Resolve the trusted local WordPress uploads root for package and image operations. */
    public static function uploads_root_path(): string { return self::uploads_root(); }

    /** Return the configured uploads URL for a validated uploads-relative file. */
    public static function uploads_url(string $relative = ''): string {
        CHADA_AIO_Locations::uploads_root(); $uploads = wp_upload_dir(null, false, true); $base = rtrim((string) ($uploads['baseurl'] ?? ''), '/');
        if ($base === '') { throw new \RuntimeException(__('The configured uploads URL is unavailable.', 'chada-aio')); }
        $relative = $relative === '' ? '' : self::uploads_relative($relative);
        if ($relative === '') { return $base; }
        return $base . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
    }

    /** Normalize an uploads-relative value and retain support for historical uploads/ prefixes. */
    public static function uploads_relative(string $relative): string {
        if (strpos($relative, 'uploads/') === 0) { $relative = substr($relative, 8); }
        return CHADA_AIO_Storage::relative(wp_normalize_path($relative));
    }

    /** Convert an existing attachment file to a validated uploads-relative path. */
    public static function uploads_relative_path(string $path): string {
        $root = self::uploads_root(); $resolved = self::validated_upload_file($path, $root);
        return CHADA_AIO_Storage::relative(ltrim(substr($resolved, strlen($root)), '/'));
    }

    /** Resolve and validate the real local WordPress uploads directory. */
    private static function uploads_root(): string {
        $root = CHADA_AIO_Locations::uploads_root();
        if (!is_dir($root)) { throw new \RuntimeException(__('The configured uploads directory is unavailable or non-local.', 'chada-aio')); }
        return $root;
    }

    /** Return the uploads root when one currently exists, allowing sites without media directories. */
    private static function available_uploads_root(): ?string {
        try { return self::uploads_root(); } catch (\Throwable $error) { return null; }
    }

    /** Require an existing local file under the trusted uploads directory. */
    private static function validated_upload_file(string $path, string $root): string {
        CHADA_AIO_Storage::file_target($path);
        $resolved = realpath($path);
        if (!$resolved || !is_file($resolved) || !CHADA_AIO_Storage::inside($resolved, $root)) {
            throw new \RuntimeException(__('An attachment file is missing or outside the configured uploads directory.', 'chada-aio'));
        }
        $relative = ltrim(substr(CHADA_AIO_Storage::normalize($resolved), strlen($root)), '/');
        return CHADA_AIO_Storage::path($root, CHADA_AIO_Storage::relative($relative));
    }
}
