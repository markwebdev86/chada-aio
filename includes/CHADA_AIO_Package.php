<?php
/** Native Chada archive format, bounded extraction and manifest integrity checks. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions become REST JSON errors or are escaped by CHADA_AIO_Admin_Menu::render; never printed as HTML here.

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.unlink_unlink -- Private local streams require seek, locks and atomic replacement; paths are confined by CHADA_AIO_Storage. Remote filesystem transports cannot provide these guarantees.


final class CHADA_AIO_Package {
    /** Resolve a package-relative wp-content path, honoring WordPress's configured uploads root. */
    public static function content_path(string $relative): string {
        $relative = CHADA_AIO_Storage::relative(wp_normalize_path($relative));
        if ($relative === 'uploads') { return CHADA_AIO_Locations::uploads_root(); }
        if (strpos($relative, 'uploads/') === 0) { return CHADA_AIO_Content::uploads_path(substr($relative, 8)); }
        $parts = explode('/', $relative); $kind = array_shift($parts);
        if ($kind === 'plugins') { $root = CHADA_AIO_Locations::plugins_root(); }
        elseif ($kind === 'mu-plugins') { $root = CHADA_AIO_Locations::mu_plugins_root(); }
        elseif ($kind === 'themes') { $root = CHADA_AIO_Locations::theme_root((string) ($parts[0] ?? '')); }
        else { return CHADA_AIO_Storage::path(CHADA_AIO_Locations::content_root(), $relative); }
        return $parts ? CHADA_AIO_Storage::path($root, implode('/', $parts)) : $root;
    }

    public static function zip(): \ZipArchive {
        if (!class_exists('ZipArchive')) { throw new \RuntimeException(__('The PHP ZIP extension is required.', 'chada-aio')); }
        return new \ZipArchive();
    }

    /** Open a validated archive for one bounded stream-processing window. */
    public static function open_archive(string $archive): \ZipArchive {
        $zip = self::zip();
        if ($zip->open($archive, \ZipArchive::CHECKCONS) !== true) { throw new \RuntimeException(__('Invalid ZIP archive.', 'chada-aio')); }
        return $zip;
    }

    /** Confirm that a package still matches the sealed upload or generated archive binding. */
    public static function assert_archive_binding(string $archive, array $binding): void {
        $current = is_file($archive) ? stat($archive) : false;
        if (!$current || !is_readable($archive)) { throw new \RuntimeException(__('The migration package is missing or unreadable.', 'chada-aio')); }
        $expected = (array) ($binding['archive_stat'] ?? []);
        $same_stat = $expected && (int) ($current['size'] ?? -1) === (int) ($expected['size'] ?? -2)
            && (int) ($current['mtime'] ?? -1) === (int) ($expected['mtime'] ?? -2)
            && ((int) ($expected['inode'] ?? 0) === 0 || (int) ($current['ino'] ?? 0) === (int) $expected['inode']);
        if ($same_stat) { return; }
        $expected_hash = (string) ($binding['sha256'] ?? ''); $actual_hash = hash_file('sha256', $archive);
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected_hash) || $actual_hash === false || !hash_equals($expected_hash, $actual_hash)) {
            throw new \RuntimeException(__('Package changed after preview.', 'chada-aio'));
        }
    }

    /** Read one bounded metadata entry without expanding the package. */
    public static function read_entry(string $archive, string $name, int $limit = 33554432): string {
        CHADA_AIO_Storage::relative($name); $zip = self::open_archive($archive); $stat = $zip->statName($name);
        try {
            if (!$stat || (int) ($stat['size'] ?? -1) < 0 || (int) $stat['size'] > $limit) { throw new \RuntimeException(__('Missing or oversized package metadata.', 'chada-aio')); }
            $value = $zip->getFromName($name); if ($value === false) { throw new \RuntimeException(__('Package metadata could not be read.', 'chada-aio')); }
            return $value;
        } finally { $zip->close(); }
    }

    public static function excluded(string $relative): bool {
        $segments = explode('/', $relative);
        $excluded = ['.git', '.svn', 'node_modules', '.env', '.work', 'cache', 'upgrade', 'updraft',
            'ai1wm-backups', 'backups', 'backup', 'chada-aio-private', 'wflogs'];
        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), $excluded, true) || preg_match('/\.(log|sql|bak)$/i', $segment)) { return true; }
        }
        if (strpos($relative, '/docs/prompts/') !== false || strpos($relative, '/tests/') !== false) { return true; }
        foreach (preg_split('/\r?\n/', (string) CHADA_AIO_Config::get('exclude_paths')) as $path) {
            if ($path !== '' && ($relative === $path || strpos($relative, $path . '/') === 0)) { return true; }
        }
        return false;
    }

    /** Copy only regular files, with a checksum over the copied bytes. */
    public static function copy(string $source, string $target): array {
        CHADA_AIO_Storage::file_target($target); CHADA_AIO_Storage::file_target($target . '.partial');
        if (!is_file($source) || is_link($source) || !is_readable($source)) { throw new \RuntimeException(__('A required source file is missing, unreadable, or a symlink.', 'chada-aio')); }
        $before = stat($source); $size = $before['size'] ?? false;
        if ($size === false) { throw new \RuntimeException(__('A required source file cannot be measured.', 'chada-aio')); }
        if ($size > CHADA_AIO_Config::MAX_ENTRY) { throw new \RuntimeException(__('A file exceeds the documented 1 GiB per-file processing safety ceiling.', 'chada-aio')); }
        CHADA_AIO_Storage::space($size * 2); CHADA_AIO_Storage::mkdir(dirname($target));
        $temp = $target . '.partial'; $input = fopen($source, 'rb'); $output = fopen($temp, 'wb');
        if (!$input || !$output) { if ($input) { fclose($input); } if ($output) { fclose($output); } throw new \RuntimeException(__('A complete source file copy could not be started.', 'chada-aio')); }
        $hash = hash_init('sha256'); $bytes = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, CHADA_AIO_Config::STREAM_CHUNK);
                if ($chunk === false) { throw new \RuntimeException(__('A source file could not be read completely.', 'chada-aio')); }
                if ($chunk === '') { break; }
                $written = 0; $chunk_length = strlen($chunk);
                while ($written < $chunk_length) {
                    $length = fwrite($output, substr($chunk, $written));
                    if ($length === false || $length === 0) { throw new \RuntimeException(__('A complete source file copy could not be written.', 'chada-aio')); }
                    $written += $length;
                }
                hash_update($hash, $chunk); $bytes += strlen($chunk);
            }
        } catch (\Throwable $error) { @unlink($temp); throw $error; }
        finally { fclose($input); fclose($output); }
        $after = stat($source);
        if ($bytes !== $size || !$after || $after['size'] !== $before['size'] || $after['mtime'] !== $before['mtime']) {
            @unlink($temp); throw new \RuntimeException(__('A source file changed during capture; retry when the site is quiet.', 'chada-aio'));
        }
        $digest = hash_final($hash);
        if (!rename($temp, $target)) { @unlink($temp); throw new \RuntimeException(__('A complete source file copy could not be promoted.', 'chada-aio')); }
        return ['size' => $size, 'mtime' => (int) ($before['mtime'] ?? 0), 'sha256' => $digest];
    }

    /** Recursively expand one directory at a time, with bounded file capture per work window. */
    public static function collect(string $directory, array &$state): bool {
        if (!isset($state['stack'])) { $state['stack'] = (array) ($state['queue'] ?? []); unset($state['queue']); }
        $count = 0; $started = microtime(true); $index = []; $direct = !empty($state['direct']);
        while (!empty($state['stack']) && $count < CHADA_AIO_Config::FILE_BATCH
            && ($count === 0 || (microtime(true) - $started < CHADA_AIO_Config::STEP_SECONDS && CHADA_AIO_Runner::work_available()))) {
            $count++; $item = array_pop($state['stack']);
            $source = self::content_path($item);
            if (self::excluded($item)) { continue; }
            if (is_link($source)) { throw new \RuntimeException('Symlinks are unsupported: ' . $item); }
            if (is_dir($source)) {
                $children = [];
                foreach (new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS) as $entry) {
                    if ($entry->isLink()) { throw new \RuntimeException('Symlinks are unsupported: ' . $item); }
                    $children[] = $item . '/' . $entry->getFilename();
                }
                rsort($children); foreach ($children as $child) { $state['stack'][] = $child; }
            } else {
                if (!is_file($source) || !is_readable($source)) { throw new \RuntimeException(__('A selected source file is missing or unreadable.', 'chada-aio')); }
                $metadata = stat($source); $size = (int) ($metadata['size'] ?? -1);
                if ($size < 0 || $size > CHADA_AIO_Config::MAX_ENTRY) { throw new \RuntimeException(__('A file exceeds the documented 1 GiB per-file processing safety ceiling.', 'chada-aio')); }
                if ($direct) {
                    $index[] = ['name' => 'files/' . $item, 'relative' => $item, 'size' => $size, 'mtime' => (int) ($metadata['mtime'] ?? 0),
                        'inode' => (int) ($metadata['ino'] ?? 0)];
                } else {
                    $captured = self::copy($source, CHADA_AIO_Storage::path($directory, 'files/' . $item));
                    $index[] = ['name' => 'files/' . $item, 'size' => $captured['size'], 'sha256' => $captured['sha256']];
                    $state['bytes_read'] = (int) ($state['bytes_read'] ?? 0) + $size; $state['bytes_written'] = (int) ($state['bytes_written'] ?? 0) + $size;
                }
                $state['files'] = (int) ($state['files'] ?? 0) + 1; $state['bytes'] = ($state['bytes'] ?? 0) + $size;
            }
            $state['visited'] = ($state['visited'] ?? 0) + 1;
            if ($state['visited'] > CHADA_AIO_Config::MAX_ENTRIES) { throw new \RuntimeException(__('Archive exceeds the documented entry safety ceiling.', 'chada-aio')); }
        }
        if ($index) { $direct ? self::append_source_index($directory, $index) : self::append_index($directory, $index); }
        return empty($state['stack']);
    }

    private static function append_source_index(string $directory, array $entries): void {
        $lines = '';
        foreach ($entries as $entry) {
            $line = wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) { throw new \RuntimeException(__('The private source index could not be encoded.', 'chada-aio')); }
            $lines .= $line . "\n";
        }
        CHADA_AIO_Storage::append(dirname($directory) . '/source-index.jsonl', $lines);
    }

    private static function append_index(string $directory, array $entries): void {
        $lines = '';
        foreach ($entries as $entry) {
            $line = wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) { throw new \RuntimeException(__('The private capture index could not be encoded.', 'chada-aio')); }
            $lines .= $line . "\n";
        }
        CHADA_AIO_Storage::append(dirname($directory) . '/capture-index.jsonl', $lines);
    }

    /** Seed the resumable capture index with files already streamed into a recovery payload. */
    public static function seed_index(string $directory, array $entries): void { if ($entries) { self::append_index($directory, $entries); } }

    private static function indexed_entries(string $directory): array {
        $index = dirname($directory) . '/capture-index.jsonl'; $entries = [];
        if (!is_file($index)) { return $entries; }
        $handle = fopen($index, 'rb');
        if (!$handle) { throw new \RuntimeException(__('Could not read the private capture index.', 'chada-aio')); }
        try {
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if (!is_array($entry) || !isset($entry['name'], $entry['size'], $entry['sha256'])) { throw new \RuntimeException(__('The private capture index is invalid.', 'chada-aio')); }
                $name = CHADA_AIO_Storage::relative((string) $entry['name']);
                if (strpos($name, 'files/') !== 0 || !preg_match('/^[a-f0-9]{64}$/D', (string) $entry['sha256']) || (int) $entry['size'] < 0) {
                    throw new \RuntimeException(__('The private capture index contains invalid metadata.', 'chada-aio'));
                }
                $metadata = ['size' => (int) $entry['size'], 'sha256' => (string) $entry['sha256']];
                if (isset($entries[$name]) && $entries[$name] !== $metadata) { throw new \RuntimeException(__('The private capture index contains conflicting entries.', 'chada-aio')); }
                $entries[$name] = $metadata;
            }
        } finally { fclose($handle); }
        return $entries;
    }

    private static function source_entries(string $directory): array {
        $index = dirname($directory) . '/source-index.jsonl'; $entries = [];
        if (!is_file($index)) { return $entries; }
        $handle = fopen($index, 'rb');
        if (!$handle) { throw new \RuntimeException(__('Could not read the private source index.', 'chada-aio')); }
        try {
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if (!is_array($entry) || !isset($entry['name'], $entry['relative'], $entry['size'], $entry['mtime'])) {
                    throw new \RuntimeException(__('The private source index is invalid.', 'chada-aio'));
                }
                $name = CHADA_AIO_Storage::relative((string) $entry['name']); $relative = CHADA_AIO_Storage::relative((string) $entry['relative']);
                if (strpos($name, 'files/') !== 0 || substr($name, 6) !== $relative || (int) $entry['size'] < 0 || isset($entries[$name])) {
                    throw new \RuntimeException(__('The private source index contains invalid metadata.', 'chada-aio'));
                }
                $entries[$name] = ['relative' => $relative, 'size' => (int) $entry['size'], 'mtime' => (int) $entry['mtime'], 'inode' => (int) ($entry['inode'] ?? 0)];
            }
        } finally { fclose($handle); }
        return $entries;
    }

    /** Count source-index records once so later packaging windows can consume the index sequentially. */
    private static function source_index_count(string $directory): int {
        $index = dirname($directory) . '/source-index.jsonl';
        if (!is_file($index)) { return 0; }
        $handle = fopen($index, 'rb');
        if (!$handle) { throw new \RuntimeException(__('Could not read the private source index.', 'chada-aio')); }
        $count = 0;
        try { while (fgets($handle) !== false) { $count++; } }
        finally { fclose($handle); }
        return $count;
    }

    /** Read the next source-index record and bind it to the manifest entry order.
     * @param resource $handle Open source-index stream.
     */
    private static function next_source_entry($handle, string $name): array {
        $line = fgets($handle); $entry = $line === false ? null : json_decode($line, true);
        if (!is_array($entry) || ($entry['name'] ?? '') !== $name || !isset($entry['relative'], $entry['size'], $entry['mtime'])) {
            throw new \RuntimeException(__('The private source index is incomplete or out of order.', 'chada-aio'));
        }
        $relative = CHADA_AIO_Storage::relative((string) $entry['relative']);
        if (substr($name, 6) !== $relative || (int) $entry['size'] < 0) { throw new \RuntimeException(__('The private source index contains invalid metadata.', 'chada-aio')); }
        return ['relative' => $relative, 'size' => (int) $entry['size'], 'mtime' => (int) $entry['mtime'], 'inode' => (int) ($entry['inode'] ?? 0)];
    }

    public static function manifest(string $directory, string $type, array $period, array $coverage = [], string $recovery_scope = '', array $metrics = []): array {
        global $wp_version;
        $entries = self::indexed_entries($directory); $sources = self::source_entries($directory);
        foreach ($sources as $name => $source) { $entries[$name] = ['size' => $source['size'], 'sha256' => str_repeat('0', 64)]; }
        $indexed = array_fill_keys(array_keys($entries), true);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) { throw new \RuntimeException(__('Unexpected package file.', 'chada-aio')); }
            $name = substr(wp_normalize_path($file->getPathname()), strlen(wp_normalize_path($directory)) + 1);
            if ($name === 'manifest.json') { continue; }
            if (isset($sources[$name])) { continue; }
            if (isset($indexed[$name])) {
                if ($file->getSize() !== $entries[$name]['size']) { throw new \RuntimeException(__('A captured file changed before packaging.', 'chada-aio')); }
                continue;
            }
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) { throw new \RuntimeException(__('A captured file could not be checksummed.', 'chada-aio')); }
            $entries[$name] = ['size' => $file->getSize(), 'sha256' => $hash];
        }
        foreach ($entries as $name => $metadata) {
            if (isset($sources[$name])) { continue; }
            $path = CHADA_AIO_Storage::path($directory, $name);
            if (!is_file($path) || filesize($path) !== $metadata['size']) {
                throw new \RuntimeException(__('A captured file is missing or changed before packaging.', 'chada-aio'));
            }
        }
        try { $uploads_url = CHADA_AIO_Content::uploads_url(); } catch (\Throwable $error) { $uploads_url = ''; }
        $manifest = ['format' => 'chada-aio', 'version' => 1, 'type' => $type, 'source_id' => CHADA_AIO_Config::identity(),
            'home' => home_url(), 'siteurl' => site_url(), 'content_url' => content_url(), 'uploads_url' => $uploads_url, 'content_path' => CHADA_AIO_Locations::content_root(),
            'wordpress' => $wp_version, 'php' => PHP_VERSION, 'created_at' => gmdate('c'), 'period' => $period,
            'exclusions' => ['core', 'server configuration', 'operational storage', 'caches', 'development files'],
            'consistency' => 'Live batched capture; use a quiet site for cross-table consistency.', 'entries' => $entries];
        if ($coverage) { $manifest['coverage'] = $coverage; }
        if ($recovery_scope !== '') { $manifest['recovery_scope'] = $recovery_scope; }
        if ($metrics) { $manifest['metrics'] = $metrics; }
        CHADA_AIO_Storage::json($directory . '/manifest.json', $manifest);
        return $manifest;
    }

    public static function pack_step(string $directory, string $archive, array &$state): bool {
        if (!isset($state['names'])) {
            $manifest = CHADA_AIO_Storage::read($directory . '/manifest.json');
            $state = ['names' => array_keys($manifest['entries']), 'offset' => 0, 'source_count' => self::source_index_count($directory), 'source_index_offset' => 0,
                'archive_offset' => 0, 'source_retries' => [],
                'bytes_read' => 0, 'bytes_written' => 0, 'archive_reopens' => 0, 'finalized' => false];
        }
        if (!empty($state['finalized'])) { return true; }
        $manifest = CHADA_AIO_Storage::read($directory . '/manifest.json');
        $zip = new CHADA_AIO_Stream_Zip($archive, (int) $state['archive_offset']); $state['archive_reopens'] = (int) ($state['archive_reopens'] ?? 0) + 1;
        $source_handle = null;
        if ((int) ($state['offset'] ?? 0) < (int) ($state['source_count'] ?? 0)) {
            $source_handle = fopen(dirname($directory) . '/source-index.jsonl', 'rb');
            if (!$source_handle || fseek($source_handle, (int) ($state['source_index_offset'] ?? 0)) !== 0) {
                if ($source_handle) { fclose($source_handle); }
                $zip->close(); throw new \RuntimeException(__('Could not resume the private source index.', 'chada-aio'));
            }
        }
        $started = microtime(true); $processed = 0; $pending_index = [];
        try {
            while ($state['offset'] < count($state['names']) && $processed < CHADA_AIO_Config::FILE_BATCH
                && ($processed === 0 || (microtime(true) - $started < CHADA_AIO_Config::STEP_SECONDS && CHADA_AIO_Runner::work_available()))) {
                $name = $state['names'][$state['offset']]; $direct = null; $source_position = null;
                if ($state['offset'] < (int) ($state['source_count'] ?? 0)) {
                    $source_position = ftell($source_handle); $direct = self::next_source_entry($source_handle, $name);
                }
                $expected = $direct ?: [];
                $source = $direct ? self::content_path($direct['relative']) : CHADA_AIO_Storage::path($directory, $name);
                if ($direct) {
                    $before = stat($source); $expected = $direct;
                    if (!$before || (int) $before['size'] !== $expected['size'] || (int) $before['mtime'] !== $expected['mtime']) {
                        $zip->close(); if ($pending_index) { self::append_package_index($directory, $pending_index); $pending_index = []; }
                        self::truncate_archive($archive, (int) $state['archive_offset']);
                        if ($source_handle && $source_position !== null) { fseek($source_handle, $source_position); }
                        $state['source_retries'][$name] = (int) ($state['source_retries'][$name] ?? 0) + 1;
                        if ($state['source_retries'][$name] > CHADA_AIO_Config::SOURCE_RETRIES) { throw new \RuntimeException(__('A source file changed repeatedly during packaging; retry when the site is quiet.', 'chada-aio')); }
                        return false;
                    }
                }
                try { $entry = $zip->add_file($source, $name, self::stored($name)); }
                catch (\Throwable $error) { $zip->close(); if ($pending_index) { self::append_package_index($directory, $pending_index); } self::truncate_archive($archive, (int) $state['archive_offset']); throw $error; }
                if ($direct) {
                    $after = stat($source);
                    if (!$after || (int) $after['size'] !== $expected['size'] || (int) $after['mtime'] !== $expected['mtime']
                        || (int) ($after['ino'] ?? 0) !== (int) ($expected['inode'] ?? 0)) {
                        $zip->close(); if ($pending_index) { self::append_package_index($directory, $pending_index); $pending_index = []; }
                        self::truncate_archive($archive, (int) $state['archive_offset']);
                        if ($source_handle && $source_position !== null) { fseek($source_handle, $source_position); }
                        $state['source_retries'][$name] = (int) ($state['source_retries'][$name] ?? 0) + 1;
                        if ($state['source_retries'][$name] > CHADA_AIO_Config::SOURCE_RETRIES) { throw new \RuntimeException(__('A source file changed repeatedly during packaging; retry when the site is quiet.', 'chada-aio')); }
                        return false;
                    }
                }
                $pending_index[] = $entry; $state['offset']++; $state['archive_offset'] = $entry['end_offset']; $processed++;
                if ($source_handle) { $state['source_index_offset'] = ftell($source_handle); }
                $state['bytes_read'] = (int) ($state['bytes_read'] ?? 0) + $entry['bytes_read'];
                $state['bytes_written'] = (int) ($state['bytes_written'] ?? 0) + $entry['bytes_written'];
            }
        } finally { if ($source_handle) { fclose($source_handle); } $zip->close(); }
        if ($pending_index) { self::append_package_index($directory, $pending_index); }
        if ($state['offset'] < count($state['names'])) { return false; }
        $manifest = self::package_manifest($directory, $manifest, count($state['names']));
        $zip = new CHADA_AIO_Stream_Zip($archive, (int) $state['archive_offset']);
        try {
            $json = wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) { throw new \RuntimeException(__('The package manifest could not be encoded.', 'chada-aio')); }
            $manifest_entry = $zip->add_data($json, 'manifest.json', false); $central = self::package_entries($directory, count($state['names'])); $central[] = $manifest_entry;
            $state['archive_offset'] = $zip->finish($central); $state['finalized'] = true;
            CHADA_AIO_Storage::json($directory . '/manifest.json', $manifest);
            return true;
        } finally { $zip->close(); }
    }

    private static function stored(string $name): bool {
        return (bool) preg_match('/\.(?:avif|gif|jpe?g|png|webp|zip|gz|bz2|xz|pdf|mp3|mp4|m4a|m4v|mov|avi|mkv|ogg|oga|wav|flac|webm)$/i', $name);
    }

    private static function append_package_index(string $directory, array $entries): void {
        $lines = '';
        foreach ($entries as $entry) {
            $line = wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) { throw new \RuntimeException(__('The private package index could not be encoded.', 'chada-aio')); }
            $lines .= $line . "\n";
        }
        CHADA_AIO_Storage::append(dirname($directory) . '/package-index.jsonl', $lines);
    }

    private static function package_entries(string $directory, int $limit): array {
        $index = dirname($directory) . '/package-index.jsonl'; $entries = [];
        if (!is_file($index)) { return $entries; }
        $handle = fopen($index, 'rb');
        if (!$handle) { throw new \RuntimeException(__('Could not read the private package index.', 'chada-aio')); }
        try {
            while (count($entries) < $limit && ($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if (!is_array($entry) || !isset($entry['name'], $entry['size'], $entry['sha256'], $entry['crc'], $entry['compressed_size'], $entry['offset'])) {
                    throw new \RuntimeException(__('The private package index is invalid.', 'chada-aio'));
                }
                $entries[] = $entry;
            }
        } finally { fclose($handle); }
        if (count($entries) !== $limit) { throw new \RuntimeException(__('The private package index is incomplete.', 'chada-aio')); }
        return $entries;
    }

    private static function package_manifest(string $directory, array $manifest, int $limit): array {
        foreach (self::package_entries($directory, $limit) as $entry) {
            $manifest['entries'][$entry['name']] = ['size' => (int) $entry['size'], 'sha256' => (string) $entry['sha256']];
        }
        return $manifest;
    }

    private static function truncate_archive(string $archive, int $offset): void {
        CHADA_AIO_Storage::file_target($archive);
        $handle = fopen($archive, 'c+b');
        if (!$handle || ftruncate($handle, $offset) === false) { if ($handle) { fclose($handle); } throw new \RuntimeException(__('The partial archive could not be safely resumed.', 'chada-aio')); }
        fclose($handle);
    }

    /** Validate the central directory before any extraction, then allow manifest-listed entries only. */
    public static function inspect(string $archive): array {
        $zip = self::zip();
        if ($zip->open($archive, \ZipArchive::CHECKCONS) !== true) { throw new \RuntimeException(__('Invalid ZIP archive.', 'chada-aio')); }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > CHADA_AIO_Config::MAX_ENTRIES) { throw new \RuntimeException(__('Invalid archive entry count.', 'chada-aio')); }
            $stat = $zip->statName('manifest.json');
            if (!$stat || $stat['size'] > 16777216) { throw new \RuntimeException(__('Missing or oversized Chada manifest.', 'chada-aio')); }
            $manifest = json_decode($zip->getFromName('manifest.json'), true);
            if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'chada-aio' || ($manifest['version'] ?? 0) !== 1
                || !in_array($manifest['type'] ?? '', CHADA_AIO_Plugin::package_types(), true)
                || !is_array($manifest['entries'] ?? null)) { throw new \RuntimeException(__('Unsupported Chada package.', 'chada-aio')); }
            CHADA_AIO_Storage::id((string) ($manifest['source_id'] ?? ''));
            foreach (['home', 'siteurl'] as $key) {
                if (empty($manifest[$key]) || !is_string($manifest[$key]) || !preg_match('~^https?://[^/]+~', $manifest[$key])) { throw new \RuntimeException(__('Invalid source URL in manifest.', 'chada-aio')); }
            }
            if (isset($manifest['content_url']) && (!is_string($manifest['content_url']) || !preg_match('~^https?://[^/]+~', $manifest['content_url']))) {
                throw new \RuntimeException(__('Invalid source content URL in manifest.', 'chada-aio'));
            }
            if (empty($manifest['content_path']) || !is_string($manifest['content_path']) || !is_array($manifest['period'] ?? null)) { throw new \RuntimeException(__('Incomplete source metadata.', 'chada-aio')); }
            if (isset($manifest['coverage'])) {
                if (!is_array($manifest['coverage']) || !in_array($manifest['coverage']['mode'] ?? '', ['runtime', 'targeted', 'complete'], true)
                    || !is_array($manifest['coverage']['paths'] ?? null)) { throw new \RuntimeException(__('Invalid package coverage metadata.', 'chada-aio')); }
                foreach ($manifest['coverage']['paths'] as $path) { CHADA_AIO_Storage::relative((string) $path); }
            }
            $seen = []; $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i); $name = CHADA_AIO_Storage::relative($entry['name']);
                $key = strtolower($name);
                if (isset($seen[$key])) { throw new \RuntimeException(__('Duplicate or case-colliding archive path.', 'chada-aio')); }
                $seen[$key] = true;
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                if ((($attributes >> 16) & 0170000) === 0120000) { throw new \RuntimeException(__('Archive symlinks are forbidden.', 'chada-aio')); }
                if ($name !== 'manifest.json' && !isset($manifest['entries'][$name])) { throw new \RuntimeException(__('Unlisted archive entry.', 'chada-aio')); }
                if ($name !== 'manifest.json') {
                    if (isset($manifest['coverage']) && strpos($name, 'files/') === 0
                        && !CHADA_AIO_Content::coverage_allows(substr($name, 6), $manifest['coverage'])) {
                        throw new \RuntimeException(__('Archive file is outside its declared coverage scope.', 'chada-aio'));
                    }
                    $expected = $manifest['entries'][$name];
                    if (!isset($expected['size'], $expected['sha256']) || $entry['size'] !== $expected['size']
                        || !preg_match('/^[a-f0-9]{64}$/D', $expected['sha256'])) { throw new \RuntimeException(__('Invalid manifest entry.', 'chada-aio')); }
                }
                $total += $entry['size'];
                if ($entry['size'] > CHADA_AIO_Config::MAX_ENTRY || $total > CHADA_AIO_Config::MAX_EXPANDED
                    || ($entry['size'] > 1048576 && $entry['size'] / max(1, $entry['comp_size']) > CHADA_AIO_Config::MAX_RATIO)) {
                    throw new \RuntimeException(__('Archive exceeds extraction safety ceilings.', 'chada-aio'));
                }
            }
            if (count($manifest['entries']) + 1 !== count($seen)) { throw new \RuntimeException(__('Manifest files are missing.', 'chada-aio')); }
            CHADA_AIO_Storage::space($total * 3);
            $manifest['expanded_size'] = $total;
            return $manifest;
        } finally { $zip->close(); }
    }

    public static function unpack_step(string $archive, string $directory, array $manifest, int &$offset): bool {
        $zip = self::zip();
        if ($zip->open($archive) !== true) { throw new \RuntimeException(__('Archive cannot be reopened.', 'chada-aio')); }
        $started = microtime(true); $processed = 0;
        try {
            $names = array_keys($manifest['entries']);
            for ($index = $offset; $index < count($names) && $processed < CHADA_AIO_Config::FILE_BATCH
                && ($processed === 0 || CHADA_AIO_Runner::work_available()); $index++) {
                $name = $names[$index];
                $target = CHADA_AIO_Storage::path($directory, $name); CHADA_AIO_Storage::mkdir(dirname($target));
                CHADA_AIO_Storage::file_target($target . '.partial');
                $source = $zip->getStream($name); $out = fopen($target . '.partial', 'wb');
                if (!$source || !$out) { throw new \RuntimeException(__('Cannot extract package entry.', 'chada-aio')); }
                $hash = hash_init('sha256'); $bytes = 0;
                try {
                    while (!feof($source)) {
                        $chunk = fread($source, CHADA_AIO_Config::STREAM_CHUNK);
                        if ($chunk === false) { throw new \RuntimeException(__('Cannot extract package entry.', 'chada-aio')); }
                        if ($chunk === '') { break; }
                        if (fwrite($out, $chunk) !== strlen($chunk)) { throw new \RuntimeException(__('Cannot extract package entry.', 'chada-aio')); }
                        hash_update($hash, $chunk); $bytes += strlen($chunk);
                    }
                }
                finally { fclose($source); fclose($out); }
                if ($bytes !== $manifest['entries'][$name]['size'] || hash_final($hash) !== $manifest['entries'][$name]['sha256']) {
                    @unlink($target . '.partial'); throw new \RuntimeException(__('Archive checksum verification failed.', 'chada-aio'));
                }
                if (!rename($target . '.partial', $target)) { @unlink($target . '.partial'); throw new \RuntimeException(__('Extracted file promotion failed.', 'chada-aio')); }
                $offset++; $processed++;
                if ($processed > 1 && microtime(true) - $started >= CHADA_AIO_Config::STEP_SECONDS) { break; }
            }
            return $offset >= count($names);
        } finally { $zip->close(); }
    }

    /** Return archive entries that must be staged before database or content processing. */
    public static function metadata_entries(array $manifest): array {
        return array_values(array_filter(array_keys((array) ($manifest['entries'] ?? [])), static function (string $name): bool {
            return strpos($name, 'files/') !== 0;
        }));
    }

    /** Stream one verified archive entry to a destination-local temporary path. */
    public static function stream_entry_to_file(\ZipArchive $zip, string $name, string $target, array $expected): int {
        CHADA_AIO_Storage::file_target($target); CHADA_AIO_Storage::file_target($target . '.partial');
        CHADA_AIO_Storage::relative($name);
        if (!isset($expected['size'], $expected['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', (string) $expected['sha256'])) {
            throw new \RuntimeException(__('Invalid package entry metadata.', 'chada-aio'));
        }
        CHADA_AIO_Storage::mkdir(dirname($target)); $partial = $target . '.partial'; $source = $zip->getStream($name); $output = fopen($partial, 'wb');
        if (!$source || !$output) { if ($source) { fclose($source); } if ($output) { fclose($output); } throw new \RuntimeException(__('Cannot stream package entry.', 'chada-aio')); }
        $hash = hash_init('sha256'); $bytes = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, CHADA_AIO_Config::STREAM_CHUNK);
                if ($chunk === false) { throw new \RuntimeException(__('Cannot stream package entry.', 'chada-aio')); }
                if ($chunk === '') { break; }
                $written = 0; $length = strlen($chunk); hash_update($hash, $chunk); $bytes += $length;
                while ($written < $length) {
                    $count = fwrite($output, substr($chunk, $written));
                    if ($count === false || $count === 0) { throw new \RuntimeException(__('Cannot write streamed package entry.', 'chada-aio')); }
                    $written += $count;
                }
            }
        } catch (\Throwable $error) { @unlink($partial); throw $error; }
        finally { fclose($source); fclose($output); }
        if ($bytes !== (int) $expected['size'] || hash_final($hash) !== $expected['sha256']) {
            @unlink($partial); throw new \RuntimeException(__('Archive checksum verification failed.', 'chada-aio'));
        }
        if (!rename($partial, $target)) { @unlink($partial); throw new \RuntimeException(__('Streamed package promotion failed.', 'chada-aio')); }
        return $bytes;
    }

    /** Read the resumable metadata and runtime installation index. */
    public static function import_index(string $directory): array {
        $index = dirname($directory) . '/import-index.jsonl'; $entries = [];
        if (!is_file($index)) { return $entries; }
        $handle = fopen($index, 'rb');
        if (!$handle) { throw new \RuntimeException(__('Could not read the private import index.', 'chada-aio')); }
        try {
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                $name = is_array($entry) ? CHADA_AIO_Storage::relative((string) ($entry['name'] ?? '')) : '';
                $status = is_array($entry) ? (string) ($entry['status'] ?? '') : '';
                if (!is_array($entry) || !in_array($status, ['verified', 'skipped', 'applied'], true) || !isset($entry['name'], $entry['size'], $entry['sha256'], $entry['mtime'])
                    || !preg_match('/^[a-f0-9]{64}$/D', (string) $entry['sha256']) || (int) $entry['size'] < 0) { throw new \RuntimeException(__('The private import index is invalid.', 'chada-aio')); }
                $target = (string) ($entry['target'] ?? ''); if ($target !== '') { CHADA_AIO_Storage::relative($target); }
                $entries[$name] = ['status' => $status, 'size' => (int) $entry['size'], 'sha256' => (string) $entry['sha256'], 'mtime' => (int) $entry['mtime'],
                    'inode' => (int) ($entry['inode'] ?? 0), 'target' => $target, 'reason' => (string) ($entry['reason'] ?? '')];
            }
        } finally { fclose($handle); }
        return $entries;
    }

    /** Append one verified metadata or runtime installation result to the import index. */
    public static function append_import_index(string $directory, string $name, array $metadata, string $status = 'verified', string $target = '', string $reason = ''): void {
        CHADA_AIO_Storage::relative($name); if ($target !== '') { CHADA_AIO_Storage::relative($target); }
        if (!in_array($status, ['verified', 'skipped', 'applied'], true)) { throw new \RuntimeException(__('The private import index status is invalid.', 'chada-aio')); }
        $line = wp_json_encode(['name' => $name, 'status' => $status, 'size' => (int) $metadata['size'], 'sha256' => (string) $metadata['sha256'],
            'mtime' => (int) ($metadata['mtime'] ?? 0), 'inode' => (int) ($metadata['inode'] ?? 0), 'target' => $target, 'reason' => $reason], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) { throw new \RuntimeException(__('The private import index could not be encoded.', 'chada-aio')); }
        CHADA_AIO_Storage::append(dirname($directory) . '/import-index.jsonl', $line . "\n");
    }

    /** Extract only database and content payloads; runtime files are streamed during application. */
    public static function extract_metadata_step(string $archive, string $directory, array $manifest, array &$state): bool {
        if (!isset($state['names'])) { $state = ['names' => self::metadata_entries($manifest), 'offset' => 0, 'verified' => self::import_index($directory), 'archive_reopens' => 0]; }
        if ($state['offset'] >= count($state['names'])) { return true; }
        $zip = self::open_archive($archive); $state['archive_reopens'] = (int) ($state['archive_reopens'] ?? 0) + 1;
        $started = microtime(true); $processed = 0;
        try {
            while ($state['offset'] < count($state['names']) && $processed < CHADA_AIO_Config::FILE_BATCH
                && ($processed === 0 || (microtime(true) - $started < CHADA_AIO_Config::STEP_SECONDS && CHADA_AIO_Runner::work_available()))) {
                $name = $state['names'][$state['offset']]; $target = CHADA_AIO_Storage::path($directory, $name); $expected = $manifest['entries'][$name];
                $cached = $state['verified'][$name] ?? []; $stat = is_file($target) ? stat($target) : false;
                if (!$stat || !in_array($cached['status'] ?? 'verified', ['verified'], true) || (string) ($cached['sha256'] ?? '') !== (string) ($expected['sha256'] ?? '')
                    || !isset($cached['size'], $cached['mtime']) || (int) ($stat['size'] ?? -1) !== (int) $cached['size'] || (int) ($stat['mtime'] ?? -1) !== (int) $cached['mtime']) {
                    $bytes = self::stream_entry_to_file($zip, $name, $target, $expected); $stat = stat($target);
                    $state['verified'][$name] = ['status' => 'verified', 'size' => $bytes, 'sha256' => $expected['sha256'], 'mtime' => (int) ($stat['mtime'] ?? 0),
                        'inode' => (int) ($stat['ino'] ?? 0), 'target' => $name];
                    self::append_import_index($directory, $name, $state['verified'][$name]);
                }
                $state['offset']++; $processed++;
            }
        } finally { $zip->close(); }
        return $state['offset'] >= count($state['names']);
    }

    /** Verify generated archives by hashing ZIP streams without writing a second expanded copy. */
    public static function verify_step(string $archive, array $manifest, int &$offset): bool {
        $zip = self::zip();
        if ($zip->open($archive, \ZipArchive::CHECKCONS) !== true) { throw new \RuntimeException(__('Archive cannot be verified.', 'chada-aio')); }
        $started = microtime(true); $processed = 0;
        try {
            $names = array_keys($manifest['entries']);
            for ($index = $offset; $index < count($names) && $processed < CHADA_AIO_Config::FILE_BATCH; $index++) {
                $name = $names[$index]; $source = $zip->getStream($name);
                if (!$source) { throw new \RuntimeException(__('Archive checksum verification failed.', 'chada-aio')); }
                $hash = hash_init('sha256'); $bytes = 0;
                try {
                    while (!feof($source)) {
                        $chunk = fread($source, CHADA_AIO_Config::STREAM_CHUNK);
                        if ($chunk === false) { throw new \RuntimeException(__('Archive checksum verification failed.', 'chada-aio')); }
                        if ($chunk === '') { break; }
                        hash_update($hash, $chunk); $bytes += strlen($chunk);
                    }
                } finally { fclose($source); }
                if ($bytes !== $manifest['entries'][$name]['size'] || hash_final($hash) !== $manifest['entries'][$name]['sha256']) {
                    throw new \RuntimeException(__('Archive checksum verification failed.', 'chada-aio'));
                }
                $offset++; $processed++;
                if ($processed > 1 && microtime(true) - $started >= CHADA_AIO_Config::STEP_SECONDS) { break; }
            }
            return $offset >= count($names);
        } finally { $zip->close(); }
    }

    /** Finalize a generated archive after writer-side entry checks and one raw archive digest pass. */
    public static function finalize_generated(string $archive): array {
        $manifest = self::inspect($archive); $sha256 = hash_file('sha256', $archive);
        if ($sha256 === false) { throw new \RuntimeException(__('The completed archive could not be checksummed.', 'chada-aio')); }
        return ['manifest' => $manifest, 'sha256' => $sha256];
    }
}
