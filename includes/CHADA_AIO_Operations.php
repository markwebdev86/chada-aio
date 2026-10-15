<?php
/** Shared functional export and restore workflows; providers select the available operations. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as REST JSON or escaped in CHADA_AIO_Admin_Menu::render; no exception message is printed as raw HTML.

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.unlink_unlink -- Private local streams require seek, locks and atomic replacement; paths are confined by CHADA_AIO_Storage. Remote filesystem transports cannot provide these guarantees.


final class CHADA_AIO_Operations {
    public static function backup(array &$job): void {
        $directory = $job['work'] . '/payload';
        CHADA_AIO_Storage::mkdir($directory);
        while (CHADA_AIO_Runner::work_available()) {
            $current_stage = $job['stage']; $stage_started = microtime(true);
            if ($job['stage'] === 'start') {
                $job['period'] = CHADA_AIO_Config::period($job['args']); $queue = [];
                if ($job['type'] !== 'all') { throw new \RuntimeException(__('This backup provider supports Backup All only.', 'chada-aio')); }
                $scope = CHADA_AIO_Content::runtime_scope($job['args']); $queue = $scope['paths']; $job['coverage'] = $scope['coverage'];
                $captured = (array) ($job['args']['captured_files'] ?? []); $pending = array_values(array_diff($queue, array_keys($captured)));
                if ($captured) {
                    $index = [];
                    foreach ($captured as $relative => $metadata) { $index[] = ['name' => 'files/' . $relative, 'size' => $metadata['size'], 'sha256' => $metadata['sha256']]; }
                    CHADA_AIO_Package::seed_index($directory, $index);
                }
                $direct = $job['type'] === 'all' && !$captured && ($job['args']['scope_mode'] ?? '') !== 'complete';
                $job['files'] = ['stack' => array_reverse($pending), 'direct' => $direct, 'visited' => count($captured), 'files' => count($captured),
                    'bytes' => array_sum(array_map(static function (array $metadata): int { return (int) $metadata['size']; }, $captured))];
                $job['database'] = CHADA_AIO_Database::begin($directory);
                $job['stage'] = 'capture'; $job['message'] = __('Capturing site files and records.', 'chada-aio'); CHADA_AIO_Runner::add_stage_time($job, $current_stage, $stage_started); continue;
            }
            if ($job['stage'] === 'capture') {
                if (!empty($job['files']['stack']) || !empty($job['files']['queue'])) {
                    CHADA_AIO_Package::collect($directory, $job['files']);
                    if (!empty($job['files']['stack'])) { CHADA_AIO_Runner::add_stage_time($job, $current_stage, $stage_started); return; }
                }
                if (isset($job['database']) && !CHADA_AIO_Database::export_step($directory, $job['database'])) { CHADA_AIO_Runner::add_stage_time($job, $current_stage, $stage_started); return; }
                $capture_metrics = ['files' => (int) ($job['files']['files'] ?? 0), 'bytes' => (int) ($job['files']['bytes'] ?? 0)];
                $job['metrics']['files'] = $capture_metrics['files']; $job['metrics']['bytes'] = $capture_metrics['bytes'];
                $job['metrics']['bytes_read'] = (int) ($job['metrics']['bytes_read'] ?? 0) + (int) ($job['files']['bytes_read'] ?? 0);
                $job['metrics']['bytes_written'] = (int) ($job['metrics']['bytes_written'] ?? 0) + (int) ($job['files']['bytes_written'] ?? 0);
                $job['metrics']['file_passes'] = $job['files']['direct'] ? 1 : 2;
                $job['metrics']['db_queries'] = (int) ($job['database']['queries'] ?? 0); $job['metrics']['db_rows'] = (int) ($job['database']['rows_exported'] ?? 0);
                $job['metrics']['db_batches'] = (int) ($job['database']['batches'] ?? 0);
                CHADA_AIO_Package::manifest($directory, $job['type'], $job['period'], $job['coverage'] ?? [], $job['recovery_scope'] ?? '', $capture_metrics);
                $job['stage'] = 'package'; $job['pack'] = []; $job['message'] = __('Packaging the captured data.', 'chada-aio'); CHADA_AIO_Runner::add_stage_time($job, $current_stage, $stage_started); continue;
            }
            $archive = CHADA_AIO_Storage::root() . '/archives/' . $job['id'] . '.zip';
            if ($job['stage'] === 'package') {
                if (!CHADA_AIO_Package::pack_step($directory, $archive, $job['pack'])) { CHADA_AIO_Runner::add_stage_time($job, $current_stage, $stage_started); return; }
                $job['manifest'] = CHADA_AIO_Storage::read($directory . '/manifest.json'); $job['verify_offset'] = 0;
                $job['stage'] = 'verify'; $job['message'] = __('Verifying the completed archive streams.', 'chada-aio'); CHADA_AIO_Runner::add_stage_time($job, $current_stage, $stage_started); continue;
            }
            if ($job['stage'] === 'verify') {
                $finalized = CHADA_AIO_Package::finalize_generated($archive);
                $job['manifest'] = $finalized['manifest'];
                $job['metrics']['archive_entries'] = (int) count($job['manifest']['entries']) + 1;
                $job['metrics']['bytes_read'] = (int) ($job['metrics']['bytes_read'] ?? 0) + (int) ($job['pack']['bytes_read'] ?? 0);
                $job['metrics']['bytes_written'] = (int) ($job['metrics']['bytes_written'] ?? 0) + (int) ($job['pack']['bytes_written'] ?? 0);
                $job['metrics']['archive_reopens'] = (int) ($job['pack']['archive_reopens'] ?? 0);
                $archive_hash = $finalized['sha256'];
                $record = ['id' => $job['id'], 'operation' => $job['operation'], 'type' => $job['type'], 'status' => 'complete',
                    'created_at' => gmdate('Y-m-d H:i:s'), 'archive' => 'archives/' . $job['id'] . '.zip', 'bytes' => filesize($archive),
                    'sha256' => $archive_hash, 'period' => $job['period'], 'protected' => !empty($job['protected']),
                    'managed' => (bool) apply_filters('chada_aio_managed_record', false), 'manifest' => $job['manifest'], 'metrics' => $job['metrics'] ?? []];
                CHADA_AIO_Catalog::put($record);
                if (empty($job['protected'])) { CHADA_AIO_Catalog::rotate($job['operation']); }
                $job['stage'] = 'done'; $job['status'] = 'complete'; $job['message'] = $job['operation'] === 'export'
                    ? __('Migration package verified and ready to download.', 'chada-aio') : __('Backup package verified and ready to download.', 'chada-aio');
                if (empty($job['protected'])) { CHADA_AIO_Storage::remove($directory); }
                CHADA_AIO_Runner::add_stage_time($job, $current_stage, $stage_started); return;
            }
            return;
        }
    }

    public static function valid_files(array $manifest): array {
        if (($manifest['type'] ?? '') !== 'all') { throw new \RuntimeException(__('This file provider supports All packages only.', 'chada-aio')); }
        $files = [];
        foreach ($manifest['entries'] as $name => $metadata) {
            if (strpos($name, 'files/') !== 0) { continue; }
            $relative = substr($name, 6); CHADA_AIO_Storage::relative($relative);
            if (isset($manifest['coverage']) && !CHADA_AIO_Content::coverage_allows($relative, $manifest['coverage'])) {
                throw new \RuntimeException(__('File is outside the package coverage scope.', 'chada-aio'));
            }
            if (CHADA_AIO_Package::excluded($relative)) { throw new \RuntimeException(__('Package attempts to restore an excluded file.', 'chada-aio')); }
            $files[] = $relative;
        }
        return $files;
    }

    /** Stream existing runtime preimages once and retain only those that differ from the incoming package. */
    private static function targeted_recovery(array &$job): array {
        $paths = []; $captured = []; $job['destination_hashes'] = (array) ($job['destination_hashes'] ?? []);
        $directory = $job['work'] . '/recovery/payload'; CHADA_AIO_Storage::mkdir($directory . '/files');
        foreach ((array) ($job['targets'] ?? []) as $relative) {
            if (strpos($relative, 'plugins/chada-aio/') === 0 || strpos($relative, 'plugins/chada-aio-pro/') === 0) { continue; }
            $target = CHADA_AIO_Package::content_path($relative); $entry = $job['manifest']['entries']['files/' . $relative] ?? [];
            if (is_file($target)) {
                $stat = stat($target); $cached = $job['destination_hashes'][$relative] ?? [];
                $hash = isset($cached['sha256'], $cached['size'], $cached['mtime']) && (int) ($stat['size'] ?? -1) === (int) $cached['size']
                    && (int) ($stat['mtime'] ?? -1) === (int) $cached['mtime'] ? $cached['sha256'] : hash_file('sha256', $target);
                if ($hash === false) { throw new \RuntimeException(__('A destination file could not be checksummed for recovery.', 'chada-aio')); }
                $metadata = ['size' => (int) ($stat['size'] ?? 0), 'mtime' => (int) ($stat['mtime'] ?? 0), 'sha256' => (string) $hash];
                $job['destination_hashes'][$relative] = $metadata;
                if (isset($entry['sha256']) && $metadata['sha256'] !== $entry['sha256']) {
                    $preimage = CHADA_AIO_Storage::path($directory, 'files/' . $relative); $captured[$relative] = CHADA_AIO_Package::copy($target, $preimage);
                    $paths[] = $relative;
                }
            }
        }
        return ['paths' => array_values(array_unique($paths)), 'captured' => $captured];
    }

    /** Build the recovery capture arguments without duplicating unrelated destination files. */
    private static function recovery_args(array &$job): array {
        if ($job['operation'] === 'import' || $job['type'] === 'all') {
            $targeted = self::targeted_recovery($job);
            return ['scope_paths' => $targeted['paths'], 'captured_files' => $targeted['captured'], 'scope_mode' => 'targeted'];
        }
        $scope = CHADA_AIO_Content::complete_scope();
        return ['scope_paths' => $scope['paths'], 'scope_mode' => 'complete'];
    }

    /** Account for a nested recovery stage, which runs inside the parent request rather than Runner::tick(). */
    private static function measure_recovery(array &$job, string $stage, float $started): void {
        $job['metrics'] = array_merge(['requests' => 0, 'ticks' => 0, 'files' => 0, 'bytes' => 0, 'bytes_read' => 0, 'bytes_written' => 0,
            'files_skipped' => 0, 'files_written' => 0, 'bytes_skipped' => 0, 'db_queries' => 0, 'db_rows' => 0, 'db_batches' => 0, 'file_passes' => 0,
            'archive_entries' => 0, 'archive_reopens' => 0, 'stage_ms' => []], (array) ($job['metrics'] ?? []));
        $job['metrics']['requests'] = (int) $job['metrics']['requests'] + 1; $job['metrics']['ticks'] = $job['metrics']['requests'];
        $job['metrics']['stage_ms'] = (array) $job['metrics']['stage_ms'];
        $job['metrics']['stage_ms'][$stage] = round((float) ($job['metrics']['stage_ms'][$stage] ?? 0) + (microtime(true) - $started) * 1000, 2);
        if ($job['status'] === 'complete') { $job['metrics']['elapsed_ms'] = round((microtime(true) - (float) $job['started_at']) * 1000, 2); }
        if ($job['status'] === 'complete') {
            try { $record = CHADA_AIO_Catalog::get($job['id']); $record['metrics'] = $job['metrics']; $record['manifest']['metrics'] = $job['metrics']; CHADA_AIO_Catalog::put($record); }
            catch (\Throwable $error) { /* Optional recovery metrics refresh must not invalidate the parent operation. */ }
        }
    }

    public static function apply(array &$job): void {
        $directory = $job['work'] . '/incoming';
        if ($job['stage'] === 'start') {
            $job['manifest'] = CHADA_AIO_Package::inspect($job['args']['archive']);
            if ($job['manifest']['type'] !== $job['type']) { throw new \RuntimeException(__('Package type changed after preview.', 'chada-aio')); }
            CHADA_AIO_Package::assert_archive_binding($job['args']['archive'], $job['args']);
            if ($job['operation'] === 'import' && (($job['args']['preflight']['status'] ?? '') === 'blocked' || (($job['args']['preflight']['status'] ?? '') === 'warning' && empty($job['args']['acknowledge_warnings'])))) {
                throw new \RuntimeException(__('The import preflight requires administrator action before this package can be applied.', 'chada-aio'));
            }
            if ($job['operation'] === 'restore' && ($job['manifest']['source_id'] !== CHADA_AIO_Config::identity()
                || $job['manifest']['home'] !== home_url())) { throw new \RuntimeException(__('Cross-site packages require Migration.', 'chada-aio')); }
            $job['targets'] = self::valid_files($job['manifest']); $job['extract_names'] = CHADA_AIO_Package::metadata_entries($job['manifest']);
            $job['extract_offset'] = 0; $job['extract_state'] = [];
            $job['metrics']['files'] = count($job['targets']); $job['metrics']['bytes'] = array_sum(array_map(static function (string $relative) use ($job): int {
                return (int) ($job['manifest']['entries']['files/' . $relative]['size'] ?? 0);
            }, $job['targets']));
            $job['stage'] = 'extract'; $job['message'] = __('Validating and staging metadata and database payloads.', 'chada-aio'); return;
        }
        if ($job['stage'] === 'extract') {
            if (!CHADA_AIO_Package::extract_metadata_step($job['args']['archive'], $directory, $job['manifest'], $job['extract_state'])) {
                $job['extract_offset'] = (int) ($job['extract_state']['offset'] ?? 0); $job['metrics']['archive_reopens'] = (int) ($job['extract_state']['archive_reopens'] ?? 0); return;
            }
            $job['extract_offset'] = (int) ($job['extract_state']['offset'] ?? 0); $job['metrics']['archive_reopens'] = (int) ($job['extract_state']['archive_reopens'] ?? 0);
            CHADA_AIO_Database::validate_schema($directory, $job['manifest']);
            $job['recovery_scope'] = 'targeted';
            $job['recovery'] = CHADA_AIO_Runner::new_state('backup', 'all', self::recovery_args($job), $job['work'] . '/recovery');
            $job['recovery']['protected'] = true;
            $job['recovery']['recovery_scope'] = $job['recovery_scope'];
            $job['stage'] = 'recovery'; $job['message'] = __('Creating and verifying the recovery backup.', 'chada-aio'); return;
        }
        if ($job['stage'] === 'recovery') {
            $recovery_stage = $job['recovery']['stage']; $recovery_started = microtime(true);
            try { self::backup($job['recovery']); }
            finally { self::measure_recovery($job['recovery'], $recovery_stage, $recovery_started); }
            if ($job['recovery']['status'] !== 'complete') { return; }
            $job['recovery_id'] = $job['recovery']['id'];
            $job['stage'] = 'prepare'; $job['message'] = __('Recovery backup verified. Preparing changes.', 'chada-aio'); return;
        }
        if ($job['stage'] === 'prepare') {
            CHADA_AIO_Plugin::file_permission($job['type']);
            $job['db_apply'] = CHADA_AIO_Database::prepare($directory, $job['id'], $job['operation'] === 'import', $job['manifest']);
            $job['stage'] = 'database-stage';
            $job['file_offset'] = 0; $job['file_journal'] = [];
            return;
        }
        if ($job['stage'] === 'database-stage') {
            $done = false; $batches = 0;
            do { $done = CHADA_AIO_Database::import_step($directory, $job['db_apply']); $batches++; }
            while (!$done && $batches < CHADA_AIO_Config::DB_BATCHES && CHADA_AIO_Runner::work_available());
            if ($done) { $job['stage'] = 'apply-files'; }
            $job['metrics']['db_batches'] = (int) ($job['metrics']['db_batches'] ?? 0) + $batches;
            $job['message'] = __('Staging database rows before replacing live tables.', 'chada-aio'); return;
        }
        if ($job['stage'] === 'apply-files') {
            CHADA_AIO_Runner::maintenance($job['id']);
            $job['touched'] = true;
            if ($job['operation'] === 'import' && !isset($job['import_index'])) { $job['import_index'] = CHADA_AIO_Package::import_index($directory); }
            $processed = 0; $started = microtime(true);
            $zip = null;
            try {
                while (isset($job['targets'][$job['file_offset']]) && $processed < CHADA_AIO_Config::FILE_BATCH
                    && ($processed === 0 || (microtime(true) - $started < CHADA_AIO_Config::STEP_SECONDS && CHADA_AIO_Runner::work_available()))) {
                    $relative = $job['targets'][$job['file_offset']];
                    // Keep the live recovery engine executable until this operation completes.
                    if (strpos($relative, 'plugins/chada-aio/') === 0 || strpos($relative, 'plugins/chada-aio-pro/') === 0) {
                        $job['warnings']['engine'] = 'Chada plugin files are preserved during restore. Reinstall another Chada version separately.';
                        $job['file_offset']++; $processed++; continue;
                    }
                    $destination = $relative;
                    $target = CHADA_AIO_Package::content_path($destination);
                    $entry = $job['manifest']['entries']['files/' . $relative] ?? [];
                    $index_name = 'files/' . $relative; $indexed = $job['import_index'][$index_name] ?? [];
                    $indexed_stat = is_file($target) ? stat($target) : false;
                    if ($job['operation'] === 'import' && in_array($indexed['status'] ?? '', ['skipped', 'applied'], true) && ($indexed['target'] ?? '') === $destination
                        && $indexed_stat && (int) ($indexed_stat['size'] ?? -1) === (int) ($indexed['size'] ?? -2) && (int) ($indexed_stat['mtime'] ?? -1) === (int) ($indexed['mtime'] ?? -2)
                        && ((int) ($indexed['inode'] ?? 0) === 0 || (int) ($indexed_stat['ino'] ?? 0) === (int) $indexed['inode']) ) {
                        $job['file_offset']++; $job['metrics']['files_skipped'] = (int) ($job['metrics']['files_skipped'] ?? 0) + (($indexed['status'] ?? '') === 'skipped' ? 1 : 0);
                        $job['metrics']['files_written'] = (int) ($job['metrics']['files_written'] ?? 0) + (($indexed['status'] ?? '') === 'applied' ? 1 : 0);
                        $job['metrics']['bytes_skipped'] = (int) ($job['metrics']['bytes_skipped'] ?? 0) + (($indexed['status'] ?? '') === 'skipped' ? (int) ($entry['size'] ?? 0) : 0);
                        $job['metrics']['bytes_written'] = (int) ($job['metrics']['bytes_written'] ?? 0) + (($indexed['status'] ?? '') === 'applied' ? (int) ($entry['size'] ?? 0) : 0);
                        $processed++; continue;
                    }
                    if ($destination !== $relative && is_file($target)) {
                        $job['file_offset']++; $job['warnings']['existing_media'] = 'Previously imported media files were preserved.';
                        $stat = stat($target); CHADA_AIO_Package::append_import_index($directory, $index_name, ['size' => (int) ($entry['size'] ?? 0),
                            'sha256' => (string) ($entry['sha256'] ?? ''), 'mtime' => (int) ($stat['mtime'] ?? 0), 'inode' => (int) ($stat['ino'] ?? 0)], 'skipped', $destination, 'preserved');
                        $job['import_index'][$index_name] = ['status' => 'skipped', 'size' => (int) ($entry['size'] ?? 0), 'sha256' => (string) ($entry['sha256'] ?? ''),
                            'mtime' => (int) ($stat['mtime'] ?? 0), 'inode' => (int) ($stat['ino'] ?? 0), 'target' => $destination];
                        $job['metrics']['files_skipped'] = (int) ($job['metrics']['files_skipped'] ?? 0) + 1;
                        $job['metrics']['bytes_skipped'] = (int) ($job['metrics']['bytes_skipped'] ?? 0) + (int) ($job['manifest']['entries']['files/' . $relative]['size'] ?? 0);
                        $processed++; continue;
                    }
                    if (file_exists($target) && !is_file($target)) { throw new \RuntimeException(__('A destination path is a directory where a file is required.', 'chada-aio')); }
                    $target_hash = '';
                    $target_stat = false;
                    if (is_file($target)) {
                        $target_stat = stat($target); $cached = $job['destination_hashes'][$relative] ?? [];
                        $target_hash = isset($cached['sha256'], $cached['size'], $cached['mtime']) && (int) ($target_stat['size'] ?? -1) === (int) $cached['size']
                            && (int) ($target_stat['mtime'] ?? -1) === (int) $cached['mtime'] ? $cached['sha256'] : hash_file('sha256', $target);
                        if ($target_hash === false) { throw new \RuntimeException(__('A destination file could not be checksummed.', 'chada-aio')); }
                    }
                    $source_hash = $entry['sha256'] ?? '';
                    if ($target_hash !== '' && $target_hash === $source_hash) {
                        $job['file_offset']++; $job['message'] = __('Skipping unchanged files.', 'chada-aio');
                        if ($job['operation'] === 'import') {
                            CHADA_AIO_Package::append_import_index($directory, $index_name, ['size' => (int) ($entry['size'] ?? 0), 'sha256' => (string) $source_hash,
                                'mtime' => (int) ($target_stat['mtime'] ?? 0), 'inode' => (int) ($target_stat['ino'] ?? 0)], 'skipped', $destination, 'unchanged');
                            $job['import_index'][$index_name] = ['status' => 'skipped', 'size' => (int) ($entry['size'] ?? 0), 'sha256' => (string) $source_hash,
                                'mtime' => (int) ($target_stat['mtime'] ?? 0), 'inode' => (int) ($target_stat['ino'] ?? 0), 'target' => $destination];
                        }
                        $job['metrics']['files_skipped'] = (int) ($job['metrics']['files_skipped'] ?? 0) + 1;
                        $job['metrics']['bytes_skipped'] = (int) ($job['metrics']['bytes_skipped'] ?? 0) + (int) ($entry['size'] ?? 0);
                        $processed++; continue;
                    }
                    if (!isset($job['file_journal'][$destination])) {
                        $existed = file_exists($target); $journal = ['existed' => $existed];
                        if ($existed && $job['recovery_scope'] === 'targeted') {
                            $recovery = CHADA_AIO_Storage::path($job['recovery']['work'] . '/payload', 'files/' . $relative);
                            if (!is_file($recovery)) { throw new \RuntimeException(__('The targeted recovery preimage is missing.', 'chada-aio')); }
                            $journal['recovery_path'] = $relative;
                        } elseif ($existed) {
                            $recovery = CHADA_AIO_Storage::path($job['recovery']['work'] . '/payload', 'files/' . $destination);
                            if (is_file($recovery)) { $journal['recovery_path'] = $destination; }
                            else { CHADA_AIO_Package::copy($target, CHADA_AIO_Storage::path($job['work'] . '/rollback', $destination)); }
                        }
                        $journal['source'] = $relative; $job['file_journal'][$destination] = $journal;
                        CHADA_AIO_Runner::save($job); // Write-ahead recovery journal precedes the live mutation.
                    }
                    if (!$zip) { $zip = CHADA_AIO_Package::open_archive($job['args']['archive']); $job['metrics']['archive_reopens'] = (int) ($job['metrics']['archive_reopens'] ?? 0) + 1; }
                    $temp = $target . '.chada-' . substr($job['id'], 0, 8);
                    $bytes = CHADA_AIO_Package::stream_entry_to_file($zip, 'files/' . $relative, $temp, $entry);
                    if (!rename($temp, $target)) { throw new \RuntimeException(__('Streamed file replacement failed.', 'chada-aio')); }
                    @chmod($target, 0644);
                    if ($job['operation'] === 'import') {
                        $stat = stat($target); CHADA_AIO_Package::append_import_index($directory, $index_name, ['size' => $bytes, 'sha256' => (string) ($entry['sha256'] ?? ''),
                            'mtime' => (int) ($stat['mtime'] ?? 0), 'inode' => (int) ($stat['ino'] ?? 0)], 'applied', $destination, 'applied');
                        $job['import_index'][$index_name] = ['status' => 'applied', 'size' => $bytes, 'sha256' => (string) ($entry['sha256'] ?? ''),
                            'mtime' => (int) ($stat['mtime'] ?? 0), 'inode' => (int) ($stat['ino'] ?? 0), 'target' => $destination];
                    }
                    $job['file_offset']++; $job['message'] = __('Applying streamed verified files.', 'chada-aio');
                    $job['metrics']['files_written'] = (int) ($job['metrics']['files_written'] ?? 0) + 1;
                    $job['metrics']['bytes_read'] = (int) ($job['metrics']['bytes_read'] ?? 0) + $bytes;
                    $job['metrics']['bytes_written'] = (int) ($job['metrics']['bytes_written'] ?? 0) + $bytes;
                    $job['metrics']['file_passes'] = 1; $processed++;
                }
            } finally { if ($zip) { $zip->close(); } }
            if (isset($job['targets'][$job['file_offset']])) { return; }
            $job['stage'] = 'database-commit'; return;
        }
        if ($job['stage'] === 'database-commit') {
            CHADA_AIO_Runner::save($job);
            CHADA_AIO_Database::commit($job['db_apply']);
            $job['stage'] = 'verify'; $job['message'] = __('Database changes staged; completing recovery checks.', 'chada-aio'); return;
        }
        if ($job['stage'] === 'verify') {
            if (!isset($job['verify_names'])) { $job['verify_names'] = array_keys((array) ($job['file_journal'] ?? [])); $job['verify_offset'] = 0; }
            $started = microtime(true); $processed = 0;
            while (isset($job['verify_names'][$job['verify_offset']]) && $processed < CHADA_AIO_Config::FILE_BATCH
                && ($processed === 0 || (microtime(true) - $started < CHADA_AIO_Config::STEP_SECONDS && CHADA_AIO_Runner::work_available()))) {
                $destination = $job['verify_names'][$job['verify_offset']]; $journal = $job['file_journal'][$destination]; $source = $journal['source'] ?? $destination;
                $entry = $job['manifest']['entries']['files/' . $source] ?? []; $target = CHADA_AIO_Package::content_path($destination);
                if (!is_file($target) || (int) filesize($target) !== (int) ($entry['size'] ?? -1) || hash_file('sha256', $target) !== ($entry['sha256'] ?? '')) {
                    throw new \RuntimeException(__('Final file verification failed; retain the recovery archive.', 'chada-aio'));
                }
                $job['verify_offset']++; $job['metrics']['files_verified'] = (int) ($job['metrics']['files_verified'] ?? 0) + 1; $processed++;
            }
            if (isset($job['verify_names'][$job['verify_offset']])) { return; }
            if (!empty($job['db_apply']) && !CHADA_AIO_Database::is_swapped($job['db_apply'])) { throw new \RuntimeException(__('Final database verification failed; retain the recovery archive.', 'chada-aio')); }
            if (!get_option('siteurl') || !get_option('home') || !CHADA_AIO_Config::identity()) { throw new \RuntimeException(__('Destination identity verification failed after import.', 'chada-aio')); }
            wp_cache_flush(); flush_rewrite_rules(false); $job['stage'] = 'finish'; $job['message'] = __('Import verified; refreshing WordPress runtime caches.', 'chada-aio'); return;
        }
        if ($job['stage'] === 'finish') {
            if (!get_option('siteurl')) { throw new \RuntimeException(__('Site verification failed after import.', 'chada-aio')); }
            $job['status'] = 'complete'; $job['stage'] = 'done';
            $job['message'] = $job['operation'] === 'import'
                ? __('Migration import complete. Recovery backup retained. Full database changes may require signing in again.', 'chada-aio')
                : __('Restore complete. Recovery backup retained. Full database changes may require signing in again.', 'chada-aio');
            CHADA_AIO_Runner::clear_maintenance($job['id']);
            CHADA_AIO_Catalog::put(['id' => $job['id'], 'operation' => $job['operation'], 'type' => $job['type'],
                'status' => 'complete', 'created_at' => gmdate('Y-m-d H:i:s'), 'protected' => false,
                'managed' => (bool) apply_filters('chada_aio_managed_record', false), 'bytes' => 0,
                'recovery_id' => $job['recovery_id'], 'recovery_scope' => $job['recovery_scope'], 'manifest' => $job['manifest'],
                'metrics' => $job['metrics'] ?? [], 'warnings' => $job['warnings'] ?? []]);
            CHADA_AIO_Catalog::rotate($job['operation']);
            if ($job['operation'] === 'import' && !empty($job['args']['upload_id'])) { self::cleanup_upload($job['args']['upload_id']); }
        }
    }

    /** Starting another job closes immediate rollback; verified recovery archives remain available. */
    public static function release(array $job): void {
        foreach (['db_apply', 'rollback_db'] as $key) { if (!empty($job[$key])) { CHADA_AIO_Database::discard($job[$key]); } }
        if (!empty($job['work']) && file_exists($job['work'])) { CHADA_AIO_Storage::remove($job['work']); }
        if (in_array($job['operation'], ['backup', 'export'], true) && $job['status'] !== 'complete') {
            $archive = CHADA_AIO_Storage::root() . '/archives/' . CHADA_AIO_Storage::id($job['id']) . '.zip';
            if (is_file($archive)) { CHADA_AIO_Storage::remove($archive); }
        }
        if ($job['operation'] === 'import' && in_array($job['status'], ['complete', 'rolled_back', 'cancelled'], true) && !empty($job['args']['upload_id'])) { self::cleanup_upload($job['args']['upload_id']); }
    }

    /** Recovery is resumable; the job stays locked until rollback completes. */
    public static function rollback(array &$job): void {
        if (!empty($job['db_apply'])) { CHADA_AIO_Database::rollback($job['db_apply']); }
        if (empty($job['rollback_db']) && !empty($job['touched']) && empty($job['db_apply'])) {
            $directory = $job['recovery']['work'] . '/payload';
            $manifest = CHADA_AIO_Storage::read($directory . '/manifest.json');
            $job['rollback_db'] = CHADA_AIO_Database::prepare($directory, wp_generate_uuid4(), false, $manifest);
            return;
        }
        if (!empty($job['rollback_db']) && empty($job['rollback_db']['swapped'])) {
            $directory = $job['recovery']['work'] . '/payload';
            if (!CHADA_AIO_Database::import_step($directory, $job['rollback_db'])) { return; }
            CHADA_AIO_Database::commit($job['rollback_db']); return;
        }
        if (!empty($job['file_journal'])) {
            $relative = array_key_last($job['file_journal']);
            $entry = $job['file_journal'][$relative]; $target = CHADA_AIO_Package::content_path($relative);
            if ($entry['existed']) {
                $source = !empty($entry['recovery_path'])
                    ? CHADA_AIO_Storage::path($job['recovery']['work'] . '/payload', 'files/' . $entry['recovery_path'])
                    : CHADA_AIO_Storage::path($job['work'] . '/rollback', $relative);
                CHADA_AIO_Package::copy($source, $target);
            }
            elseif (is_file($target) && !unlink($target)) { throw new \RuntimeException(__('Cannot remove newly imported file during rollback.', 'chada-aio')); }
            unset($job['file_journal'][$relative]); return;
        }
        $job['status'] = 'rolled_back'; $job['message'] = __('Rollback complete. Recovery archive retained.', 'chada-aio');
        if ($job['operation'] === 'import' && !empty($job['args']['upload_id'])) { self::cleanup_upload($job['args']['upload_id']); }
        CHADA_AIO_Runner::clear_maintenance($job['id']);
    }

    /** Remove a completed import's private upload only after its job journal is durable. */
    private static function cleanup_upload(string $id): void {
        try { $id = CHADA_AIO_Storage::id($id); $root = CHADA_AIO_Storage::root(); foreach ([$root . '/uploads/' . $id . '.json', $root . '/uploads/' . $id . '.zip'] as $path) { if (is_file($path)) { CHADA_AIO_Storage::remove($path); } } }
        catch (\Throwable $error) { /* Upload cleanup is best effort; catalog and recovery state remain authoritative. */ }
    }
}
