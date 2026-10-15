<?php
/** Pro managed-history settings panel. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

/** @var int $chada_aio_count Number of managed history records. */
assert(isset($chada_aio_count));
/* translators: %d is the number of retained Pro-managed history records. */
$chada_aio_message = sprintf(__('Managed history contains %d records with no product count cap. Host storage limits still apply. New Pro-managed archives are excluded from ordinary rotation; explicit deletion remains available.', 'chada-aio'), $chada_aio_count);
?><section class="chada-panel"><h2><?php esc_html_e('Pro managed history', 'chada-aio'); ?></h2><p><?php echo esc_html($chada_aio_message); ?></p><p><?php esc_html_e('Deactivating Pro preserves existing managed archives and history. Free backup, migration and optimization remain independently usable.', 'chada-aio'); ?></p></section>
