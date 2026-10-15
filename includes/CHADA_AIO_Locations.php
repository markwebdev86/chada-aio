<?php
/** WordPress-aware local filesystem locations without changing the site's filesystem transport. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions become escaped administrator notices or REST JSON errors.

final class CHADA_AIO_Locations {
    /** Resolve the local WordPress installation directory. @return string Validated directory. */
    public static function wordpress_root(): string { return self::directory((string) self::filesystem()->abspath(), true); }

    /** Resolve the configured content directory. @return string Validated directory. */
    public static function content_root(): string { return self::directory((string) self::filesystem()->wp_content_dir(), true); }

    /** Resolve the configured plugin directory, including a destination not created yet. @return string Local directory. */
    public static function plugins_root(): string { return self::directory((string) self::filesystem()->wp_plugins_dir()); }

    /** Resolve a theme's registered directory. @param string $slug Theme directory name. @return string Local root. */
    public static function theme_root(string $slug = ''): string {
        $themes = $slug !== '' ? wp_get_themes() : [];
        $theme = $themes[$slug] ?? null;
        $root = is_object($theme) && method_exists($theme, 'get_theme_root') ? $theme->get_theme_root() : get_theme_root($slug);
        return self::directory((string) $root);
    }

    /** Resolve must-use configuration; WordPress has no dedicated filesystem accessor for this directory. */
    public static function mu_plugins_root(): string {
        return self::directory((string) self::filesystem()->find_folder(WPMU_PLUGIN_DIR));
    }

    /** Resolve uploads without creating directories or accepting a remote stream wrapper. @return string Local root. */
    public static function uploads_root(): string {
        $uploads = wp_upload_dir(null, false, true);
        if (!empty($uploads['error'])) { throw new \RuntimeException(__('The configured uploads directory is unavailable.', 'chada-aio')); }
        return self::directory((string) ($uploads['basedir'] ?? ''));
    }

    /** Return local roots that can contain publicly served WordPress content. @return array Validated directories. */
    public static function public_roots(): array {
        $roots = [self::wordpress_root(), self::content_root(), self::plugins_root(), self::mu_plugins_root(), self::uploads_root(), self::theme_root()];
        foreach (array_keys(wp_get_themes()) as $slug) { $roots[] = self::theme_root((string) $slug); }
        return array_values(array_unique($roots));
    }

    /** Resolve public home/document boundaries without relying on HTTP-only SCRIPT_FILENAME inference. */
    public static function document_roots(): array {
        $wordpress = self::wordpress_root(); $roots = [$wordpress];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Type-checked below, unslashed, and strictly validated as an absolute local directory before use.
        $document = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!is_string($document)) { throw new \RuntimeException(__('The server document root cannot be validated.', 'chada-aio')); }
        if ($document !== '') { $roots[] = self::directory(wp_unslash($document), true); }
        $site = wp_parse_url(site_url()); $site_path = is_array($site) ? rtrim((string) ($site['path'] ?? ''), '/') : '';
        if ($site_path !== '' && substr($wordpress, -strlen($site_path)) === $site_path) {
            $roots[] = self::directory(substr($wordpress, 0, -strlen($site_path)), true);
        } elseif ($document === '' && $site_path !== '') {
            throw new \RuntimeException(__('The public document root is unavailable. Configure DOCUMENT_ROOT on the server before using private storage.', 'chada-aio'));
        }
        return array_values(array_unique($roots));
    }

    /** Validate an absolute local path and every existing ancestor; this method never creates directories. */
    public static function directory(string $path, bool $must_exist = false): string {
        $path = wp_normalize_path($path);
        if ($path === '' || strpos($path, "\0") !== false || strpos($path, '://') !== false
            || !preg_match('~^(?:/|[A-Za-z]:/)~', $path) || preg_match('~(^|/)\.\.?(/|$)~', $path)) {
            throw new \RuntimeException(__('A WordPress filesystem location is invalid or non-local.', 'chada-aio'));
        }
        $path = CHADA_AIO_Storage::normalize($path); $cursor = $path;
        while (true) {
            if (is_link($cursor)) { throw new \RuntimeException(__('Symlinked filesystem locations are not supported.', 'chada-aio')); }
            $parent = CHADA_AIO_Storage::normalize(dirname($cursor));
            if ($parent === $cursor) { break; }
            $cursor = $parent;
        }
        $probe = $path;
        while (!file_exists($probe) && dirname($probe) !== $probe) { $probe = dirname($probe); }
        $resolved = realpath($probe);
        if ($resolved === false || !is_dir($probe) || ($must_exist && !is_dir($path))) {
            throw new \RuntimeException(__('A required local WordPress directory is unavailable.', 'chada-aio'));
        }
        return CHADA_AIO_Storage::normalize(wp_normalize_path($resolved) . substr($path, strlen(CHADA_AIO_Storage::normalize($probe))));
    }

    /** Use a local direct resolver; preserve the global filesystem object and avoid credential prompts. */
    private static function filesystem(): \WP_Filesystem_Direct {
        if (!class_exists('WP_Filesystem_Direct')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        }
        return new \WP_Filesystem_Direct(null);
    }
}
