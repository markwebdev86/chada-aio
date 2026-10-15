<?php
/** Reversible same-format image processing with per-image original preservation. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Only validated wp-content/uploads attachment paths are replaced; plugin directories are never image targets.

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions become REST JSON errors or are escaped by CHADA_AIO_Admin_Menu::render; never printed as HTML here.

// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.unlink_unlink -- Private local streams require seek, locks and atomic replacement; paths are confined by CHADA_AIO_Storage. Remote filesystem transports cannot provide these guarantees.


final class CHADA_AIO_Images {
    public static function bounds(int $width, int $height): array {
        $long = max((int) CHADA_AIO_Config::get('landscape_width'), (int) CHADA_AIO_Config::get('landscape_height'));
        $short = min((int) CHADA_AIO_Config::get('landscape_width'), (int) CHADA_AIO_Config::get('landscape_height'));
        if ($width === $height) { return [$short, $short]; }
        return $width > $height ? [$long, $short] : [$short, $long];
    }

    private static function fingerprint(array $files): string {
        $values = [];
        foreach ($files as $file) { $values[$file] = hash_file('sha256', CHADA_AIO_Content::uploads_path($file)); }
        return hash('sha256', wp_json_encode($values));
    }

    /** Preserve recognition of original fingerprints saved before custom uploads paths were supported. */
    private static function previous_fingerprint(array $files): string {
        $values = [];
        foreach ($files as $file) { $values['uploads/' . $file] = hash_file('sha256', CHADA_AIO_Content::uploads_path($file)); }
        return hash('sha256', wp_json_encode($values));
    }

    /** Find the persisted original entry key, accepting the former uploads/ prefix. */
    private static function original_key(string $relative, array $entries): string {
        if (isset($entries[$relative])) { return $relative; }
        $previous_entry = 'uploads/' . $relative;
        if (isset($entries[$previous_entry])) { return $previous_entry; }
        throw new \RuntimeException(__('A retained original file is missing.', 'chada-aio'));
    }

    public static function process(int $id): array {
        if (get_post_type($id) !== 'attachment') { throw new \RuntimeException(__('Selected record is not an attachment.', 'chada-aio')); }
        $mime = get_post_mime_type($id);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { return ['status' => 'skipped', 'reason' => __('Unsupported image format.', 'chada-aio')]; }
        $files = CHADA_AIO_Content::attachment_files($id);
        $file = CHADA_AIO_Content::uploads_path($files[0]); $header = file_get_contents($file, false, null, 0, 1048576);
        if (($mime === 'image/webp' && strpos($header, 'ANIM') !== false)
            || ($mime === 'image/png' && strpos($header, 'acTL') !== false)) { return ['status' => 'skipped', 'reason' => __('Animated images are preserved.', 'chada-aio')]; }
        $dimensions = wp_getimagesize($file);
        if (!$dimensions || $dimensions[0] * $dimensions[1] > 60000000) { return ['status' => 'skipped', 'reason' => __('Image exceeds the 60-megapixel decode safety ceiling.', 'chada-aio')]; }
        $settings = ['quality' => CHADA_AIO_Config::get('quality'), 'width' => CHADA_AIO_Config::get('landscape_width'), 'height' => CHADA_AIO_Config::get('landscape_height')];
        $signature = hash('sha256', wp_json_encode($settings)); $fingerprint = self::fingerprint($files); $previous_path_fingerprint = self::previous_fingerprint($files);
        $previous = get_post_meta($id, CHADA_AIO_Config::meta('optimized'), true);
        $stored_fingerprint = (string) ($previous['fingerprint'] ?? '');
        if (is_array($previous) && ($previous['settings'] ?? '') === $signature && in_array($stored_fingerprint, [$fingerprint, $previous_path_fingerprint], true)) {
            return ['status' => 'skipped', 'reason' => __('Already optimized with these settings.', 'chada-aio')];
        }
        $original_id = get_post_meta($id, CHADA_AIO_Config::meta('original'), true);
        if ($original_id && is_array($previous) && !in_array($stored_fingerprint, [$fingerprint, $previous_path_fingerprint], true)) {
            return ['status' => 'skipped', 'reason' => __('Image changed outside Chada. Undo or review its retained original before processing again.', 'chada-aio')];
        }
        if (!$original_id) {
            $original_id = wp_generate_uuid4(); $root = CHADA_AIO_Storage::root() . '/originals/' . $original_id;
            $entries = [];
            foreach ($files as $relative) { $entries[$relative] = CHADA_AIO_Package::copy(CHADA_AIO_Content::uploads_path($relative), CHADA_AIO_Storage::path($root, 'files/' . $relative)); }
            CHADA_AIO_Storage::json($root . '/original.json', ['attachment' => $id, 'metadata' => wp_get_attachment_metadata($id),
                'attached_file' => get_post_meta($id, '_wp_attached_file', true), 'entries' => $entries]);
            update_post_meta($id, CHADA_AIO_Config::meta('original'), $original_id);
        }
        $root = CHADA_AIO_Storage::root() . '/originals/' . CHADA_AIO_Storage::id($original_id);
        $original = CHADA_AIO_Storage::read($root . '/original.json'); $before = 0; $after = 0;
        try {
            foreach ($files as $relative) {
                $target = CHADA_AIO_Content::uploads_path($relative); $before += filesize($target);
                $original_key = self::original_key($relative, $original['entries']);
                $source = CHADA_AIO_Storage::path($root, 'files/' . $original_key);
                $dimensions = wp_getimagesize($source);
                if (!$dimensions || $dimensions[0] * $dimensions[1] > 60000000) { throw new \RuntimeException(__('An original or derivative exceeds the image decode safety ceiling.', 'chada-aio')); }
                if (hash_file('sha256', $source) !== $original['entries'][$original_key]['sha256']) { throw new \RuntimeException(__('Retained original checksum failed.', 'chada-aio')); }
                $editor = wp_get_image_editor($source);
                if (is_wp_error($editor)) { throw new \RuntimeException($editor->get_error_message()); }
                $size = $editor->get_size(); [$max_width, $max_height] = self::bounds($size['width'], $size['height']);
                $rotated = $editor->maybe_exif_rotate();
                if (is_wp_error($rotated)) { throw new \RuntimeException($rotated->get_error_message()); }
                $size = $editor->get_size(); [$max_width, $max_height] = self::bounds($size['width'], $size['height']);
                $resized = $size['width'] > $max_width || $size['height'] > $max_height;
                if ($resized) { $result = $editor->resize($max_width, $max_height, false); if (is_wp_error($result)) { throw new \RuntimeException($result->get_error_message()); } }
                $editor->set_quality((int) $settings['quality']);
                $temp = CHADA_AIO_Content::uploads_path($relative . '.chada-tmp.' . pathinfo($target, PATHINFO_EXTENSION));
                $saved = $editor->save($temp, $mime);
                if (is_wp_error($saved)) { throw new \RuntimeException($saved->get_error_message()); }
                $candidate = wp_normalize_path((string) ($saved['path'] ?? ''));
                if ($candidate !== wp_normalize_path($temp) || !CHADA_AIO_Storage::inside($candidate, dirname($target))) {
                    throw new \RuntimeException(__('The image editor returned a file outside the attachment directory.', 'chada-aio'));
                }
                if (!$resized && filesize($candidate) >= filesize($target)) { unlink($candidate); }
                elseif (!rename($candidate, $target)) { throw new \RuntimeException(__('Image replacement failed.', 'chada-aio')); }
                clearstatcache(true, $target); $after += filesize($target); unset($editor);
            }
            $metadata = wp_get_attachment_metadata($id);
            $main = wp_getimagesize($file);
            $metadata['width'] = $main[0]; $metadata['height'] = $main[1]; $metadata['filesize'] = filesize($file);
            foreach (($metadata['sizes'] ?? []) as $name => $entry) {
                $path = CHADA_AIO_Content::uploads_path(CHADA_AIO_Content::uploads_relative_path(dirname($file) . '/' . $entry['file'])); $size = wp_getimagesize($path);
                if ($size) { $metadata['sizes'][$name]['width'] = $size[0]; $metadata['sizes'][$name]['height'] = $size[1]; $metadata['sizes'][$name]['filesize'] = filesize($path); }
            }
            wp_update_attachment_metadata($id, $metadata);
            update_post_meta($id, CHADA_AIO_Config::meta('optimized'), ['settings' => $signature, 'fingerprint' => self::fingerprint($files)]);
            return ['status' => 'processed', 'before' => $before, 'after' => $after, 'saved' => $before - $after, 'original' => $original_id];
        } catch (\Throwable $error) { self::undo($id); throw $error; }
    }

    public static function undo(int $id): void {
        $original_id = get_post_meta($id, CHADA_AIO_Config::meta('original'), true);
        if (!$original_id) { throw new \RuntimeException(__('No retained original is available for this image.', 'chada-aio')); }
        $root = CHADA_AIO_Storage::root() . '/originals/' . CHADA_AIO_Storage::id($original_id);
        $original = CHADA_AIO_Storage::read($root . '/original.json');
        if ((int) $original['attachment'] !== $id) { throw new \RuntimeException(__('Original attachment identity mismatch.', 'chada-aio')); }
        $destinations = []; $attached_file = CHADA_AIO_Content::uploads_relative((string) ($original['attached_file'] ?? ''));
        if ($attached_file === '') { throw new \RuntimeException(__('The original attachment path is invalid or missing.', 'chada-aio')); }
        $attached_path = CHADA_AIO_Content::uploads_path($attached_file);
        foreach ($original['entries'] as $relative => $entry) {
            $source = CHADA_AIO_Storage::path($root, 'files/' . $relative);
            if (hash_file('sha256', $source) !== $entry['sha256']) { throw new \RuntimeException(__('Original image checksum failed.', 'chada-aio')); }
            $destinations[$relative] = CHADA_AIO_Content::uploads_path((string) $relative);
        }
        if (!is_file($attached_path)) { throw new \RuntimeException(__('The original attachment path is invalid or missing.', 'chada-aio')); }
        foreach ($destinations as $relative => $target) { CHADA_AIO_Package::copy(CHADA_AIO_Storage::path($root, 'files/' . $relative), $target); }
        update_post_meta($id, '_wp_attached_file', $attached_file);
        wp_update_attachment_metadata($id, $original['metadata']);
        delete_post_meta($id, CHADA_AIO_Config::meta('optimized'));
    }

    public static function step(array &$job): void {
        if ($job['stage'] === 'start') {
            $ids = array_values(array_unique(array_map('absint', $job['args']['ids'] ?? [])));
            $limit = CHADA_AIO_Plugin::selected_image_limit();
            /* translators: %d is the selected-image limit for the active edition. */
            $message = sprintf(__('Select between 1 and %d images for this operation. You can run another batch afterwards.', 'chada-aio'), $limit);
            if (!$ids || count($ids) > $limit) { throw new \RuntimeException($message); }
            $job['image_ids'] = $ids; $job['image_offset'] = 0;
            $job['result'] = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'saved' => 0, 'images' => []];
            $job['stage'] = 'images'; return;
        }
        self::batch_step($job);
    }

    /** Functional per-image primitive shared with Pro's independent selection/orchestration provider. */
    public static function batch_step(array &$job): void {
        if (isset($job['image_ids'][$job['image_offset']])) {
            $id = $job['image_ids'][$job['image_offset']];
            try { $result = self::process($id); }
            catch (\Throwable $error) { $result = ['status' => 'failed', 'reason' => $error->getMessage()]; }
            $job['result'][$result['status']]++;
            $job['result']['saved'] += $result['saved'] ?? 0;
            $job['result']['images'][] = ['id' => $id] + $result;
            $job['image_offset']++;
            $job['message'] = sprintf('Processed %d of %d selected images.', $job['image_offset'], count($job['image_ids'])); return;
        }
        $job['status'] = 'complete'; $job['stage'] = 'done'; $job['message'] = __('Image job completed. Review processed, skipped, and failed results.', 'chada-aio');
        CHADA_AIO_Catalog::put(['id' => $job['id'], 'operation' => 'optimize', 'type' => $job['type'], 'status' => 'complete',
            'created_at' => gmdate('Y-m-d H:i:s'), 'bytes' => 0, 'protected' => false, 'result' => $job['result']]);
    }
}
