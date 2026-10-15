<?php
/** A single WordPress Plugins-page action link. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

/** @var string $chada_aio_url Action URL. */
/** @var string $chada_aio_label Action label. */
assert(isset($chada_aio_url));
assert(isset($chada_aio_label));
?><a href="<?php echo esc_url($chada_aio_url); ?>"><?php echo esc_html($chada_aio_label); ?></a>
