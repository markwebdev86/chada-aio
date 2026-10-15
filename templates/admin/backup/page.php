<?php
/** Backup workflow page. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

/** @var array<string, mixed> $chada_aio_selector Backup selector data. */
assert(isset($chada_aio_selector));
?><section class="chada-panel chada-workflow-card" data-tour="backup"><div class="chada-step-heading"><span class="chada-step-number">1</span><div><p class="chada-eyebrow"><?php esc_html_e('Protect this site', 'chada-aio'); ?></p><h2><?php esc_html_e('Create a verified backup', 'chada-aio'); ?></h2><p class="description"><?php esc_html_e('Backup All captures the runtime scope needed to recover this site, then verifies the package before download or restore.', 'chada-aio'); ?></p></div></div><form class="chada-operation chada-fields" data-operation="backup">
    <?php CHADA_AIO_Template::output('admin/shared/operation-selector', ['chada_aio_selector' => $chada_aio_selector]); ?>
    <button class="button button-primary chada-operation-submit chada-loader-button" type="submit" data-default-label="<?php echo esc_attr__('Create backup', 'chada-aio'); ?>"><?php esc_html_e('Create backup', 'chada-aio'); ?></button>
</form><div class="chada-callout"><strong><?php esc_html_e('Backup All scope:', 'chada-aio'); ?></strong> <?php esc_html_e('Prefixed tables, uploads, must-use plugins, active plugin roots, active and parent themes, and present runtime drop-ins. Inactive/custom wp-content paths stay untouched.', 'chada-aio'); ?></div><p class="description"><?php esc_html_e('Retention settings control verified history. Protected recovery backups remain until an administrator explicitly removes them.', 'chada-aio'); ?></p></section>
