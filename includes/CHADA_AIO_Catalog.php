<?php
/** Durable archive catalog with a rebuildable database index. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- %i is unavailable on older supported WordPress versions; table names are plugin-derived and identifier-bounded.

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as REST JSON or escaped in CHADA_AIO_Admin_Menu::render; no exception message is printed as raw HTML.

final class CHADA_AIO_Catalog {
    public static function put(array $record): void {
        global $wpdb;
        CHADA_AIO_Storage::id($record['id']);
        CHADA_AIO_Storage::json(CHADA_AIO_Storage::root() . '/archives/' . $record['id'] . '.json', $record);
        $wpdb->replace(CHADA_AIO_Config::table('records'), ['id' => $record['id'],
            'operation' => $record['operation'], 'type' => $record['type'], 'status' => $record['status'],
            'created_at' => $record['created_at'], 'protected' => !empty($record['protected']) ? 1 : 0,
            'metadata' => wp_json_encode($record)]);
        do_action('chada_aio_record_written', $record);
    }

    public static function get(string $id): array {
        return CHADA_AIO_Storage::read(CHADA_AIO_Storage::root() . '/archives/' . CHADA_AIO_Storage::id($id) . '.json');
    }

    public static function all(string $operation = ''): array {
        $records = [];
        foreach (glob(CHADA_AIO_Storage::root() . '/archives/*.json') ?: [] as $file) {
            try {
                $record = CHADA_AIO_Storage::read($file);
                if ($operation === '' || $record['operation'] === $operation) { $records[] = $record; }
            } catch (\Throwable $error) { continue; }
        }
        usort($records, static function ($a, $b) { return strcmp($b['created_at'] . $b['id'], $a['created_at'] . $a['id']); });
        return $records;
    }

    public static function count(): int {
        global $wpdb;
        $table = CHADA_AIO_Config::table('records');
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
    }

    public static function archive(string $id): string {
        $record = self::get($id);
        if (empty($record['archive'])) { throw new \RuntimeException(__('This operation has no downloadable archive.', 'chada-aio')); }
        return CHADA_AIO_Storage::path(CHADA_AIO_Storage::root(), $record['archive']);
    }

    public static function delete(string $id, bool $allow_protected = false): void {
        global $wpdb;
        $record = self::get($id);
        if (!empty($record['protected']) && !$allow_protected) { throw new \RuntimeException(__('Protected recovery data requires explicit cleanup confirmation.', 'chada-aio')); }
        if (!empty($record['archive'])) { CHADA_AIO_Storage::remove(self::archive($id)); }
        CHADA_AIO_Storage::remove(CHADA_AIO_Storage::root() . '/archives/' . CHADA_AIO_Storage::id($id) . '.json');
        $wpdb->delete(CHADA_AIO_Config::table('records'), ['id' => $id]);
    }

    /** Defaults are editable retention policies and do not disable core operations. */
    public static function rotate(string $operation): void {
        if (!in_array($operation, ['backup', 'export', 'import'], true)) { return; }
        $limit = (int) CHADA_AIO_Config::get($operation === 'backup' ? 'backup_retention' : 'migration_retention');
        $limit = (int) apply_filters('chada_aio_retention_count', $limit, $operation);
        if ($limit === 0) { return; }
        $records = array_values(array_filter(self::all($operation), static function ($record) {
            return $record['status'] === 'complete' && empty($record['protected']) && empty($record['managed']);
        }));
        foreach (array_slice($records, $limit) as $record) { self::delete($record['id']); }
    }
}
