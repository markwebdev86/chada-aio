<?php
/** First-activation Setup Wizard reminder. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

/** @var string $chada_aio_url Setup Wizard URL. */
assert(isset($chada_aio_url));
?><div class="notice notice-info"><p><?php esc_html_e('Chada AIO is ready. Run the Setup Wizard to configure protected storage and operating defaults.', 'chada-aio'); ?> <a href="<?php echo esc_url($chada_aio_url); ?>"><?php esc_html_e('Open Setup Wizard', 'chada-aio'); ?></a></p></div>
