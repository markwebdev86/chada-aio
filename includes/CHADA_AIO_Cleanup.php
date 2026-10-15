<?php
/** Explicit, previewable cleanup of disposable WordPress records; tables are inspection-only. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as REST JSON or escaped in CHADA_AIO_Admin_Menu::render; no exception message is printed as raw HTML.

final class CHADA_AIO_Cleanup {
    const CATEGORIES = ['revisions', 'trash', 'comments', 'transients'];

    /** Validate and canonicalize the cleanup categories supplied by an administrator. */
    public static function validate_categories(array $selected): array {
        foreach ($selected as $category) {
            if (!is_string($category)) { throw new \RuntimeException(__('Cleanup categories must be text values.', 'chada-aio')); }
        }
        $selected = array_values(array_intersect(self::CATEGORIES, $selected));
        if (!$selected) { throw new \RuntimeException(__('Select at least one cleanup category.', 'chada-aio')); }
        return $selected;
    }

    public static function preview(array $selected): array {
        global $wpdb;
        $selected = self::validate_categories($selected);
        $before = wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS * (int) CHADA_AIO_Config::get('cleanup_days'));
        $result = [];
        if (in_array('revisions', $selected, true)) {
            $rows = $wpdb->get_results("SELECT ID, post_parent, post_name, post_date FROM {$wpdb->posts} WHERE post_type = 'revision' ORDER BY post_parent, post_date DESC, ID DESC", ARRAY_A);
            $kept = []; $ids = [];
            foreach ($rows as $row) {
                if (strpos($row['post_name'], 'autosave') !== false) { continue; }
                $parent = $row['post_parent']; $kept[$parent] = ($kept[$parent] ?? 0) + 1;
                if ($kept[$parent] > (int) CHADA_AIO_Config::get('keep_revisions') && $row['post_date'] < $before) { $ids[] = (int) $row['ID']; }
            }
            $result['revisions'] = $ids;
        }
        if (in_array('trash', $selected, true)) {
            $result['trash'] = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_status = 'trash' AND p.post_type IN ('post','page') AND m.meta_key = '_wp_trash_meta_time' AND CAST(m.meta_value AS UNSIGNED) < %d", time() - DAY_IN_SECONDS * (int) CHADA_AIO_Config::get('cleanup_days'))));
        }
        if (in_array('comments', $selected, true)) {
            $result['comments'] = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash') AND comment_date < %s", $before)));
        }
        if (in_array('transients', $selected, true)) {
            $result['transients'] = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like('_transient_timeout_') . '%', time()));
        }
        return $result;
    }

    public static function tables(): array {
        global $wpdb;
        $known = $wpdb->tables('all', true); $result = [];
        foreach ($wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'), ARRAY_A) as $table) {
            $name = $table['Name'];
            $suffix = substr($name, strlen($wpdb->prefix));
            $owner = in_array($name, $known, true) ? 'WordPress core' : (CHADA_AIO_Database::is_operational_suffix($suffix) ? 'Chada AIO' : 'Unknown; inspect plugin documentation');
            $result[] = ['name' => $name, 'bytes' => (int) $table['Data_length'] + (int) $table['Index_length'], 'owner' => $owner];
        }
        return $result;
    }

    public static function step(array &$job): void {
        if ($job['stage'] === 'start') {
            $job['selection'] = $job['args']['selection'] ?? self::preview($job['args']['categories'] ?? []);
            if (empty($job['selection'])) { throw new \RuntimeException(__('Select a cleanup category.', 'chada-aio')); }
            $job['selection_total'] = array_sum(array_map('count', $job['selection']));
            foreach ($job['selection'] as &$ids) { $ids = array_reverse($ids); }
            unset($ids);
            $job['recovery'] = CHADA_AIO_Runner::new_state('backup', 'all', ['scope_paths' => [], 'scope_mode' => 'targeted'], $job['work'] . '/recovery');
            $job['recovery']['protected'] = true; $job['stage'] = 'recovery'; return;
        }
        if ($job['stage'] === 'recovery') {
            CHADA_AIO_Operations::backup($job['recovery']);
            if ($job['recovery']['status'] !== 'complete') { return; }
            $job['recovery_id'] = $job['recovery']['id'];
            $job['result'] = ['deleted' => 0]; $job['stage'] = 'clean'; return;
        }
        if ($job['stage'] === 'clean') {
            CHADA_AIO_Runner::maintenance($job['id']); $job['touched'] = true; CHADA_AIO_Runner::save($job);
            // Revalidate current eligibility: never delete solely because a previous preview selected a record.
            $eligible = self::preview(array_keys($job['selection'])); $count = 0;
            foreach ($job['selection'] as $category => &$ids) {
                while ($ids && $count < CHADA_AIO_Config::ROW_BATCH) {
                    $id = array_pop($ids); $count++;
                    if (!in_array($id, $eligible[$category] ?? [], true)) { continue; }
                    if (in_array($category, ['revisions', 'trash'], true)) { $deleted = wp_delete_post((int) $id, true); }
                    elseif ($category === 'comments') { $deleted = wp_delete_comment((int) $id, true); }
                    else { $deleted = delete_transient(substr($id, strlen('_transient_timeout_'))); }
                    if (!$deleted) { throw new \RuntimeException(__('A selected cleanup record could not be deleted.', 'chada-aio')); }
                    $job['result']['deleted']++;
                }
            }
            unset($ids);
            if (array_sum(array_map('count', $job['selection'])) > 0) { return; }
            $job['stage'] = 'done'; $job['status'] = 'complete'; $job['message'] = __('Selected records cleaned. CHADA_AIO_Database recovery backup retained.', 'chada-aio');
            CHADA_AIO_Runner::clear_maintenance($job['id']);
            CHADA_AIO_Catalog::put(['id' => $job['id'], 'operation' => 'cleanup', 'type' => 'database', 'status' => 'complete',
                'created_at' => gmdate('Y-m-d H:i:s'), 'bytes' => 0, 'protected' => false,
                'recovery_id' => $job['recovery_id'], 'result' => $job['result']]);
        }
    }
}
