<?php
/** Private filesystem boundary, atomic journals and safe path handling. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as REST JSON or escaped in CHADA_AIO_Admin_Menu::render; no exception message is printed as raw HTML.

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.unlink_unlink -- Private local streams require seek, locks and atomic replacement; paths are confined by CHADA_AIO_Storage. Remote filesystem transports cannot provide these guarantees.


final class CHADA_AIO_Storage {
    private static $root_cache = '';
    private static $space_free;
    private static $space_reserved = 0;

    public static function normalize(string $path): string {
        $path = wp_normalize_path($path);
        return $path === '/' || preg_match('~^[A-Za-z]:/$~', $path) ? $path : rtrim($path, '/');
    }

    /** Return non-mutating, host-aware guidance for the Private storage path field. */
    public static function path_guidance(): array {
        $wordpress_root = CHADA_AIO_Locations::wordpress_root();
        $filesystem_root = DIRECTORY_SEPARATOR === '\\' ? self::windows_root($wordpress_root) : '/';
        $parent = dirname($wordpress_root);
        if ($parent === '' || $parent === '.') { $parent = $filesystem_root; }
        $example = self::display_path($parent . '/chada-aio-private-example');
        $site_id = (string) get_option(CHADA_AIO_Config::key('site_id'), '');
        $suffix = $site_id !== '' ? substr($site_id, 0, 8) : '<site-id>';
        $default = defined('CHADA_AIO_STORAGE_DIR') ? self::display_path((string) CHADA_AIO_STORAGE_DIR)
            : self::display_path($parent . '/chada-aio-private-' . $suffix);
        return ['filesystem_root' => $filesystem_root, 'example' => $example, 'default' => $default,
            'blank_note' => __('The displayed default is a placeholder only and is not submitted. Leave this blank to use Chada AIO’s private sibling-directory default automatically.', 'chada-aio'),
            'constant_note' => defined('CHADA_AIO_STORAGE_DIR') ? __('The CHADA_AIO_STORAGE_DIR value in wp-config.php controls the effective path on this site.', 'chada-aio') : ''];
    }

    /** Convert an absolute WordPress root to a display-safe Windows filesystem root. */
    private static function windows_root(string $path): string {
        return preg_match('~^([A-Za-z]):/~', $path, $matches) ? strtoupper($matches[1]) . ':\\' : '\\';
    }

    /** Format a normalized path using the host operating system’s separator. */
    private static function display_path(string $path): string {
        return DIRECTORY_SEPARATOR === '\\' ? str_replace('/', '\\', $path) : $path;
    }

    public static function inside(string $path, string $root): bool {
        $path = self::normalize($path); $root = self::normalize($root);
        if (DIRECTORY_SEPARATOR === '\\') { $path = strtolower($path); $root = strtolower($root); }
        return $root !== '' && ($path === $root || strpos($path, rtrim($root, '/') . '/') === 0);
    }

    public static function relative(string $path): string {
        if ($path === '' || strpos($path, "\0") !== false || strpos($path, '\\') !== false
            || preg_match('~(^/|^[a-zA-Z]:|://|(^|/)\.\.?(/|$))~', $path)) {
            throw new \RuntimeException(__('Unsafe relative path.', 'chada-aio'));
        }
        return $path;
    }

    /** Resolve only beneath a trusted root and refuse symlinked path components. */
    public static function path(string $root, string $relative): string {
        $relative = self::relative($relative);
        $path = self::normalize($root);
        foreach (explode('/', $relative) as $part) {
            if ($part === '') { throw new \RuntimeException(__('Empty path component.', 'chada-aio')); }
            $path .= '/' . $part;
            if (is_link($path)) { throw new \RuntimeException(__('Symlink paths are not supported.', 'chada-aio')); }
        }
        if (!self::inside($path, $root)) { throw new \RuntimeException(__('Path escapes its storage root.', 'chada-aio')); }
        return $path;
    }

    public static function validate_root(string $path): void {
        if (!preg_match('~^(/|[A-Za-z]:[/\\\\])~', $path) || strpos($path, '..') !== false) {
            throw new \RuntimeException(__('Storage must be an absolute path without parent traversal.', 'chada-aio'));
        }
        if (dirname(rtrim($path, '/\\')) === rtrim($path, '/\\') || strlen(self::normalize($path)) < 5) {
            throw new \RuntimeException(__('Use a dedicated private directory, never a filesystem root.', 'chada-aio'));
        }
        $resolved = CHADA_AIO_Locations::directory($path);
        foreach (array_merge(CHADA_AIO_Locations::public_roots(), CHADA_AIO_Locations::document_roots()) as $public_root) {
            if (self::inside($resolved, $public_root) || self::inside($public_root, $resolved)) {
                throw new \RuntimeException(__('Choose a dedicated private directory outside WordPress and the web document root.', 'chada-aio'));
            }
        }
    }

    public static function root(): string {
        $configured = defined('CHADA_AIO_STORAGE_DIR') ? CHADA_AIO_STORAGE_DIR : CHADA_AIO_Config::get('storage_path');
        $root = $configured ?: dirname(CHADA_AIO_Locations::wordpress_root()) . '/chada-aio-private-' . substr(CHADA_AIO_Config::identity(), 0, 8);
        if (self::$root_cache !== '' && self::normalize($root) === self::$root_cache) { return self::$root_cache; }
        self::$space_free = null; self::$space_reserved = 0;
        self::validate_root($root);
        self::mkdir($root);
        $marker = $root . '/chada-site.json';
        if (is_file($marker) && (self::read($marker)['site_id'] ?? '') !== CHADA_AIO_Config::identity()) {
            throw new \RuntimeException(__('This private directory belongs to another site. Choose a separate directory.', 'chada-aio'));
        }
        if (!is_file($marker)) { self::json($marker, ['site_id' => CHADA_AIO_Config::identity()]); }
        foreach (['archives', 'jobs', 'uploads', 'originals', 'recovery'] as $dir) { self::mkdir($root . '/' . $dir); }
        self::$root_cache = self::normalize($root); return self::$root_cache;
    }

    public static function mkdir(string $path): void {
        CHADA_AIO_Locations::directory($path);
        $existed = is_dir($path);
        if (!is_dir($path) && !wp_mkdir_p($path)) { throw new \RuntimeException(__('Cannot create private directory: check server permissions.', 'chada-aio')); }
        if (!is_writable($path)) { throw new \RuntimeException(__('Private directory is not writable.', 'chada-aio')); }
        if (!$existed) {
            $mode = 0700;
            foreach (CHADA_AIO_Locations::public_roots() as $public_root) { if (self::inside($path, $public_root)) { $mode = 0755; break; } }
            if (!chmod($path, $mode)) { throw new \RuntimeException(__('Could not set permissions on the new directory.', 'chada-aio')); }
        }
    }

    public static function space(int $needed): void {
        $root = self::root();
        if (self::$space_free === null) { self::$space_free = disk_free_space($root); }
        if (self::$space_free === false || self::$space_free - self::$space_reserved < $needed + 67108864) {
            throw new \RuntimeException(__('Insufficient verified free disk space.', 'chada-aio'));
        }
        self::$space_reserved += $needed;
    }

    public static function write(string $path, string $data): void {
        self::file_target($path);
        self::mkdir(dirname($path));
        $temp = $path . '.' . wp_generate_password(12, false, false) . '.tmp';
        if (file_put_contents($temp, $data, LOCK_EX) !== strlen($data)) {
            @unlink($temp); throw new \RuntimeException(__('Could not write the complete private file.', 'chada-aio'));
        }
        @chmod($temp, 0600);
        if (!rename($temp, $path)) { @unlink($temp); throw new \RuntimeException(__('Atomic file replacement failed.', 'chada-aio')); }
    }

    /** Append a private journal record while holding a process-safe file lock. */
    public static function append(string $path, string $data): void {
        self::file_target($path);
        self::mkdir(dirname($path));
        $handle = fopen($path, 'ab');
        if (!$handle || !flock($handle, LOCK_EX)) { if ($handle) { fclose($handle); } throw new \RuntimeException(__('Could not open the private capture index.', 'chada-aio')); }
        try {
            $written = 0; $length = strlen($data);
            while ($written < $length) {
                $count = fwrite($handle, substr($data, $written));
                if ($count === false || $count === 0) { throw new \RuntimeException(__('Could not append the private capture index.', 'chada-aio')); }
                $written += $count;
            }
        } finally { flock($handle, LOCK_UN); fclose($handle); }
        @chmod($path, 0600);
    }

    public static function json(string $path, array $data): void {
        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) { throw new \RuntimeException(__('JSON encoding failed.', 'chada-aio')); }
        if (strlen($json) > 33554432) { throw new \RuntimeException(__('Metadata exceeds the 32 MiB processing ceiling. Use a smaller content selection.', 'chada-aio')); }
        self::write($path, $json);
    }

    public static function read(string $path): array {
        self::file_target($path);
        if (!is_file($path) || filesize($path) > 33554432) { throw new \RuntimeException(__('Missing or oversized metadata.', 'chada-aio')); }
        $value = json_decode(file_get_contents($path), true);
        if (!is_array($value) || json_last_error() !== JSON_ERROR_NONE) { throw new \RuntimeException(__('Invalid metadata.', 'chada-aio')); }
        return $value;
    }

    /** Validate a local regular-file destination and its ancestors before opening a stream. */
    public static function file_target(string $path): void {
        CHADA_AIO_Locations::directory(dirname($path));
        if (strpos($path, "\0") !== false || is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException(__('The file destination is unsafe or is not a regular file.', 'chada-aio'));
        }
    }

    /** Delete only a known child of this plugin's private storage, never its root. */
    public static function remove(string $path): void {
        $root = self::root();
        if (!self::inside($path, $root) || self::normalize($path) === $root || is_link($path)) {
            throw new \RuntimeException(__('Refusing deletion outside private storage.', 'chada-aio'));
        }
        if (!file_exists($path)) { return; }
        $path = self::path($root, substr(self::normalize($path), strlen($root) + 1));
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) { self::remove($entry->getPathname()); }
            if (!rmdir($path)) { throw new \RuntimeException(__('Directory cleanup failed.', 'chada-aio')); }
        } elseif (!unlink($path)) { throw new \RuntimeException(__('File cleanup failed.', 'chada-aio')); }
    }

    public static function id(string $id): string {
        if (!preg_match('/^[a-f0-9-]{36}$/D', $id)) { throw new \RuntimeException(__('Invalid record identifier.', 'chada-aio')); }
        return $id;
    }

    public static function status(): array {
        try { $root = self::root(); return ['ready' => true, 'path' => $root, 'free' => disk_free_space($root)]; }
        catch (\Throwable $error) { return ['ready' => false, 'error' => $error->getMessage()]; }
    }
}
