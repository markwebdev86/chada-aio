<?php
/** Plugin and must-use plugin selection fields for Pro component operations. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

/** @var array<string, list<array<string, mixed>>> $chada_aio_inventory Plugin inventory. */
assert(isset($chada_aio_inventory));
$chada_aio_normal_plugins = $chada_aio_inventory['normal'] ?? [];
$chada_aio_must_use_plugins = $chada_aio_inventory['must_use'] ?? [];
?><fieldset class="chada-plugin-selection" aria-required="false" hidden>
    <legend><span><?php esc_html_e('Plugins to include', 'chada-aio'); ?><span class="chada-required" aria-hidden="true">*</span><span class="screen-reader-text"> <?php esc_html_e('Required field', 'chada-aio'); ?></span></span></legend>
    <p class="description"><?php esc_html_e('Active normal plugins are selected by default. Select at least one. Plugin folders include their bundled dependencies.', 'chada-aio'); ?></p>
    <p class="chada-selection-count" data-plugin-count><?php esc_html_e('0 plugins selected', 'chada-aio'); ?></p>
    <div class="chada-plugin-list">
        <?php foreach ($chada_aio_normal_plugins as $chada_aio_plugin): ?>
            <label class="chada-check"><input type="checkbox" name="plugins[]" value="<?php echo esc_attr((string) ($chada_aio_plugin['path'] ?? '')); ?>" <?php checked(!empty($chada_aio_plugin['active']), true); ?>> <?php echo esc_html((string) ($chada_aio_plugin['label'] ?? '')); ?> <code><?php echo esc_html((string) ($chada_aio_plugin['path'] ?? '')); ?></code></label>
        <?php endforeach; ?>
        <?php if (!$chada_aio_normal_plugins): ?><p class="description"><?php esc_html_e('No normal plugins were found.', 'chada-aio'); ?></p><?php endif; ?>
    </div>
    <?php if ($chada_aio_must_use_plugins): ?>
        <details class="chada-mu-plugins"><summary><?php esc_html_e('Must-use plugins (optional)', 'chada-aio'); ?></summary><div class="chada-plugin-list">
            <?php foreach ($chada_aio_must_use_plugins as $chada_aio_plugin): ?>
                <label class="chada-check"><input type="checkbox" name="mu_plugins[]" value="<?php echo esc_attr((string) ($chada_aio_plugin['path'] ?? '')); ?>"> <?php echo esc_html((string) ($chada_aio_plugin['label'] ?? '')); ?> <code><?php echo esc_html((string) ($chada_aio_plugin['path'] ?? '')); ?></code></label>
            <?php endforeach; ?>
        </div></details>
    <?php endif; ?>
</fieldset>
