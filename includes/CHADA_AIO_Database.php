<?php
/** Chunked, binary-safe database snapshots and staged table replacement. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as REST JSON or escaped in CHADA_AIO_Admin_Menu::render; no exception message is printed as raw HTML.

// phpcs:disable WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Snapshot DDL/RENAME cannot use value placeholders. Every identifier passes identifier(); schema() rejects comments, external references and multiple statements; row values use wpdb prepare/replace.

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Private local streams require seek, locks and atomic replacement; paths are confined by CHADA_AIO_Storage. Remote filesystem transports cannot provide these guarantees.


final class CHADA_AIO_Database {
    public static function is_operational_suffix(string $suffix): bool {
        foreach ([CHADA_AIO_PREFIX, CHADA_AIO_Installer::LEGACY_PREFIX, CHADA_AIO_Installer::RETIRED_PREFIX] as $prefix) {
            if (strpos($suffix, $prefix) === 0) { return true; }
        }
        return false;
    }

    public static function is_operational_option(string $name): bool {
        foreach ([CHADA_AIO_PREFIX, CHADA_AIO_Installer::LEGACY_PREFIX, CHADA_AIO_Installer::RETIRED_PREFIX] as $prefix) {
            if (strpos($name, $prefix) === 0) { return true; }
        }
        return false;
    }

    public static function identifier(string $name): string {
        if (!preg_match('/^[A-Za-z0-9_$]+$/D', $name) || strlen($name) > 64) { throw new \RuntimeException(__('Unsupported database identifier.', 'chada-aio')); }
        return '`' . $name . '`';
    }

    public static function tables(): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SHOW FULL TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'), ARRAY_N);
        $tables = [];
        foreach ($rows as $row) {
            if (self::is_operational_suffix(substr($row[0], strlen($wpdb->prefix)))) { continue; }
            if ($row[1] !== 'BASE TABLE') { throw new \RuntimeException(__('CHADA_AIO_Database views require an external database backup.', 'chada-aio')); }
            $tables[] = $row[0];
        }
        foreach ($wpdb->get_results('SHOW TRIGGERS', ARRAY_A) as $trigger) {
            if (in_array($trigger['Table'], $tables, true)) { throw new \RuntimeException(__('CHADA_AIO_Database triggers require an external database backup.', 'chada-aio')); }
        }
        return $tables;
    }

    /** Accept a single table DDL only; reject external references, executable comments and extra statements. */
    public static function schema(string $sql, string $original, string $target): string {
        $prefix = 'CREATE TABLE ' . self::identifier($original) . ' (';
        if (strpos($sql, $prefix) !== 0) { throw new \RuntimeException(__('Invalid table schema header.', 'chada-aio')); }
        $plain = ''; $quote = ''; $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== '') {
                if ($char === '\\') { $i++; continue; }
                if ($char === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote) { $i++; } else { $quote = ''; }
                }
                continue;
            }
            if (in_array($char, ["'", '"', '`'], true)) { $quote = $char; $plain .= ' '; continue; }
            $plain .= $char;
        }
        if ($quote !== '' || preg_match('~;|/\*|--|#|\b(SELECT|REFERENCES|FOREIGN|TRIGGER|TABLESPACE|DIRECTORY|UNION|PARTITION)\b~i', $plain)) {
            throw new \RuntimeException(__('Unsupported schema: external references, partitions, comments or multiple statements.', 'chada-aio'));
        }
        if (!preg_match('/\)\s+ENGINE=(InnoDB|MyISAM)\b/i', $plain)) { throw new \RuntimeException(__('Unsupported database storage engine.', 'chada-aio')); }
        return 'CREATE TABLE ' . self::identifier($target) . substr($sql, strlen('CREATE TABLE ' . self::identifier($original)));
    }

    /** Validate archived schema metadata without creating staging tables or changing the live database. */
    public static function validate_schema(string $directory, array $manifest): array {
        return self::validate_schema_data(CHADA_AIO_Storage::read($directory . '/database/schema.json'), $manifest);
    }

    /** Validate an in-memory schema payload before it is staged on disk or applied to the live database. */
    public static function validate_schema_data(array $schema, array $manifest): array {
        global $wpdb;
        $tables = []; $seen = [];
        $source_prefix = (string) ($schema['prefix'] ?? '');
        if ($source_prefix === '' || empty($schema['tables']) || !is_array($schema['tables'])) { throw new \RuntimeException(__('Empty database schema.', 'chada-aio')); }
        foreach ($schema['tables'] as $index => $table) {
            $name = (string) ($table['name'] ?? ''); $file = (string) ($table['file'] ?? ''); $columns = (array) ($table['columns'] ?? []);
            if (strpos($name, $source_prefix) !== 0) { throw new \RuntimeException(__('Table is outside the archived site prefix.', 'chada-aio')); }
            $suffix = substr($name, strlen($source_prefix));
            if ($suffix === '' || self::is_operational_suffix($suffix)) { throw new \RuntimeException(__('Cannot import operational tables.', 'chada-aio')); }
            $target = $wpdb->prefix . $suffix;
            if (isset($seen[$target])) { throw new \RuntimeException(__('Duplicate table in schema.', 'chada-aio')); }
            $seen[$target] = true; CHADA_AIO_Storage::relative($file);
            if (strpos($file, 'database/') !== 0 || !isset($table['schema']) || !is_string($table['schema']) || !isset($table['rows']) || !is_array($table['columns'] ?? null)) { throw new \RuntimeException(__('Invalid database row path.', 'chada-aio')); }
            foreach ($columns as $column) { if (!is_string($column)) { throw new \RuntimeException(__('Invalid database column metadata.', 'chada-aio')); } self::identifier($column); }
            $row_key = '__chada_row_' . substr(str_replace('-', '', (string) ($manifest['source_id'] ?? '')), 0, 10);
            if (in_array($row_key, $columns, true) || (int) ($table['rows'] ?? -1) < 0) { throw new \RuntimeException(__('Invalid database staging metadata.', 'chada-aio')); }
            self::schema($table['schema'], $name, $target); $tables[] = ['name' => $name, 'target' => $target, 'file' => $file, 'rows' => (int) $table['rows']];
        }
        return ['prefix' => $source_prefix, 'tables' => $tables];
    }

    public static function begin(string $directory): array {
        global $wpdb;
        $tables = []; $names = self::tables(); $estimates = [];
        if ($names) {
            $estimate_rows = $wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'), ARRAY_A);
            foreach ($estimate_rows as $estimate) { $estimates[$estimate['TABLE_NAME']] = (int) ($estimate['TABLE_ROWS'] ?? 0); }
        }
        $queries = $names ? 3 : 1;
        foreach ($names as $index => $name) {
            $quoted = self::identifier($name);
            $definition = $wpdb->get_row("SHOW CREATE TABLE $quoted", ARRAY_N);
            if (!$definition) { throw new \RuntimeException(__('Cannot read table schema.', 'chada-aio')); }
            self::schema($definition[1], $name, $name);
            $definitions = $wpdb->get_results("SHOW FULL COLUMNS FROM $quoted", ARRAY_A); $queries++;
            $primary = array_values(array_filter($definitions, static function (array $column): bool { return ($column['Key'] ?? '') === 'PRI'; }));
            $columns = array_values(array_map(static function (array $column): string { return $column['Field']; },
                array_filter($definitions, static function (array $column): bool { return stripos($column['Extra'] ?? '', 'GENERATED') === false; })));
            $cursor = null;
            if (count($primary) === 1 && in_array($primary[0]['Field'], $columns, true)
                && preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double)\b/i', $primary[0]['Type'] ?? '')) { $cursor = $primary[0]['Field']; }
            $tables[] = ['name' => $name, 'schema' => $definition[1], 'columns' => $columns,
                'rows' => $estimates[$name] ?? 0, 'rows_estimate' => $estimates[$name] ?? 0, 'actual_rows' => 0,
                'file' => 'database/' . $index . '.jsonl', 'cursor_column' => $cursor];
        }
        CHADA_AIO_Storage::mkdir($directory . '/database');
        CHADA_AIO_Storage::json($directory . '/database/schema.json', ['prefix' => $wpdb->prefix, 'tables' => $tables]);
        return ['table' => 0, 'offset' => 0, 'cursor' => null, 'bytes' => 0, 'tables' => $tables, 'queries' => $queries, 'batches' => 0, 'rows_exported' => 0];
    }

    public static function export_step(string $directory, array &$state): bool {
        global $wpdb;
        $batches = 0;
        while (isset($state['tables'][$state['table']]) && $batches < CHADA_AIO_Config::DB_BATCHES
            && ($batches === 0 || CHADA_AIO_Runner::work_available())) {
            $table = $state['tables'][$state['table']]; $columns = implode(',', array_map([self::class, 'identifier'], $table['columns'])); $name = self::identifier($table['name']);
            $where = ''; $order = ''; $offset = '';
            if (!empty($table['cursor_column'])) {
                $cursor_column = self::identifier($table['cursor_column']); $order = " ORDER BY $cursor_column ASC";
                if ($state['cursor'] !== null) { $where = $wpdb->prepare(" WHERE $cursor_column > %s", (string) $state['cursor']); }
            } else { $offset = $wpdb->prepare(' OFFSET %d', $state['offset']); }
            $query = $wpdb->prepare("SELECT $columns FROM $name$where$order LIMIT %d$offset", CHADA_AIO_Config::ROW_BATCH); $rows = $wpdb->get_results($query, ARRAY_N); $state['queries'] = (int) ($state['queries'] ?? 0) + 1;
            if ($wpdb->last_error) { throw new \RuntimeException(__('CHADA_AIO_Database snapshot query failed.', 'chada-aio')); }
            $path = CHADA_AIO_Storage::path($directory, $table['file']); $handle = fopen($path, 'c+b');
            if (!$handle) { throw new \RuntimeException(__('Cannot write database rows.', 'chada-aio')); }
            try {
                if (!ftruncate($handle, $state['bytes']) || fseek($handle, $state['bytes'], SEEK_SET) !== 0) { throw new \RuntimeException(__('Cannot resume database capture at its durable checkpoint.', 'chada-aio')); }
                $lines = [];
                foreach ($rows as $row) {
                    $encoded = array_map(static function ($value) { return $value === null ? null : base64_encode((string) $value); }, $row); $json = wp_json_encode($encoded);
                    if ($json === false) { throw new \RuntimeException(__('Incomplete database row encoding.', 'chada-aio')); }
                    $lines[] = $json . "\n";
                }
                $data = implode('', $lines); if ($data !== '' && fwrite($handle, $data) !== strlen($data)) { throw new \RuntimeException(__('Incomplete database row write.', 'chada-aio')); }
                $state['bytes'] = ftell($handle);
            } finally { fclose($handle); }
            $row_count = count($rows);
            if (!array_key_exists('actual_rows', $state['tables'][$state['table']])) { $state['tables'][$state['table']]['actual_rows'] = (int) $state['offset']; }
            $state['offset'] += $row_count;
            $state['tables'][$state['table']]['actual_rows'] = (int) ($state['tables'][$state['table']]['actual_rows'] ?? 0) + $row_count;
            $state['rows_exported'] = (int) ($state['rows_exported'] ?? 0) + $row_count;
            if (!empty($table['cursor_column']) && $rows) {
                $cursor_index = array_search($table['cursor_column'], $table['columns'], true);
                if ($cursor_index === false || $rows[$row_count - 1][$cursor_index] === null) { throw new \RuntimeException(__('Database keyset cursor is invalid.', 'chada-aio')); }
                $state['cursor'] = (string) $rows[$row_count - 1][$cursor_index];
            }
            $state['batches'] = (int) ($state['batches'] ?? 0) + 1; $batches++;
            if ($row_count < CHADA_AIO_Config::ROW_BATCH) {
                $state['tables'][$state['table']]['rows'] = (int) $state['tables'][$state['table']]['actual_rows'];
                $state['table']++; $state['offset'] = 0; $state['cursor'] = null; $state['bytes'] = 0;
            }
        }
        if ($state['table'] < count($state['tables'])) { return false; }
        $schema_path = $directory . '/database/schema.json'; $schema = CHADA_AIO_Storage::read($schema_path);
        foreach ($schema['tables'] as $index => &$schema_table) {
            $schema_table['rows'] = (int) ($state['tables'][$index]['rows'] ?? 0);
            $schema_table['rows_estimate'] = (int) ($state['tables'][$index]['rows_estimate'] ?? $schema_table['rows']);
            unset($schema_table['actual_rows']);
        }
        unset($schema_table);
        CHADA_AIO_Storage::json($schema_path, $schema);
        return true;
    }

    /** Rewrite scalars/arrays only, never instantiate classes from archived serialized values. */
    public static function remap(string $value, array $map): string {
        if (!$map) { return $value; }
        if (is_serialized($value)) {
            $decoded = @unserialize($value, ['allowed_classes' => false]);
            if ($decoded === false && $value !== 'b:0;') { throw new \RuntimeException(__('Invalid serialized value in database archive.', 'chada-aio')); }
            $walk = static function ($item) use (&$walk, $map) {
                if (is_object($item)) { throw new \RuntimeException(__('Serialized objects cannot be migrated automatically.', 'chada-aio')); }
                if (is_array($item)) {
                    $out = [];
                    foreach ($item as $key => $child) { $out[is_string($key) ? strtr($key, $map) : $key] = $walk($child); }
                    return $out;
                }
                return is_string($item) ? strtr($item, $map) : $item;
            };
            return serialize($walk($decoded));
        }
        return strtr($value, $map);
    }

    public static function prepare(string $directory, string $id, bool $migration, array $manifest): array {
        global $wpdb;
        $schema = CHADA_AIO_Storage::read($directory . '/database/schema.json');
        self::validate_schema($directory, $manifest);
        $tables = []; $token = substr(str_replace('-', '', $id), 0, 10); $seen = []; $row_key = '__chada_row_' . $token;
        if (empty($schema['prefix']) || empty($schema['tables']) || !is_array($schema['tables'])) { throw new \RuntimeException(__('Empty database schema.', 'chada-aio')); }
        foreach ($schema['tables'] as $i => $table) {
            if (strpos($table['name'], $schema['prefix']) !== 0) { throw new \RuntimeException(__('Table is outside the archived site prefix.', 'chada-aio')); }
            $suffix = substr($table['name'], strlen($schema['prefix']));
            if (self::is_operational_suffix($suffix)) { throw new \RuntimeException(__('Cannot import operational tables.', 'chada-aio')); }
            $target = $wpdb->prefix . $suffix;
            if (isset($seen[$target])) { throw new \RuntimeException(__('Duplicate table in schema.', 'chada-aio')); }
            $seen[$target] = true;
            CHADA_AIO_Storage::relative($table['file']);
            if (strpos($table['file'], 'database/') !== 0) { throw new \RuntimeException(__('Invalid database row path.', 'chada-aio')); }
            foreach ($table['columns'] as $column) { self::identifier($column); }
            if (in_array($row_key, $table['columns'], true)) { throw new \RuntimeException(__('Reserved staging column conflict.', 'chada-aio')); }
            $temp = $wpdb->prefix . CHADA_AIO_PREFIX . 'stage_' . $token . '_' . $i;
            $old = $wpdb->prefix . CHADA_AIO_PREFIX . 'old_' . $token . '_' . $i;
            $sql = self::schema($table['schema'], $table['name'], $temp);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($old)))) { throw new \RuntimeException(__('A previous database swap needs recovery.', 'chada-aio')); }
            $existing_temp = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($temp)));
            if ($existing_temp && $wpdb->query('DROP TABLE ' . self::identifier($temp)) === false) { throw new \RuntimeException(__('Could not reset this job staging table.', 'chada-aio')); }
            if ($wpdb->query($sql) === false) { throw new \RuntimeException(__('Could not create staging table.', 'chada-aio')); }
            if ($wpdb->query('ALTER TABLE ' . self::identifier($temp) . ' ADD COLUMN ' . self::identifier($row_key) . ' bigint unsigned NULL UNIQUE') === false) {
                throw new \RuntimeException(__('Could not create the staging replay checkpoint.', 'chada-aio'));
            }
            $existed = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($target)));
            $tables[] = array_merge($table, ['target' => $target, 'temp' => $temp, 'old' => $old, 'existed' => $existed]);
        }
        $map = $migration ? array_filter([$manifest['home'] => home_url(), $manifest['siteurl'] => site_url(),
            $manifest['content_path'] => CHADA_AIO_Locations::content_root(), ($manifest['content_url'] ?? '') => content_url(),
            ($manifest['uploads_url'] ?? '') => CHADA_AIO_Content::uploads_url()], static function ($key) { return $key !== ''; }, ARRAY_FILTER_USE_KEY) : [];
        return ['tables' => $tables, 'table' => 0, 'offset' => 0, 'row' => 0, 'row_key' => $row_key,
            'map' => $map, 'migration' => $migration, 'source_prefix' => $schema['prefix'], 'swapped' => false];
    }

    /** Replace a bounded batch with one query at a time while respecting the server packet budget. */
    private static function replace_rows(string $table, array $rows): void {
        global $wpdb;
        if (!$rows) { return; }
        $columns = array_keys($rows[0]); $quoted_columns = implode(',', array_map([self::class, 'identifier'], $columns));
        $prefix = 'REPLACE INTO ' . self::identifier($table) . " ($quoted_columns) VALUES "; $values_sql = []; $params = []; $length = strlen($prefix);
        $flush = static function () use (&$values_sql, &$params, $prefix, $wpdb): void {
            $query = $prefix . implode(',', $values_sql); $prepared = $wpdb->prepare($query, ...$params);
            if ($wpdb->query($prepared) === false) { throw new \RuntimeException(__('Staging a database row batch failed.', 'chada-aio')); }
            $values_sql = []; $params = [];
        };
        foreach ($rows as $row) {
            $row_params = []; $placeholders = [];
            foreach ($columns as $column) {
                if ($row[$column] === null) { $placeholders[] = 'NULL'; continue; }
                $placeholders[] = '%s'; $row_params[] = $row[$column];
            }
            $row_sql = '(' . implode(',', $placeholders) . ')';
            $parameter_bytes = 2 * array_sum(array_map(static function ($value): int { return strlen((string) $value); }, $row_params));
            if ($values_sql && $length + strlen($row_sql) + 1 + $parameter_bytes > CHADA_AIO_Config::DB_QUERY_BYTES) { $flush(); $length = strlen($prefix); }
            $values_sql[] = $row_sql; $params = array_merge($params, $row_params); $length += strlen($row_sql) + 1 + $parameter_bytes;
        }
        $flush();
    }

    public static function import_step(string $directory, array &$state): bool {
        global $wpdb;
        if (!isset($state['tables'][$state['table']])) { return true; }
        $table = $state['tables'][$state['table']];
        $handle = fopen(CHADA_AIO_Storage::path($directory, $table['file']), 'rb');
        if (!$handle || fseek($handle, $state['offset']) !== 0) { throw new \RuntimeException(__('Cannot resume database import.', 'chada-aio')); }
        $count = 0; $rows = []; $checkpoint = [$state['table'], $state['offset'], $state['row']];
        try {
            if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException(__('Cannot start staging transaction.', 'chada-aio')); }
            while ($count < CHADA_AIO_Config::ROW_BATCH && ($line = fgets($handle, 16777216)) !== false) {
                if (substr($line, -1) !== "\n") { throw new \RuntimeException(__('Oversized or incomplete database row.', 'chada-aio')); }
                $values = json_decode($line, true);
                if (!is_array($values) || count($values) !== count($table['columns'])) { throw new \RuntimeException(__('Invalid database row shape.', 'chada-aio')); }
                $row = [];
                foreach ($table['columns'] as $i => $column) {
                    $value = $values[$i] === null ? null : base64_decode($values[$i], true);
                    if ($value === false) { throw new \RuntimeException(__('Invalid binary database value.', 'chada-aio')); }
                    $row[$column] = $value;
                }
                if ($state['map'] && (($table['target'] === $wpdb->options && preg_match('/^_(site_)?transient_/', $row['option_name'] ?? ''))
                    || ($table['target'] === $wpdb->postmeta && in_array($row['meta_key'] ?? '', [CHADA_AIO_Config::meta('original'), CHADA_AIO_Config::meta('optimized')], true)))) {
                    $count++; $state['row']++; continue;
                }
                foreach ($row as $column => &$value) { if ($value !== null && $column !== 'guid') { $value = self::remap($value, $state['map']); } } unset($value);
                if ($table['target'] === $wpdb->options && isset($row['option_name']) && self::is_operational_option($row['option_name'])) {
                    $count++; $state['row']++; continue;
                }
                if ($state['map']) {
                    if ($table['target'] === $wpdb->options && $row['option_name'] === $state['source_prefix'] . 'user_roles') { $row['option_name'] = $wpdb->prefix . 'user_roles'; }
                    if ($table['target'] === $wpdb->usermeta && in_array($row['meta_key'], [$state['source_prefix'] . 'capabilities', $state['source_prefix'] . 'user_level'], true)) {
                        $row['meta_key'] = $wpdb->prefix . substr($row['meta_key'], strlen($state['source_prefix']));
                    }
                }
                $row[$state['row_key']] = ++$state['row'];
                $rows[] = $row;
                $count++;
            }
            self::replace_rows($table['temp'], $rows);
            $state['offset'] = ftell($handle);
            if (feof($handle)) {
                if ($state['row'] !== (int) $table['rows']) { throw new \RuntimeException(__('Archived database row count does not match its schema.', 'chada-aio')); }
                $state['table']++; $state['offset'] = 0; $state['row'] = 0;
            }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException(__('Cannot commit staged rows.', 'chada-aio')); }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            [$state['table'], $state['offset'], $state['row']] = $checkpoint;
            throw $error;
        }
        finally { fclose($handle); }
        return $state['table'] >= count($state['tables']);
    }

    /** Swap all prepared tables together. Original tables remain for an explicit rollback. */
    public static function commit(array &$state): void {
        global $wpdb;
        if (self::is_swapped($state)) { $state['swapped'] = true; wp_cache_flush(); return; }
        $renames = [];
        foreach ($state['tables'] as &$table) {
            if ($table['target'] === $wpdb->options) {
                if ($state['migration'] ?? !empty($state['map'])) { self::destination_locations($table['temp']); }
                $controls = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(CHADA_AIO_PREFIX) . '%'), ARRAY_A);
                foreach ($controls as $row) { unset($row['option_id']); $wpdb->replace($table['temp'], $row); }
                $active = $wpdb->get_var('SELECT option_value FROM ' . self::identifier($table['temp']) . " WHERE option_name = 'active_plugins'");
                $active = is_string($active) ? @unserialize($active, ['allowed_classes' => false]) : [];
                if (!is_array($active)) { throw new \RuntimeException(__('Invalid active plugin state in database archive.', 'chada-aio')); }
                foreach ((array) get_option('active_plugins', []) as $plugin) {
                    if (in_array($plugin, ['chada-aio/chada-aio.php',
                        'chada-aio-pro/chada-aio-pro.php'], true)) { $active[] = $plugin; }
                }
                $wpdb->update($table['temp'], ['option_value' => serialize(array_values(array_unique($active)))], ['option_name' => 'active_plugins']);
            }
            $marker = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . self::identifier($table['temp']) . ' LIKE %s', $wpdb->esc_like($state['row_key'])));
            if ($marker && $wpdb->query('ALTER TABLE ' . self::identifier($table['temp']) . ' DROP COLUMN ' . self::identifier($state['row_key'])) === false) {
                throw new \RuntimeException(__('Could not finalize staging schema.', 'chada-aio'));
            }
            if ($table['existed']) { $renames[] = self::identifier($table['target']) . ' TO ' . self::identifier($table['old']); }
            $renames[] = self::identifier($table['temp']) . ' TO ' . self::identifier($table['target']);
        }
        unset($table);
        if (!$renames || $wpdb->query('RENAME TABLE ' . implode(', ', $renames)) === false) { throw new \RuntimeException(__('Atomic database table swap failed.', 'chada-aio')); }
        $state['swapped'] = true;
        wp_cache_flush();
    }

    /** Keep host-specific uploads settings and resolve imported active themes against destination directories. */
    private static function destination_locations(string $table): void {
        global $wpdb;
        $values = ['home' => home_url(), 'siteurl' => site_url(), 'upload_path' => (string) get_option('upload_path', ''),
            'upload_url_path' => (string) get_option('upload_url_path', '')];
        foreach (['stylesheet' => 'stylesheet_root', 'template' => 'template_root'] as $selector => $option) {
            $slug = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $table, $selector));
            if (is_string($slug) && $slug !== '') { $values[$option] = CHADA_AIO_Locations::theme_root($slug); }
        }
        foreach ($values as $option => $value) {
            if ($wpdb->replace($table, ['option_name' => $option, 'option_value' => $value, 'autoload' => 'yes'], ['%s', '%s', '%s']) === false) {
                throw new \RuntimeException(__('Could not preserve destination directory settings.', 'chada-aio'));
            }
        }
    }

    public static function rollback(array &$state): void {
        global $wpdb;
        if (!self::is_swapped($state)) { $state['swapped'] = false; return; }
        $renames = [];
        foreach ($state['tables'] as $table) {
            $renames[] = self::identifier($table['target']) . ' TO ' . self::identifier($table['temp']);
            if (!empty($table['existed'])) { $renames[] = self::identifier($table['old']) . ' TO ' . self::identifier($table['target']); }
        }
        if ($wpdb->query('RENAME TABLE ' . implode(', ', $renames)) === false) { throw new \RuntimeException(__('Database rollback failed; retain the recovery journal for administrator recovery.', 'chada-aio')); }
        $state['swapped'] = false; wp_cache_flush();
    }

    /** Release only this journal's temporary tables; live target tables are never removed here. */
    public static function discard(array $state): void {
        global $wpdb;
        foreach (($state['tables'] ?? []) as $table) {
            foreach (['temp' => 'stage', 'old' => 'old'] as $key => $kind) {
                $name = $table[$key] ?? '';
                if (!preg_match('/^' . preg_quote($wpdb->prefix . CHADA_AIO_PREFIX, '/') . $kind . '_[a-f0-9]{10}_\d+$/D', $name)) {
                    throw new \RuntimeException(__('Invalid staging cleanup identifier.', 'chada-aio'));
                }
                if ($wpdb->query('DROP TABLE IF EXISTS ' . self::identifier($name)) === false) { throw new \RuntimeException(__('Cannot release staging tables.', 'chada-aio')); }
            }
        }
    }

    /** Detect a completed atomic swap after a process exit before its JSON checkpoint was written. */
    public static function is_swapped(array $state): bool {
        global $wpdb;
        $swapped = 0;
        foreach ($state['tables'] as $table) {
            $temp = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table['temp'])));
            $target = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table['target'])));
            $old = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table['old'])));
            if (!$temp && $target && (!$table['existed'] || $old)) { $swapped++; }
        }
        if ($swapped !== 0 && $swapped !== count($state['tables'])) { throw new \RuntimeException(__('Database staging state is inconsistent; retain the recovery archive and journal.', 'chada-aio')); }
        return $swapped === count($state['tables']);
    }
}
