<?php
/** Administrator-visible storage namespace migration warning. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

/** @var string $chada_aio_error Migration error message. */
assert(isset($chada_aio_error));
?><div class="notice notice-error"><p><strong><?php esc_html_e('Chada AIO storage migration is paused.', 'chada-aio'); ?></strong> <?php echo esc_html($chada_aio_error); ?> <?php esc_html_e('No existing data was removed. Resolve the storage conflict, then retry the migration.', 'chada-aio'); ?></p></div>
