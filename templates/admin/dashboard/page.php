<?php
/** Dashboard cards, readiness information, and next-step guidance. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

/** @var list<array{label: string, value: mixed}> $chada_aio_cards Dashboard summary cards. */
/** @var list<array{label: string, url: string, primary: bool}> $chada_aio_actions Dashboard actions. */
/** @var list<array{key: string, tab: string, label: string, message: string, url: string}> $chada_aio_setting_errors Settings readiness failures. */
/** @var array<string, mixed> $chada_aio_storage Storage status. */
/** @var bool $chada_aio_zip_available Whether ZipArchive is available. */
/** @var bool $chada_aio_image_editor_available Whether the image editor is available. */
/** @var array<string, mixed> $chada_aio_runtime Request-scoped PHP execution status. */
assert(isset($chada_aio_cards));
assert(isset($chada_aio_actions));
assert(isset($chada_aio_setting_errors));
assert(isset($chada_aio_storage));
assert(isset($chada_aio_zip_available));
assert(isset($chada_aio_image_editor_available));
assert(isset($chada_aio_runtime));
?><?php if ($chada_aio_setting_errors): ?><section class="notice notice-error chada-settings-alert" role="alert" aria-labelledby="chada-settings-alert-title"><p><strong id="chada-settings-alert-title"><?php esc_html_e('Settings need attention.', 'chada-aio'); ?></strong> <?php esc_html_e('Resolve the following values before relying on Chada AIO operations.', 'chada-aio'); ?></p><ul><?php foreach ($chada_aio_setting_errors as $chada_aio_setting_error): ?><li><a href="<?php echo esc_url($chada_aio_setting_error['url']); ?>"><?php echo esc_html($chada_aio_setting_error['label']); ?></a>: <?php echo esc_html($chada_aio_setting_error['message']); ?></li><?php endforeach; ?></ul></section><?php endif; ?><div class="chada-cards" data-tour="dashboard">
    <?php foreach ($chada_aio_cards as $chada_aio_card): ?><section class="chada-card"><h2><?php echo esc_html($chada_aio_card['label']); ?></h2><p><?php echo esc_html((string) $chada_aio_card['value']); ?></p></section><?php endforeach; ?>
</div>
<section class="chada-panel chada-next-step"><p class="chada-eyebrow"><?php esc_html_e('Recommended first step', 'chada-aio'); ?></p><h2><?php esc_html_e('Prepare your first verified backup', 'chada-aio'); ?></h2><p><?php esc_html_e('Check protected storage, create Backup All, download a separate copy, and test recovery on an isolated site before relying on it.', 'chada-aio'); ?></p><p class="chada-actions"><?php foreach ($chada_aio_actions as $chada_aio_action): ?><a class="button<?php echo $chada_aio_action['primary'] ? ' button-primary' : ''; ?>" href="<?php echo esc_url($chada_aio_action['url']); ?>"><?php echo esc_html($chada_aio_action['label']); ?></a><?php endforeach; ?></p></section>
<section class="chada-panel"><h2><?php esc_html_e('Storage and system readiness', 'chada-aio'); ?></h2>
    <?php if (!empty($chada_aio_storage['ready'])): ?><p><?php esc_html_e('Private path: ', 'chada-aio'); ?><code><?php echo esc_html((string) ($chada_aio_storage['path'] ?? '')); ?></code></p><p><?php esc_html_e('Free space: ', 'chada-aio'); ?><?php echo esc_html(size_format((int) ($chada_aio_storage['free'] ?? 0))); ?></p>
    <?php else: ?><p class="chada-error"><?php echo esc_html((string) ($chada_aio_storage['error'] ?? '')); ?></p><?php endif; ?>
    <p><?php esc_html_e('PHP ZIP: ', 'chada-aio'); ?><?php echo $chada_aio_zip_available ? esc_html__('Available', 'chada-aio') : esc_html__('Missing — archives are unavailable', 'chada-aio'); ?>. <?php esc_html_e('Image editor: ', 'chada-aio'); ?><?php echo $chada_aio_image_editor_available ? esc_html__('Available', 'chada-aio') : esc_html__('Unavailable', 'chada-aio'); ?>.</p>
    <p><?php esc_html_e('PHP execution time: ', 'chada-aio'); ?><?php echo esc_html((string) ($chada_aio_runtime['message'] ?? __('Runtime status is unavailable.', 'chada-aio'))); ?></p>
    <?php if (($chada_aio_runtime['state'] ?? '') === 'unavailable'): ?><p class="chada-error"><?php esc_html_e('Ask the hosting administrator to allow max_execution_time of at least 600 seconds. Web-server and proxy timeouts are configured separately.', 'chada-aio'); ?></p><?php endif; ?>
    <p><?php esc_html_e('Backups are live batched captures. For cross-table consistency, run during a quiet period.', 'chada-aio'); ?></p>
</section>
