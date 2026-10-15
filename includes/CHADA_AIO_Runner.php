<?php
/** Authenticated resumable jobs with filesystem journals and a single-writer lock. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameters select read-only admin pages. Every mutation uses an authenticated REST nonce or an admin-post nonce.

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as REST JSON or escaped in CHADA_AIO_Admin_Menu::render; no exception message is printed as raw HTML.

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Private local streams require seek, locks and atomic replacement; paths are confined by CHADA_AIO_Storage. Remote filesystem transports cannot provide these guarantees.


final class CHADA_AIO_Runner {
    private static $deadline = 0.0;

    public static function begin_work_window(): void {
        CHADA_AIO_Runtime::apply();
        $limit = (int) ini_get('max_execution_time'); $seconds = (float) CHADA_AIO_Config::STEP_SECONDS;
        if ($limit > 0) { $seconds = min($seconds, max(1.0, $limit - 5.0)); }
        self::$deadline = microtime(true) + $seconds;
    }

    public static function work_available(): bool { return self::$deadline === 0.0 || microtime(true) < self::$deadline; }

    public static function end_work_window(): void { self::$deadline = 0.0; }

    public static function add_stage_time(array &$job, string $stage, float $started): void {
        $job['metrics'] = array_merge(['stage_ms' => []], (array) ($job['metrics'] ?? [])); $job['metrics']['stage_ms'] = (array) ($job['metrics']['stage_ms'] ?? []);
        $job['metrics']['stage_ms'][$stage] = round((float) ($job['metrics']['stage_ms'][$stage] ?? 0) + (microtime(true) - $started) * 1000, 2); $job['_stage_metrics_recorded'] = true;
    }

    public static function new_state(string $operation, string $type, array $args, string $work = ''): array {
        $id = wp_generate_uuid4();
        return ['id' => $id, 'operation' => $operation, 'type' => $type, 'args' => $args, 'owner' => get_current_user_id(),
            'stage' => 'start', 'status' => 'running', 'created_at' => gmdate('c'), 'updated_at' => time(),
            'started_at' => microtime(true), 'metrics' => ['requests' => 0, 'ticks' => 0, 'files' => 0, 'bytes' => 0, 'bytes_read' => 0,
                'bytes_written' => 0, 'files_skipped' => 0, 'files_written' => 0, 'bytes_skipped' => 0, 'db_queries' => 0, 'db_rows' => 0, 'db_batches' => 0, 'file_passes' => 0, 'archive_entries' => 0,
                'archive_reopens' => 0, 'stage_ms' => []],
            'work' => $work ?: CHADA_AIO_Storage::root() . '/jobs/' . $id, 'message' => 'Starting preflight checks.'];
    }

    public static function save(array $job): void {
        global $wpdb;
        CHADA_AIO_Storage::json(CHADA_AIO_Storage::root() . '/jobs/' . CHADA_AIO_Storage::id($job['id']) . '.json', $job);
        $wpdb->replace(CHADA_AIO_Config::table('jobs'), ['id' => $job['id'], 'operation' => $job['operation'],
            'status' => $job['status'], 'owner_id' => $job['owner'], 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    public static function get(string $id): array { return CHADA_AIO_Storage::read(CHADA_AIO_Storage::root() . '/jobs/' . CHADA_AIO_Storage::id($id) . '.json'); }

    /** Return the current job, optionally excluding terminal states from status views. */
    public static function active(bool $include_terminal = true): ?array {
        $file = CHADA_AIO_Storage::root() . '/active.json';
        if (!is_file($file)) { return null; }
        $active = CHADA_AIO_Storage::read($file);
        $job = self::get($active['id']);
        if (!$include_terminal && !in_array($job['status'] ?? '', ['running', 'rolling_back', 'recovery_required'], true)) { return null; }
        return $job;
    }

    public static function lock() {
        $handle = fopen(CHADA_AIO_Storage::root() . '/worker.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) { throw new \RuntimeException(__('Another worker is processing this site.', 'chada-aio')); }
        return $handle;
    }

    public static function start(string $operation, string $type, array $args): array {
        CHADA_AIO_Installer::assert_ready();
        CHADA_AIO_Plugin::provider($operation, $type);
        if (in_array($operation, ['restore', 'import'], true)) { CHADA_AIO_Plugin::file_permission($type); }
        $validator = CHADA_AIO_Plugin::maybe_provider('validate_start', $type);
        if ($validator) { $args = $validator($operation, $type, $args); }
        $lock = self::lock();
        try {
            $active = self::active();
            if ($active && in_array($active['status'], ['running', 'recovery_required', 'rolling_back'], true)) {
                throw new \RuntimeException(__('Finish, cancel, or recover the current operation first.', 'chada-aio'));
            }
            if ($active) { CHADA_AIO_Plugin::lifecycle('release', $active); }
            $job = self::new_state($operation, $type, $args); $job['_progress'] = self::progress($job); self::save($job);
            CHADA_AIO_Storage::json(CHADA_AIO_Storage::root() . '/active.json', ['id' => $job['id']]);
            return self::public_state($job);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function tick(string $id): array {
        $lock = self::lock();
        try {
            $job = self::get($id);
            if (!in_array($job['status'], ['running', 'rolling_back'], true)) { return self::public_state($job); }
            if ((self::active()['id'] ?? '') !== $id) { throw new \RuntimeException(__('This is not the current operation.', 'chada-aio')); }
            if ((int) $job['owner'] !== get_current_user_id() && !current_user_can('manage_options')) { throw new \RuntimeException(__('Cannot operate another user\'s job.', 'chada-aio')); }
            $stage = $job['stage']; $started = microtime(true); self::begin_work_window();
            try {
                if ($job['status'] === 'rolling_back') { CHADA_AIO_Plugin::lifecycle('rollback', $job); }
                else { $provider = CHADA_AIO_Plugin::provider($job['operation'], $job['type']); $provider($job); }
            } catch (\Throwable $error) {
                $job['status'] = !empty($job['touched']) ? 'recovery_required' : 'failed';
                $job['message'] = $error->getMessage();
                if (empty($job['touched'])) { self::clear_maintenance($job['id']); }
            } finally { self::end_work_window(); }
            $job['metrics'] = array_merge(['requests' => 0, 'ticks' => 0, 'files' => 0, 'bytes' => 0, 'bytes_read' => 0,
                'bytes_written' => 0, 'files_skipped' => 0, 'files_written' => 0, 'bytes_skipped' => 0, 'db_queries' => 0, 'db_rows' => 0, 'db_batches' => 0, 'file_passes' => 0, 'archive_entries' => 0,
                'archive_reopens' => 0, 'stage_ms' => []], (array) ($job['metrics'] ?? []));
            $job['metrics']['stage_ms'] = (array) $job['metrics']['stage_ms'];
            $job['metrics']['requests'] = (int) $job['metrics']['requests'] + 1; $job['metrics']['ticks'] = $job['metrics']['requests'];
            if (empty($job['_stage_metrics_recorded'])) { $job['metrics']['stage_ms'][$stage] = round((float) ($job['metrics']['stage_ms'][$stage] ?? 0) + (microtime(true) - $started) * 1000, 2); }
            unset($job['_stage_metrics_recorded']);
            if (in_array($job['status'], ['complete', 'failed', 'cancelled', 'rolled_back'], true)) {
                $job['metrics']['elapsed_ms'] = round((microtime(true) - (float) ($job['started_at'] ?? microtime(true))) * 1000, 2);
            }
            $job['_progress'] = self::progress($job); $job['updated_at'] = time(); self::save($job);
            if ($job['status'] === 'complete' && in_array($job['operation'], ['backup', 'export', 'import', 'restore'], true)) {
                try { $record = CHADA_AIO_Catalog::get($job['id']); $record['metrics'] = $job['metrics']; $record['manifest']['metrics'] = $job['metrics']; CHADA_AIO_Catalog::put($record); }
                catch (\Throwable $error) { /* Optional metrics refresh must not invalidate a completed job. */ }
            }
            return self::public_state($job);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function control(string $id, string $action): array {
        $lock = self::lock();
        try {
            $job = self::get($id);
            $active = self::active();
            if (!$active || $active['id'] !== $id) { throw new \RuntimeException(__('Only the most recent operation can be controlled. Use its recovery archive for older changes.', 'chada-aio')); }
            if ($active && $active['id'] !== $id && in_array($active['status'], ['running', 'rolling_back', 'recovery_required'], true)) {
                throw new \RuntimeException(__('A different operation is active.', 'chada-aio'));
            }
            if ($action === 'rollback') {
                if (empty($job['recovery_id']) || empty($job['touched']) || !in_array($job['status'], ['complete', 'recovery_required', 'running'], true)) {
                    throw new \RuntimeException(__('No applied operation needs rollback.', 'chada-aio'));
                }
                self::maintenance($id);
                $job['status'] = 'rolling_back';
                $job['stage'] = 'rollback'; $job['rollback_total'] = max(1, count($job['file_journal'] ?? []));
                CHADA_AIO_Storage::json(CHADA_AIO_Storage::root() . '/active.json', ['id' => $id]);
            } elseif ($action === 'retry') {
                if ($job['status'] !== 'failed' || !empty($job['touched'])) { throw new \RuntimeException(__('Only a failed job with no live changes can be retried.', 'chada-aio')); }
                $old_created = $job['created_at']; $old_owner = $job['owner']; $args = $job['args']; $operation = $job['operation']; $type = $job['type'];
                CHADA_AIO_Plugin::lifecycle('release', $job); $job = self::new_state($operation, $type, $args, CHADA_AIO_Storage::root() . '/jobs/' . $id);
                $job['id'] = $id; $job['owner'] = $old_owner; $job['created_at'] = $old_created; $job['message'] = __('Retrying the failed operation from its durable input.', 'chada-aio');
                CHADA_AIO_Storage::json(CHADA_AIO_Storage::root() . '/active.json', ['id' => $id]);
            } elseif ($action === 'cancel') {
                if (!in_array($job['status'], ['running', 'failed'], true)) { throw new \RuntimeException(__('Only an unfinished job can be cancelled.', 'chada-aio')); }
                if (!empty($job['touched'])) { throw new \RuntimeException(__('Changes have started; use Rollback to recover safely.', 'chada-aio')); }
                $job['status'] = 'cancelled'; $job['message'] = __('Cancelled before live changes.', 'chada-aio');
                CHADA_AIO_Plugin::lifecycle('release', $job);
            } else { throw new \RuntimeException(__('Unknown job control.', 'chada-aio')); }
            $job['_progress'] = self::progress($job); self::save($job); return self::public_state($job);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function public_state(array $job): array {
        $state = array_intersect_key($job, array_flip(['id', 'operation', 'type', 'status', 'stage', 'message',
            'created_at', 'updated_at', 'recovery_id', 'result', 'warnings', 'image_offset', 'file_offset', 'touched']));
        $state['progress'] = self::progress($job);
        $state['retryable'] = ($job['status'] ?? '') === 'failed' && empty($job['touched']);
        if (in_array($job['status'] ?? '', ['complete', 'failed', 'cancelled', 'rolled_back'], true)) { $state['metrics'] = $job['metrics'] ?? []; }
        return $state;
    }

    /** Calculate a bounded, resumable progress value from the current job journal. */
    public static function progress(array $job): array {
        $operation = $job['operation'] ?? ''; $stage = $job['stage'] ?? 'start';
        if (in_array($job['status'] ?? '', ['complete', 'rolled_back'], true)) {
            return ['percent' => 100, 'completed' => 1, 'total' => 1, 'stage' => (string) $stage];
        }
        if ($operation === 'optimize') { $progress = self::image_progress($job); }
        elseif ($operation === 'cleanup') { $progress = self::cleanup_progress($job); }
        elseif (in_array($operation, ['restore', 'import'], true)) { $progress = self::apply_progress($job); }
        else { $progress = self::backup_progress($job); }
        $previous = (int) ($job['_progress']['percent'] ?? 0);
        $progress['percent'] = max($previous, (int) $progress['percent']);
        $progress['percent'] = max(0, min(100, $progress['percent']));
        $progress['completed'] = max(0, (int) $progress['completed']);
        $progress['total'] = max(1, (int) $progress['total']);
        $progress['completed'] = min($progress['completed'], $progress['total']);
        $progress['stage'] = (string) $stage;
        return $progress;
    }

    private static function image_progress(array $job): array {
        $total = count((array) ($job['image_ids'] ?? $job['args']['ids'] ?? []));
        return self::phase(0, 100, (int) ($job['image_offset'] ?? 0), max(1, $total));
    }

    private static function cleanup_progress(array $job): array {
        if ($job['stage'] === 'recovery' && !empty($job['recovery'])) {
            $nested = self::progress($job['recovery']); return self::phase(0, 50, $nested['percent'], 100);
        }
        if ($job['stage'] === 'clean') {
            $total = max(1, (int) ($job['selection_total'] ?? 0));
            return self::phase(50, 100, (int) ($job['result']['deleted'] ?? 0), $total);
        }
        return self::phase(0, 50, 0, 1);
    }

    private static function backup_progress(array $job): array {
        if ($job['stage'] === 'capture') {
            $files = (array) ($job['files'] ?? []); $file_done = (int) ($files['visited'] ?? 0);
            $file_total = $file_done + count((array) ($files['stack'] ?? $files['queue'] ?? [])); $db_done = 0; $db_total = 0;
            if (!empty($job['database']['tables'])) {
                foreach ($job['database']['tables'] as $index => $table) {
                    $rows = (int) ($table['rows'] ?? 0); $db_total += $rows;
                    if ($index < (int) ($job['database']['table'] ?? 0)) { $db_done += $rows; }
                    elseif ($index === (int) ($job['database']['table'] ?? 0)) { $db_done += (int) ($job['database']['offset'] ?? 0); }
                }
            }
            return self::phase(5, 70, $file_done + $db_done, max(1, $file_total + $db_total));
        }
        if ($job['stage'] === 'package') {
            $names = (array) ($job['pack']['names'] ?? []);
            return self::phase(70, 85, (int) ($job['pack']['offset'] ?? 0), max(1, count($names)));
        }
        if ($job['stage'] === 'verify') {
            $total = count((array) ($job['manifest']['entries'] ?? []));
            return self::phase(85, 100, (int) ($job['verify_offset'] ?? 0), max(1, $total));
        }
        return self::phase(0, 5, 0, 1);
    }

    private static function apply_progress(array $job): array {
        if ($job['stage'] === 'extract') {
            $total = count((array) ($job['extract_names'] ?? $job['manifest']['entries'] ?? []));
            return self::phase(0, 20, (int) ($job['extract_offset'] ?? 0), max(1, $total));
        }
        if ($job['stage'] === 'recovery' && !empty($job['recovery'])) {
            $nested = self::progress($job['recovery']); return self::phase(20, 50, $nested['percent'], 100);
        }
        if ($job['stage'] === 'database-stage') {
            [$done, $total] = self::database_work($job['db_apply'] ?? []); return self::phase(50, 75, $done, $total);
        }
        if ($job['stage'] === 'apply-files') {
            $total = count((array) ($job['targets'] ?? [])); return self::phase(75, 90, (int) ($job['file_offset'] ?? 0), max(1, $total));
        }
        if ($job['stage'] === 'apply-content') {
            $total = max(1, count((array) ($job['content']['posts'] ?? [])) * 2);
            return self::phase(90, 98, (int) ($job['content_state']['offset'] ?? 0), $total);
        }
        if ($job['stage'] === 'verify') {
            $total = max(1, count((array) ($job['verify_names'] ?? [])) + 1);
            return self::phase(98, 100, (int) ($job['verify_offset'] ?? 0), $total);
        }
        if ($job['stage'] === 'rollback') {
            $total = max(1, (int) ($job['rollback_total'] ?? 1));
            return self::phase(0, 99, $total - count((array) ($job['file_journal'] ?? [])), $total);
        }
        return self::phase(0, 20, 0, 1);
    }

    private static function database_work(array $state): array {
        $done = 0; $total = 0; $current = (int) ($state['table'] ?? 0); $offset = (int) ($state['row'] ?? $state['offset'] ?? 0);
        foreach ((array) ($state['tables'] ?? []) as $index => $table) {
            $rows = (int) ($table['rows'] ?? 0); $total += $rows;
            if ($index < $current) { $done += $rows; } elseif ($index === $current) { $done += $offset; }
        }
        return [$done, max(1, $total)];
    }

    private static function phase(int $start, int $end, int $completed, int $total): array {
        $total = max(1, $total); $completed = max(0, min($total, $completed));
        return ['percent' => $start + (int) floor(($end - $start) * $completed / $total), 'completed' => $completed, 'total' => $total, 'stage' => ''];
    }

    public static function maintenance(string $id): void {
        CHADA_AIO_Storage::json(CHADA_AIO_Storage::root() . '/maintenance.json', ['id' => $id]);
    }

    public static function clear_maintenance(string $id): void {
        $file = CHADA_AIO_Storage::root() . '/maintenance.json';
        if (is_file($file) && (CHADA_AIO_Storage::read($file)['id'] ?? '') === $id) { CHADA_AIO_Storage::remove($file); }
    }

    public static function guard(): void {
        if (defined('WP_CLI') && WP_CLI) { return; }
        try { $file = CHADA_AIO_Storage::root() . '/maintenance.json'; } catch (\Throwable $error) { return; }
        if (!is_file($file)) { return; }
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (current_user_can('manage_options') && strpos($page, CHADA_AIO_ADMIN_PREFIX) === 0) { return; }
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $script = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME'])) : '';
        if (defined('REST_REQUEST') && REST_REQUEST && strpos($uri, CHADA_AIO_REST_NAMESPACE) !== false) { return; }
        if (strpos($script, 'wp-login.php') !== false) { return; }
        wp_die(esc_html__('A verified site recovery is being applied. Please try again shortly.', 'chada-aio'), '', ['response' => 503]);
    }
}
