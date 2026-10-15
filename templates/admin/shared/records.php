<?php
/** AJAX-backed operation history grid shell. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

/** @var string $chada_aio_operation Operation filter passed to the REST client. */
/** @var string $chada_aio_title History heading. */
/** @var string $chada_aio_description History description. */
/** @var list<array{value: string, label: string}> $chada_aio_types Available type filters. */
assert(isset($chada_aio_operation));
assert(isset($chada_aio_title));
assert(isset($chada_aio_description));
assert(isset($chada_aio_types));
?><section class="chada-panel chada-grid" data-operation="<?php echo esc_attr($chada_aio_operation); ?>" data-tour="records">
    <div class="chada-section-heading"><div><p class="chada-eyebrow"><?php esc_html_e('History', 'chada-aio'); ?></p><h2><?php echo esc_html($chada_aio_title); ?></h2></div><button type="button" class="button chada-refresh chada-loader-button"><?php esc_html_e('Refresh history', 'chada-aio'); ?></button></div>
    <p class="description"><?php echo esc_html($chada_aio_description); ?></p>
    <div class="chada-fields chada-record-filters"><label><?php esc_html_e('Filter by type', 'chada-aio'); ?><select class="chada-filter"><option value=""><?php esc_html_e('All available types', 'chada-aio'); ?></option><?php foreach ($chada_aio_types as $chada_aio_type): ?><option value="<?php echo esc_attr($chada_aio_type['value']); ?>"><?php echo esc_html($chada_aio_type['label']); ?></option><?php endforeach; ?></select></label></div>
    <div class="chada-table-wrap"><table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e('Date', 'chada-aio'); ?></th><th scope="col"><?php esc_html_e('Operation', 'chada-aio'); ?></th><th scope="col"><?php esc_html_e('Type', 'chada-aio'); ?></th><th scope="col"><?php esc_html_e('Status', 'chada-aio'); ?></th><th scope="col"><?php esc_html_e('Actions', 'chada-aio'); ?></th></tr></thead><tbody><tr><td colspan="5"><?php esc_html_e('Loading history…', 'chada-aio'); ?></td></tr></tbody></table></div>
    <p class="chada-actions"><button type="button" class="button chada-prev chada-loader-button"><?php esc_html_e('Previous', 'chada-aio'); ?></button><span class="chada-page" aria-live="polite"></span><button type="button" class="button chada-next chada-loader-button"><?php esc_html_e('Next', 'chada-aio'); ?></button></p>
</section>
