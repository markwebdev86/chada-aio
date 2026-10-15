<?php
/** Administrator-only REST boundary; previews bind confirmation to an immutable package digest. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as REST JSON or escaped in CHADA_AIO_Admin_Menu::render; no exception message is printed as raw HTML.

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Private local streams require seek, locks and atomic replacement; paths are confined by CHADA_AIO_Storage. Remote filesystem transports cannot provide these guarantees.


final class CHADA_AIO_Rest {
    public static function permission(): bool { return current_user_can('manage_options') && !is_multisite(); }

    public static function register(): void {
        add_action('rest_api_init', static function () {
            $routes = [
                '/status' => ['GET', 'status'], '/records' => ['GET', 'records'],
                '/records/(?P<id>[a-f0-9-]{36})' => ['GET', 'record'],
                '/records/(?P<id>[a-f0-9-]{36})/delete' => ['POST', 'delete'],
                '/jobs' => ['POST', 'start'], '/jobs/(?P<id>[a-f0-9-]{36})' => ['GET', 'job'],
                '/jobs/(?P<id>[a-f0-9-]{36})/step' => ['POST', 'step'],
                '/jobs/(?P<id>[a-f0-9-]{36})/control' => ['POST', 'control'],
                '/previews' => ['POST', 'preview'], '/uploads' => ['POST', 'upload_start'],
                '/uploads/(?P<id>[a-f0-9-]{36})/status' => ['GET', 'upload_status'],
                '/uploads/(?P<id>[a-f0-9-]{36})' => ['POST', 'upload_chunk'],
                '/settings' => ['POST', 'settings'], '/cleanup-preview' => ['POST', 'cleanup_preview'],
                '/images/(?P<id>\d+)/undo' => ['POST', 'undo'], '/user-state' => ['POST', 'user_state'],
            ];
            foreach ($routes as $path => [$method, $handler]) {
                register_rest_route(CHADA_AIO_REST_NAMESPACE, $path, ['methods' => $method,
                    'permission_callback' => [self::class, 'permission'], 'callback' => static function ($request) use ($handler) {
                        try { return rest_ensure_response(self::$handler($request)); }
                        catch (\Throwable $error) { return new \WP_Error('chada-aio_operation', $error->getMessage(), ['status' => 400]); }
                    }]);
            }
        });
        add_action('admin_post_chada-aio_download', [self::class, 'download']);
    }

    private static function input(\WP_REST_Request $request): array {
        $input = $request->get_json_params();
        if (!is_array($input)) { throw new \RuntimeException(__('The request body must be a JSON object.', 'chada-aio')); }
        return $input;
    }

    private static function invalid_input(): \RuntimeException { return new \RuntimeException(__('The request contains invalid data.', 'chada-aio')); }

    /** @param mixed $value */
    private static function string_value($value): string {
        if (!is_string($value)) { throw self::invalid_input(); }
        return $value;
    }

    private static function string_input(array $input, string $key, string $default = ''): string {
        return array_key_exists($key, $input) ? self::string_value($input[$key]) : $default;
    }

    /** @param mixed $value */
    private static function integer_value($value, int $minimum, int $maximum): int {
        if (is_int($value)) { $integer = $value; }
        elseif (is_string($value) && preg_match('/^-?\d+$/D', $value)) {
            $negative = isset($value[0]) && $value[0] === '-';
            $digits = ltrim($negative ? substr($value, 1) : $value, '0');
            $digits = $digits === '' ? '0' : $digits;
            $normalized = $negative && $digits !== '0' ? '-' . $digits : $digits;
            $integer = filter_var($normalized, FILTER_VALIDATE_INT);
            if ($integer === false) { throw self::invalid_input(); }
        } else { throw self::invalid_input(); }
        if ($integer < $minimum || $integer > $maximum) { throw self::invalid_input(); }
        return $integer;
    }

    private static function integer_input(array $input, string $key, int $minimum, int $maximum, int $default): int {
        return array_key_exists($key, $input) ? self::integer_value($input[$key], $minimum, $maximum) : $default;
    }

    private static function boolean_input(array $input, string $key, bool $default = false): bool {
        if (!array_key_exists($key, $input)) { return $default; }
        $value = $input[$key];
        if (is_bool($value)) { return $value; }
        if ($value === 0 || $value === '0') { return false; }
        if ($value === 1 || $value === '1') { return true; }
        throw self::invalid_input();
    }

    private static function array_input(array $input, string $key): array {
        if (!array_key_exists($key, $input)) { return []; }
        if (!is_array($input[$key])) { throw self::invalid_input(); }
        return $input[$key];
    }

    /** @param mixed $value */
    private static function string_list($value): array {
        if (!is_array($value) || ($value && array_keys($value) !== range(0, count($value) - 1))) { throw self::invalid_input(); }
        foreach ($value as $item) { if (!is_string($item)) { throw self::invalid_input(); } }
        return array_values($value);
    }

    /** @param mixed $value */
    private static function integer_list($value): array {
        if (!is_array($value) || ($value && array_keys($value) !== range(0, count($value) - 1))) { throw self::invalid_input(); }
        $ids = [];
        foreach ($value as $item) { $ids[] = self::integer_value($item, 1, PHP_INT_MAX); }
        return array_values(array_unique($ids));
    }

    public static function status(): array {
        $storage = CHADA_AIO_Storage::status(); $active = null;
        if ($storage['ready']) { $job = CHADA_AIO_Runner::active(false); $active = $job ? CHADA_AIO_Runner::public_state($job) : null; }
        return ['storage' => $storage, 'active' => $active, 'zip' => class_exists('ZipArchive'), 'runtime' => CHADA_AIO_Runtime::status(),
            'settings_errors' => CHADA_AIO_Config::readiness_errors(), 'migration_error' => CHADA_AIO_Installer::migration_error()];
    }

    public static function records(\WP_REST_Request $request): array {
        $requested = trim(self::string_value($request->get_param('operation') ?? ''));
        $operations = $requested === '' ? [] : array_values(array_unique(array_filter(array_map('sanitize_key', preg_split('/\s*,\s*/', $requested) ?: []))));
        $allowed = ['backup', 'export', 'import', 'optimize', 'cleanup'];
        $operations = array_values(array_intersect($operations, $allowed));
        $records = [];
        if (!$requested) { $records = CHADA_AIO_Catalog::all(); }
        else {
            foreach ($operations as $operation) { $records = array_merge($records, CHADA_AIO_Catalog::all($operation)); }
            usort($records, static function ($a, $b) { return strcmp($b['created_at'] . $b['id'], $a['created_at'] . $a['id']); });
        }
        $type = sanitize_key(self::string_value($request->get_param('type') ?? ''));
        if ($type !== '') { $records = array_values(array_filter($records, static function ($record) use ($type) { return $record['type'] === $type; })); }
        $page_value = $request->get_param('page'); $page = $page_value === null ? 1 : self::integer_value($page_value, 1, PHP_INT_MAX); $size = 20;
        $pages = max(1, (int) ceil(count($records) / $size)); $page = min($page, $pages);
        $slice = array_slice($records, ($page - 1) * $size, $size);
        foreach ($slice as &$record) { unset($record['manifest'], $record['result']); }
        unset($record);
        return ['records' => $slice, 'total' => count($records), 'page' => $page, 'pages' => $pages];
    }

    public static function record(\WP_REST_Request $request): array {
        $record = CHADA_AIO_Catalog::get($request['id']);
        if (!empty($record['archive'])) {
            $record['download'] = html_entity_decode(wp_nonce_url(admin_url('admin-post.php?action=chada-aio_download&id=' . rawurlencode($record['id'])), 'chada-aio_download_' . $record['id']), ENT_QUOTES, 'UTF-8');
        }
        return $record;
    }

    public static function delete(\WP_REST_Request $request): array {
        $input = self::input($request);
        if (self::string_input($input, 'confirm') !== 'DELETE') { throw new \RuntimeException(__('Confirm archive deletion.', 'chada-aio')); }
        $protected = self::boolean_input($input, 'protected');
        $lock = CHADA_AIO_Runner::lock();
        try {
            $active = CHADA_AIO_Runner::active();
            if ($active && in_array($active['status'], ['running', 'recovery_required', 'rolling_back'], true)) { throw new \RuntimeException(__('Archives cannot be deleted while an operation is active.', 'chada-aio')); }
            CHADA_AIO_Catalog::delete($request['id'], $protected);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        return ['deleted' => true];
    }

    public static function preview(\WP_REST_Request $request): array {
        self::cleanup_stale_uploads();
        $input = self::input($request); $operation = sanitize_key(self::string_input($input, 'operation'));
        if (!in_array($operation, ['restore', 'import', 'cleanup'], true)) { throw new \RuntimeException(__('Invalid preview operation.', 'chada-aio')); }
        $args = []; $summary = [];
        if ($operation === 'cleanup') {
            $type = 'database'; $args['categories'] = CHADA_AIO_Cleanup::validate_categories(self::string_list(self::array_input($input, 'categories')));
            $args['selection'] = CHADA_AIO_Cleanup::preview($args['categories']);
            $summary['counts'] = array_map('count', $args['selection']);
        } else {
            $upload = []; $id = ''; $overwrite = self::boolean_input($input, 'overwrite');
            if ($operation === 'restore') { $archive = CHADA_AIO_Catalog::archive(CHADA_AIO_Storage::id(self::string_input($input, 'record_id'))); }
            else {
                $id = CHADA_AIO_Storage::id(self::string_input($input, 'upload_id'));
                $upload = CHADA_AIO_Storage::read(CHADA_AIO_Storage::root() . '/uploads/' . $id . '.json');
                if ((int) $upload['owner'] !== get_current_user_id() || (int) $upload['offset'] !== (int) $upload['size']) { throw new \RuntimeException(__('Upload is incomplete or belongs to another administrator.', 'chada-aio')); }
                $archive = CHADA_AIO_Storage::root() . '/uploads/' . $id . '.zip';
                if (!is_file($archive) || filesize($archive) !== (int) $upload['size']) { throw new \RuntimeException(__('The sealed upload is incomplete. Resume it or upload the package again.', 'chada-aio')); }
                if (empty($upload['sha256']) || empty($upload['stat'])) {
                    $upload['sha256'] = hash_file('sha256', $archive); $stat = stat($archive);
                    if ($upload['sha256'] === false || !$stat) { throw new \RuntimeException(__('The uploaded package could not be sealed.', 'chada-aio')); }
                    $upload['sealed'] = true; $upload['stat'] = ['size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'], 'inode' => (int) ($stat['ino'] ?? 0)]; $upload['last_activity'] = time();
                    CHADA_AIO_Storage::json(CHADA_AIO_Storage::root() . '/uploads/' . $id . '.json', $upload);
                }
            }
            $manifest = CHADA_AIO_Package::inspect($archive); $type = $manifest['type'];
            CHADA_AIO_Plugin::provider($operation, $type); CHADA_AIO_Plugin::file_permission($type);
            if ($operation === 'restore' && ($manifest['source_id'] !== CHADA_AIO_Config::identity() || $manifest['home'] !== home_url())) { throw new \RuntimeException(__('Use Migration for packages from another site.', 'chada-aio')); }
            $file_provider = CHADA_AIO_Plugin::provider('files', $type); $files = $file_provider($manifest); $collisions = 0;
            foreach ($files as $file) { if (is_file(CHADA_AIO_Package::content_path($file))) { $collisions++; } }
            $args = ['archive' => $archive, 'sha256' => (string) ($upload['sha256'] ?? hash_file('sha256', $archive)), 'overwrite' => $overwrite];
            if ($operation === 'import') { $args['archive_stat'] = (array) ($upload['stat'] ?? []); $args['upload_id'] = $id; }
            $preflight = $operation === 'import' ? CHADA_AIO_Import_Preflight::run($archive, $manifest, $operation, $type, $files, $collisions, $overwrite) : ['status' => 'ready', 'errors' => [], 'warnings' => [], 'checks' => []];
            $preview_policy = apply_filters('chada_aio_import_preview_policy', [
                'recovery_scope' => 'targeted',
                'effect' => 'Replace the covered site files and database tables, including user accounts. Destination connection configuration remains. Sign-in may change.',
            ], $type, $operation, $manifest);
            $preview_policy = is_array($preview_policy) ? $preview_policy : [];
            $summary = ['type' => $type, 'source' => $manifest['home'], 'files' => count($files), 'existing_paths' => $collisions,
                'expanded_bytes' => $manifest['expanded_size'], 'period' => $manifest['period'],
                'coverage' => $manifest['coverage']['mode'] ?? 'unknown',
                'recovery_scope' => sanitize_key((string) ($preview_policy['recovery_scope'] ?? 'targeted')),
                'effect' => sanitize_text_field((string) ($preview_policy['effect'] ?? 'The package will apply its supported changes to this site.')),
                'recovery' => 'A verified recovery backup is required before changes.',
                'file_policy' => 'Destination-only files and the running Chada plugins remain in place.'];
            if ($operation === 'import') { $summary += ['preflight' => $preflight, 'source_wordpress' => $manifest['wordpress'] ?? 'unknown', 'source_php' => $manifest['php'] ?? 'unknown',
                'destination_wordpress' => get_bloginfo('version'), 'destination_php' => PHP_VERSION]; }
        }
        $token = wp_generate_uuid4();
        CHADA_AIO_Storage::json(CHADA_AIO_Storage::root() . '/uploads/preview-' . $token . '.json', ['owner' => get_current_user_id(), 'expires' => time() + CHADA_AIO_Config::PREVIEW_TTL,
            'operation' => $operation, 'type' => $type, 'args' => $args, 'preflight' => $preflight ?? ['status' => 'ready', 'errors' => [], 'warnings' => [], 'checks' => []]]);
        return ['preview_id' => $token, 'summary' => $summary];
    }

    public static function start(\WP_REST_Request $request): array {
        $input = self::input($request);
        if (array_key_exists('preview_id', $input)) {
            $preview_id = CHADA_AIO_Storage::id(self::string_input($input, 'preview_id'));
            $acknowledge_warnings = self::boolean_input($input, 'acknowledge_warnings');
            $file = CHADA_AIO_Storage::root() . '/uploads/preview-' . $preview_id . '.json';
            $preview = CHADA_AIO_Storage::read($file);
            if ((int) $preview['owner'] !== get_current_user_id() || $preview['expires'] < time() || self::string_input($input, 'confirm') !== 'APPLY') {
                throw new \RuntimeException(__('Preview expired or confirmation is missing.', 'chada-aio'));
            }
            $preflight = (array) ($preview['preflight'] ?? []);
            if (($preflight['status'] ?? '') === 'blocked') { throw new \RuntimeException(__('The import preflight has blockers. Resolve them and preview the package again.', 'chada-aio')); }
            if (($preflight['status'] ?? '') === 'warning' && !$acknowledge_warnings) { throw new \RuntimeException(__('Acknowledge the import warnings before applying this package.', 'chada-aio')); }
            $preview['args']['preflight'] = $preflight; $preview['args']['acknowledge_warnings'] = $acknowledge_warnings;
            $job = CHADA_AIO_Runner::start($preview['operation'], $preview['type'], $preview['args']); CHADA_AIO_Storage::remove($file); return $job;
        }
        $operation = sanitize_key(self::string_input($input, 'operation')); $type = sanitize_key(self::string_input($input, 'type'));
        if (!in_array($operation, ['backup', 'export', 'optimize'], true)) { throw new \RuntimeException(__('A validated preview is required for this operation.', 'chada-aio')); }
        $args = array_intersect_key(self::array_input($input, 'args'), array_flip(['period', 'year', 'quarter', 'theme', 'ids', 'plugins', 'mu_plugins']));
        if (array_key_exists('theme', $args)) { $args['theme'] = self::string_value($args['theme']); }
        foreach (['plugins', 'mu_plugins'] as $key) { if (array_key_exists($key, $args)) { $args[$key] = self::string_list($args[$key]); } }
        if (array_key_exists('ids', $args)) { $args['ids'] = self::integer_list($args['ids']); }
        CHADA_AIO_Config::period($args);
        return CHADA_AIO_Runner::start($operation, $type, $args);
    }

    public static function job(\WP_REST_Request $request): array { return CHADA_AIO_Runner::public_state(CHADA_AIO_Runner::get($request['id'])); }
    public static function step(\WP_REST_Request $request): array { return CHADA_AIO_Runner::tick($request['id']); }
    public static function control(\WP_REST_Request $request): array { return CHADA_AIO_Runner::control($request['id'], sanitize_key(self::string_input(self::input($request), 'action'))); }

    /** Return the durable upload checkpoint after a network response is lost. */
    public static function upload_status(\WP_REST_Request $request): array {
        self::cleanup_stale_uploads(); $id = CHADA_AIO_Storage::id($request['id']); $lock = CHADA_AIO_Runner::lock();
        try {
            $path = CHADA_AIO_Storage::root() . '/uploads/' . $id; $state = CHADA_AIO_Storage::read($path . '.json');
            if ((int) ($state['owner'] ?? -1) !== get_current_user_id()) { throw new \RuntimeException(__('Upload belongs to another administrator.', 'chada-aio')); }
            $actual = is_file($path . '.zip') ? (int) filesize($path . '.zip') : -1;
            if ($actual !== (int) ($state['offset'] ?? -2) && empty($state['sealed'])) { throw new \RuntimeException(__('The upload checkpoint is inconsistent. Upload the package again.', 'chada-aio')); }
            return ['id' => $id, 'offset' => (int) $state['offset'], 'size' => (int) $state['size'], 'chunk_size' => CHADA_AIO_Config::UPLOAD_CHUNK,
                'complete' => !empty($state['sealed']), 'sealed' => !empty($state['sealed']), 'last_activity' => (int) ($state['last_activity'] ?? $state['created'] ?? 0)];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function upload_start(\WP_REST_Request $request): array {
        self::cleanup_stale_uploads(); $lock = CHADA_AIO_Runner::lock();
        try {
        $input = self::input($request);
        if (!array_key_exists('size', $input)) { throw new \RuntimeException(__('Invalid archive upload size (maximum 20 GiB).', 'chada-aio')); }
        $size = self::integer_value($input['size'], 1, CHADA_AIO_Config::MAX_EXPANDED);
        $resume_id = self::string_input($input, 'resume_id'); $raw_name = trim(self::string_input($input, 'name')); $name = sanitize_file_name($raw_name);
        $last_modified = self::integer_input($input, 'last_modified', 0, PHP_INT_MAX, 0);
        if ($raw_name === '' || $name === '' || strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') { throw new \RuntimeException(__('Choose a Chada ZIP archive.', 'chada-aio')); }
        if ($resume_id !== '') {
            $resume_id = CHADA_AIO_Storage::id($resume_id);
            $state_path = CHADA_AIO_Storage::root() . '/uploads/' . $resume_id . '.json';
            if (is_file($state_path)) {
                $state = CHADA_AIO_Storage::read($state_path);
                $same_file = $name === ($state['name'] ?? 'package.zip');
                $same_timestamp = $last_modified === (int) ($state['last_modified'] ?? 0);
                if ((int) ($state['owner'] ?? -1) === get_current_user_id() && (int) ($state['size'] ?? -1) === $size && $same_file && $same_timestamp && empty($state['sealed'])
                    && is_file(CHADA_AIO_Storage::root() . '/uploads/' . $resume_id . '.zip') && filesize(CHADA_AIO_Storage::root() . '/uploads/' . $resume_id . '.zip') === (int) $state['offset']) {
                    $state['last_activity'] = time(); CHADA_AIO_Storage::json($state_path, $state);
                    return ['id' => $resume_id, 'chunk_size' => CHADA_AIO_Config::UPLOAD_CHUNK, 'offset' => (int) $state['offset'], 'complete' => false];
                }
            }
        }
        CHADA_AIO_Storage::space($size * 3); $id = wp_generate_uuid4();
        CHADA_AIO_Storage::json(CHADA_AIO_Storage::root() . '/uploads/' . $id . '.json', ['owner' => get_current_user_id(), 'size' => $size, 'offset' => 0,
            'name' => $name, 'last_modified' => $last_modified, 'created' => time(), 'last_activity' => time(), 'sealed' => false]);
        CHADA_AIO_Storage::write(CHADA_AIO_Storage::root() . '/uploads/' . $id . '.zip', '');
        return ['id' => $id, 'chunk_size' => CHADA_AIO_Config::UPLOAD_CHUNK, 'offset' => 0, 'complete' => false];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function upload_chunk(\WP_REST_Request $request): array {
        $id = CHADA_AIO_Storage::id($request['id']); $path = CHADA_AIO_Storage::root() . '/uploads/' . $id;
        $lock = CHADA_AIO_Runner::lock();
        try {
            $state = CHADA_AIO_Storage::read($path . '.json'); $bytes = $request->get_body();
            $offset = self::integer_value($request->get_param('offset'), 0, (int) $state['size']);
            if (!empty($state['sealed'])) { return ['offset' => (int) $state['offset'], 'complete' => true]; }
            if ((int) ($state['owner'] ?? -1) !== get_current_user_id() || $offset !== (int) $state['offset']
                || strlen($bytes) > CHADA_AIO_Config::UPLOAD_CHUNK || strlen($bytes) === 0 || $state['offset'] + strlen($bytes) > $state['size']) {
                throw new \RuntimeException(__('Invalid upload owner, offset, or chunk length.', 'chada-aio'));
            }
            CHADA_AIO_Storage::file_target($path . '.zip');
            $handle = fopen($path . '.zip', 'c+b');
            if (!$handle) { throw new \RuntimeException(__('Cannot open upload.', 'chada-aio')); }
            try {
                if (filesize($path . '.zip') !== (int) $state['offset']) { throw new \RuntimeException(__('The upload checkpoint is inconsistent. Upload the package again.', 'chada-aio')); }
                if (fseek($handle, $state['offset']) !== 0) { throw new \RuntimeException(__('Upload write failed.', 'chada-aio')); }
                $written = 0; $length = strlen($bytes);
                while ($written < $length) { $count = fwrite($handle, substr($bytes, $written)); if ($count === false || $count === 0) { throw new \RuntimeException(__('Upload write failed.', 'chada-aio')); } $written += $count; }
                fflush($handle); if (function_exists('fsync')) { fsync($handle); }
            } finally { fclose($handle); }
            $state['offset'] += strlen($bytes);
            if ((int) $state['offset'] === (int) $state['size']) {
                $state['sha256'] = hash_file('sha256', $path . '.zip'); $stat = stat($path . '.zip');
                if ($state['sha256'] === false || !$stat || (int) $stat['size'] !== (int) $state['size']) { throw new \RuntimeException(__('The uploaded package could not be sealed.', 'chada-aio')); }
                $state['sealed'] = true; $state['stat'] = ['size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'], 'inode' => (int) ($stat['ino'] ?? 0)];
            }
            $state['last_activity'] = time();
            CHADA_AIO_Storage::json($path . '.json', $state);
            return ['offset' => $state['offset'], 'complete' => $state['offset'] === $state['size']];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Remove abandoned upload sessions without touching previews or jobs that still reference them. */
    private static function cleanup_stale_uploads(): void {
        try { $root = CHADA_AIO_Storage::root(); $references = [];
            foreach (glob($root . '/uploads/preview-*.json') ?: [] as $file) { try { $preview = CHADA_AIO_Storage::read($file); if ((int) ($preview['expires'] ?? 0) >= time() && !empty($preview['args']['upload_id'])) { $references[$preview['args']['upload_id']] = true; } } catch (\Throwable $error) { /* An invalid expired preview is not an active upload reference. */ } }
            foreach (glob($root . '/jobs/*.json') ?: [] as $file) { try { $job = CHADA_AIO_Storage::read($file); if (in_array($job['status'] ?? '', ['running', 'recovery_required', 'rolling_back'], true) && !empty($job['args']['upload_id'])) { $references[$job['args']['upload_id']] = true; } } catch (\Throwable $error) { /* A corrupt job is handled by its normal recovery path. */ } }
            foreach (glob($root . '/uploads/*.json') ?: [] as $file) {
                if (strpos(basename($file), 'preview-') === 0) { continue; }
                try { $state = CHADA_AIO_Storage::read($file); $id = basename($file, '.json'); $last = (int) ($state['last_activity'] ?? $state['created'] ?? 0);
                    if ($last > 0 && $last + CHADA_AIO_Config::UPLOAD_TTL < time() && empty($references[$id])) { CHADA_AIO_Storage::remove($file); $zip = $root . '/uploads/' . $id . '.zip'; if (is_file($zip)) { CHADA_AIO_Storage::remove($zip); } }
                } catch (\Throwable $error) { /* Cleanup is opportunistic and cannot interrupt a valid upload request. */ }
            }
        } catch (\Throwable $error) { /* Storage readiness is reported by the operation that needs it. */ }
    }

    public static function settings(\WP_REST_Request $request): array {
        $input = self::input($request); $tab = sanitize_key(self::string_input($input, 'tab')); $values = self::array_input($input, 'values');
        $status = CHADA_AIO_Storage::status();
        if (!$status['ready']) { return CHADA_AIO_Config::save($tab, $values); }
        $lock = CHADA_AIO_Runner::lock();
        try {
            $active = CHADA_AIO_Runner::active();
            if ($active && in_array($active['status'], ['running', 'rolling_back', 'recovery_required'], true)) { throw new \RuntimeException(__('Finish the current operation before changing settings.', 'chada-aio')); }
            return CHADA_AIO_Config::save($tab, $values);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function cleanup_preview(\WP_REST_Request $request): array {
        $categories = CHADA_AIO_Cleanup::validate_categories(self::string_list(self::array_input(self::input($request), 'categories')));
        return ['counts' => array_map('count', CHADA_AIO_Cleanup::preview($categories)), 'tables' => CHADA_AIO_Cleanup::tables()];
    }

    public static function undo(\WP_REST_Request $request): array {
        if (self::string_input(self::input($request), 'confirm') !== 'UNDO') { throw new \RuntimeException(__('Confirm image undo.', 'chada-aio')); }
        $id = self::integer_value($request['id'], 1, PHP_INT_MAX);
        $lock = CHADA_AIO_Runner::lock();
        try {
            $active = CHADA_AIO_Runner::active();
            if ($active && in_array($active['status'], ['running', 'rolling_back', 'recovery_required'], true)) { throw new \RuntimeException(__('Finish the active job before undoing images.', 'chada-aio')); }
            CHADA_AIO_Images::undo($id); return ['restored' => true];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function user_state(\WP_REST_Request $request): array {
        $input = self::input($request); $kind = self::string_input($input, 'kind');
        if (!in_array($kind, ['wizard', 'tour'], true)) { throw new \RuntimeException(__('Unknown user preference.', 'chada-aio')); }
        $value = ['step' => self::integer_input($input, 'step', 0, 50, 0), 'complete' => self::boolean_input($input, 'complete')];
        if ($kind === 'wizard' && $value['complete']) {
            $settings_errors = CHADA_AIO_Config::readiness_errors();
            // translators: %s is the first setup readiness error.
            if ($settings_errors) { throw new \RuntimeException(sprintf(__('Resolve the setup issue before completing setup: %s', 'chada-aio'), $settings_errors[0]['message'])); }
            if (!class_exists('ZipArchive')) { throw new \RuntimeException(__('The PHP ZIP extension is required before completing setup.', 'chada-aio')); }
        }
        update_user_meta(get_current_user_id(), CHADA_AIO_Config::meta($kind), $value);
        if ($kind === 'wizard' && $value['complete']) { CHADA_AIO_Config::complete_setup(); }
        return $value;
    }

    public static function download(): void {
        if (!self::permission()) { wp_die(esc_html__('Administrator access is required.', 'chada-aio'), '', ['response' => 403]); }
        $id = isset($_GET['id']) && is_string($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';
        check_admin_referer('chada-aio_download_' . $id);
        try {
            $id = CHADA_AIO_Storage::id($id);
            $archive = CHADA_AIO_Catalog::archive($id);
            if (!is_file($archive)) { throw new \RuntimeException(__('Archive file is missing.', 'chada-aio')); }
            nocache_headers(); header('Content-Type: application/zip'); header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="chada-' . CHADA_AIO_Storage::id($id) . '.zip"');
            header('Content-Length: ' . filesize($archive));
            $handle = fopen($archive, 'rb');
            if (!$handle) { throw new \RuntimeException(__('Archive is unreadable.', 'chada-aio')); }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated ZIP response; escaping would corrupt binary archive bytes.
            while (!feof($handle)) { echo fread($handle, 65536); }
            fclose($handle); exit;
        } catch (\Throwable $error) { wp_die(esc_html($error->getMessage())); }
    }
}
