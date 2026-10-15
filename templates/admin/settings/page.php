<?php
/** Settings tabs and the Setup Wizard shell. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

/** @var string $chada_aio_tab Active settings tab. */
/** @var array<string, string> $chada_aio_labels Settings tab labels. */
/** @var list<array<string, mixed>> $chada_aio_fields Active-tab field definitions. */
/** @var list<array<string, mixed>> $chada_aio_wizard_steps Wizard step definitions. */
assert(isset($chada_aio_tab));
assert(isset($chada_aio_labels));
assert(isset($chada_aio_fields));
assert(isset($chada_aio_wizard_steps));
?><section class="chada-panel chada-settings-intro"><div><p class="chada-eyebrow"><?php esc_html_e('Configuration', 'chada-aio'); ?></p><h2><?php esc_html_e('Set up Chada AIO for this site', 'chada-aio'); ?></h2><p><?php esc_html_e('Use the wizard for essential first setup, or save individual tabs when you already know which setting needs to change.', 'chada-aio'); ?></p></div><button type="button" class="button button-primary" id="chada-wizard-open" data-tour="wizard"><?php esc_html_e('Open Setup Wizard', 'chada-aio'); ?></button></section>
<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Settings tabs', 'chada-aio'); ?>" data-tour="settings"><?php foreach ($chada_aio_labels as $chada_aio_key => $chada_aio_label): ?><a class="nav-tab<?php echo $chada_aio_key === $chada_aio_tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . CHADA_AIO_ADMIN_PREFIX . 'settings&tab=' . $chada_aio_key)); ?>"><?php echo esc_html($chada_aio_label); ?></a><?php endforeach; ?></nav>
<section class="chada-panel"><form class="chada-settings" data-tab="<?php echo esc_attr($chada_aio_tab); ?>"><p class="description"><?php esc_html_e('Changes are saved per tab. Saving one section does not overwrite values in the others.', 'chada-aio'); ?></p><?php CHADA_AIO_Template::output('admin/shared/settings-fields', ['chada_aio_fields' => $chada_aio_fields]); ?><p class="chada-actions"><button type="submit" class="button button-primary chada-settings-submit"><?php esc_html_e('Save settings', 'chada-aio'); ?></button><span class="chada-save-status" role="status" aria-live="polite"></span></p></form></section>
<?php do_action('chada_aio_settings_panels'); ?>
<dialog id="chada-wizard" class="chada-dialog chada-wizard" aria-labelledby="chada-wizard-title"><h2 id="chada-wizard-title"><?php esc_html_e('Setup Wizard', 'chada-aio'); ?></h2><ol class="chada-wizard-steps" aria-label="<?php esc_attr_e('Setup steps', 'chada-aio'); ?>"><li data-wizard-label="storage"><?php esc_html_e('Storage', 'chada-aio'); ?></li><li data-wizard-label="general"><?php esc_html_e('General', 'chada-aio'); ?></li><li data-wizard-label="optimization"><?php esc_html_e('Optimization', 'chada-aio'); ?></li><li data-wizard-label="review"><?php esc_html_e('Review', 'chada-aio'); ?></li></ol><p id="chada-wizard-progress" role="status" aria-live="polite"></p>
    <?php foreach ($chada_aio_wizard_steps as $chada_aio_step): ?><form class="chada-wizard-step" data-step="<?php echo esc_attr((string) ($chada_aio_step['step'] ?? 0)); ?>" data-tab="<?php echo esc_attr((string) ($chada_aio_step['tab'] ?? '')); ?>" hidden><?php CHADA_AIO_Template::output('admin/shared/settings-fields', ['chada_aio_fields' => $chada_aio_step['fields'] ?? []]); ?></form><?php endforeach; ?>
    <div class="chada-wizard-step" data-step="3" hidden><h3><?php esc_html_e('Review', 'chada-aio'); ?></h3><p><?php esc_html_e('Your saved settings are ready. Creating a backup and testing recovery are separate actions.', 'chada-aio'); ?></p><p id="chada-wizard-readiness"></p></div><p class="chada-actions"><button type="button" class="button" id="chada-wizard-prev"><?php esc_html_e('Previous', 'chada-aio'); ?></button><button type="button" class="button button-primary" id="chada-wizard-next"><?php esc_html_e('Save and next', 'chada-aio'); ?></button><button type="button" class="button" id="chada-wizard-close"><?php esc_html_e('Close', 'chada-aio'); ?></button></p>
</dialog>
