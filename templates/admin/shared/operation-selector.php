<?php
/** Operation scope selector shared by Backup and Migration. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

/** @var array<string, mixed> $chada_aio_selector Selector data. */
assert(isset($chada_aio_selector));
$chada_aio_types = $chada_aio_selector['types'] ?? [];
?><label class="chada-primary-field">
    <span><?php esc_html_e('What do you want to process?', 'chada-aio'); ?><span class="chada-required" aria-hidden="true">*</span><span class="screen-reader-text"> <?php esc_html_e('Required field', 'chada-aio'); ?></span></span>
    <select name="type" data-tour="type" required>
        <?php foreach ($chada_aio_types as $chada_aio_type): ?>
            <option value="<?php echo esc_attr((string) ($chada_aio_type['value'] ?? '')); ?>" <?php selected($chada_aio_selector['default_type'] ?? '', $chada_aio_type['value'] ?? '', true); ?>><?php echo esc_html((string) ($chada_aio_type['label'] ?? '')); ?></option>
        <?php endforeach; ?>
    </select>
</label>
<details class="chada-advanced-options" hidden>
    <summary><?php esc_html_e('Advanced selection', 'chada-aio'); ?></summary>
    <div class="chada-fields">
        <label><span><?php esc_html_e('Date range', 'chada-aio'); ?><span class="chada-required" aria-hidden="true">*</span><span class="screen-reader-text"> <?php esc_html_e('Required field', 'chada-aio'); ?></span></span><select name="period"><option value="all"><?php esc_html_e('All dates', 'chada-aio'); ?></option><option value="quarter"><?php esc_html_e('Calendar quarter', 'chada-aio'); ?></option><option value="year"><?php esc_html_e('Calendar year', 'chada-aio'); ?></option></select></label>
        <label><span><?php esc_html_e('Year', 'chada-aio'); ?><span class="chada-required" aria-hidden="true">*</span><span class="screen-reader-text"> <?php esc_html_e('Required field', 'chada-aio'); ?></span></span><input name="year" type="number" min="1970" max="2200" value="<?php echo esc_attr((string) ($chada_aio_selector['year'] ?? '')); ?>"></label>
        <label><span><?php esc_html_e('Quarter', 'chada-aio'); ?><span class="chada-required" aria-hidden="true">*</span><span class="screen-reader-text"> <?php esc_html_e('Required field', 'chada-aio'); ?></span></span><select name="quarter"><option value="1"><?php esc_html_e('Q1 · Jan–Mar', 'chada-aio'); ?></option><option value="2"><?php esc_html_e('Q2 · Apr–Jun', 'chada-aio'); ?></option><option value="3"><?php esc_html_e('Q3 · Jul–Sep', 'chada-aio'); ?></option><option value="4"><?php esc_html_e('Q4 · Oct–Dec', 'chada-aio'); ?></option></select></label>
        <label><span><?php esc_html_e('Theme', 'chada-aio'); ?><span class="chada-required" aria-hidden="true">*</span><span class="screen-reader-text"> <?php esc_html_e('Required field', 'chada-aio'); ?></span></span><select name="theme">
            <?php foreach ($chada_aio_selector['themes'] ?? [] as $chada_aio_theme): ?><option value="<?php echo esc_attr((string) ($chada_aio_theme['value'] ?? '')); ?>" <?php selected(!empty($chada_aio_theme['selected']), true); ?>><?php echo esc_html((string) ($chada_aio_theme['label'] ?? '')); ?></option><?php endforeach; ?>
        </select></label>
    </div>
    <?php if (!empty($chada_aio_selector['show_plugins'])): ?>
        <?php CHADA_AIO_Template::output('admin/shared/plugin-selection', ['chada_aio_inventory' => $chada_aio_selector['inventory'] ?? []]); ?>
    <?php endif; ?>
</details>
