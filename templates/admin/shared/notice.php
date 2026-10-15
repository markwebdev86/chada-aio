<?php
/** Admin notice with a consistent escaped message surface. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

/** @var string $chada_aio_type Notice class suffix. */
/** @var string $chada_aio_message Raw notice message. */
assert(isset($chada_aio_type));
assert(isset($chada_aio_message));
$chada_aio_notice_type = in_array($chada_aio_type, ['error', 'warning', 'info', 'success'], true) ? $chada_aio_type : 'info';
?><div class="notice notice-<?php echo esc_attr($chada_aio_notice_type); ?>"><p><?php echo esc_html($chada_aio_message); ?></p></div>
