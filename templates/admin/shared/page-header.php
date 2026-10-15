<?php
/** Shared page heading and outer wrapper. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;
/** @var string $chada_aio_title */
/** @var string $chada_aio_badge */
/** @var string $chada_aio_description */
assert(isset($chada_aio_title));
assert(isset($chada_aio_badge));
assert(isset($chada_aio_description));
?><div class="wrap chada-aio"><div class="chada-page-heading"><div><h1><?php echo esc_html($chada_aio_title); ?></h1><p class="chada-subtitle"><?php echo esc_html(CHADA_AIO_PROJECT_NAME); ?> <span class="chada-badge"><?php echo esc_html($chada_aio_badge); ?></span></p></div><a class="button chada-header-help" href="<?php echo esc_url(admin_url('admin.php?page=' . CHADA_AIO_ADMIN_PREFIX . 'instructions')); ?>"><?php echo esc_html__('Open Instructions', 'chada-aio'); ?></a></div><p class="chada-page-description"><?php echo esc_html($chada_aio_description); ?></p>
