<?php
/** Structured operational instructions and interactive walkthrough controls. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

/** @var array<string, string> $chada_aio_tabs Instruction tabs. */
/** @var array<string, array<string, mixed>> $chada_aio_contents Structured instruction content. */
assert(isset($chada_aio_tabs));
assert(isset($chada_aio_contents));
?><div role="tablist" class="chada-tabs" aria-label="<?php esc_attr_e('Instructions sections', 'chada-aio'); ?>" data-tour="instructions">
    <?php foreach ($chada_aio_tabs as $chada_aio_key => $chada_aio_label): ?><a href="#<?php echo esc_attr($chada_aio_key); ?>" role="tab" id="tab-<?php echo esc_attr($chada_aio_key); ?>" aria-controls="<?php echo esc_attr($chada_aio_key); ?>" aria-selected="<?php echo $chada_aio_key === 'getting-started' ? 'true' : 'false'; ?>"><?php echo esc_html($chada_aio_label); ?></a><?php endforeach; ?>
</div>
<?php foreach ($chada_aio_tabs as $chada_aio_key => $chada_aio_label): $chada_aio_content = $chada_aio_contents[$chada_aio_key] ?? []; $chada_aio_steps = is_array($chada_aio_content['steps'] ?? null) ? $chada_aio_content['steps'] : []; $chada_aio_paragraphs = is_array($chada_aio_content['paragraphs'] ?? null) ? $chada_aio_content['paragraphs'] : []; $chada_aio_definitions = is_array($chada_aio_content['definitions'] ?? null) ? $chada_aio_content['definitions'] : []; ?>
    <section class="chada-panel chada-tabpanel" role="tabpanel" id="<?php echo esc_attr($chada_aio_key); ?>" aria-labelledby="tab-<?php echo esc_attr($chada_aio_key); ?>">
        <?php if (isset($chada_aio_content['intro']) && is_string($chada_aio_content['intro'])): ?><p><?php echo esc_html($chada_aio_content['intro']); ?></p><?php endif; ?>
        <?php if ($chada_aio_steps): ?><ol><?php foreach ($chada_aio_steps as $chada_aio_step): ?><li><?php if (isset($chada_aio_step['link'], $chada_aio_step['url'])): ?><?php echo esc_html((string) ($chada_aio_step['before'] ?? '')); ?><a href="<?php echo esc_url((string) $chada_aio_step['url']); ?>"><?php echo esc_html((string) $chada_aio_step['link']); ?></a><?php echo esc_html((string) ($chada_aio_step['after'] ?? '')); ?><?php else: ?><?php echo esc_html((string) ($chada_aio_step['text'] ?? '')); ?><?php endif; ?></li><?php endforeach; ?></ol><?php endif; ?>
        <?php foreach ($chada_aio_paragraphs as $chada_aio_paragraph): ?><p><?php echo esc_html((string) $chada_aio_paragraph); ?></p><?php endforeach; ?>
        <?php if ($chada_aio_definitions): ?><dl><?php foreach ($chada_aio_definitions as $chada_aio_definition): ?><dt><?php echo esc_html((string) ($chada_aio_definition['term'] ?? '')); ?></dt><dd><?php echo esc_html((string) ($chada_aio_definition['description'] ?? '')); ?></dd><?php endforeach; ?></dl><?php endif; ?>
        <?php if (!empty($chada_aio_content['walkthrough'])): ?><p class="chada-actions"><button class="button button-primary" type="button" id="chada-tour-start"><?php esc_html_e('Start walkthrough', 'chada-aio'); ?></button><button class="button" type="button" id="chada-tour-resume"><?php esc_html_e('Resume walkthrough', 'chada-aio'); ?></button></p><?php endif; ?>
    </section>
<?php endforeach; ?>
