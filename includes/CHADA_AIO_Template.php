<?php
/**
 * Safe renderer for the plugin's extracted templates.
 *
 * Templates receive raw data, escape it at the output sink, and document every extracted variable with an @var
 * declaration and assert(). Values containing trusted, pre-escaped markup must use an explicit _html suffix.
 */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

final class CHADA_AIO_Template {
    /** Resolve a validated template name beneath the plugin templates directory. */
    public static function path(string $template): string {
        $template = trim($template, '/');
        if ($template === '' || preg_match('/[^a-zA-Z0-9_\/-]/', $template)) {
            throw new \InvalidArgumentException('Invalid Chada template name.');
        }
        $path = CHADA_AIO_PLUGIN_DIR . 'templates/' . $template . '.php';
        $path = apply_filters('chada_aio_template_path', $path, $template);
        if (!is_readable($path)) {
            throw new \RuntimeException('Chada template not found.');
        }
        return $path;
    }

    /** Render a template to a string. */
    public static function render(string $template, array $args = []): string {
        ob_start();
        self::output($template, $args);
        return (string) ob_get_clean();
    }

    /** Render a template directly to the current output stream. */
    public static function output(string $template, array $args = []): void {
        $path = self::path($template);
        extract($args, EXTR_SKIP);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Template output is intentionally included after validation.
        require $path;
    }
}
