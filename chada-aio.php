<?php
/**
 * Plugin Name:       Chada AIO - Backup, Migrate and Optimize
 * Plugin URI:		  https://github.com/markwebdev86/chada-aio.git
 * Description:       Native WordPress backup, migration, image optimization, and database cleanup tools.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            markwebdev86
 * Author URI:		  https://github.com/markwebdev86
 * License:           GPL-2.0-or-later
 * License URI:		  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chada-aio
 * Domain Path:       /languages
 *
 * @package Chada_All_In_One
 */

defined('ABSPATH') || exit;

define('CHADA_AIO_PROJECT_NAME', 'Chada AIO - Backup, Migrate and Optimize');
define('CHADA_AIO_URL_SEGMENT', 'chada-aio');
define('CHADA_AIO_PLUGIN_SLUG', 'chada-aio');
define('CHADA_AIO_PHP_SLUG', 'chada-aio');
define('CHADA_AIO_PLUGIN_FOLDER', 'chada-aio');
define('CHADA_AIO_TEXT_DOMAIN', 'chada-aio');
define('CHADA_AIO_PREFIX', 'chada_aio_');
define('CHADA_AIO_ADMIN_PREFIX', 'chada-aio-');
define('CHADA_AIO_REST_NAMESPACE', 'chada-aio/v1');
define('CHADA_AIO_VERSION', '1.0.0');
define('CHADA_AIO_DB_VERSION', '0.0.4');
define('CHADA_AIO_PLUGIN_FILE', __FILE__);
define('CHADA_AIO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHADA_AIO_PLUGIN_URL', plugin_dir_url(__FILE__));

$chada_aio_composer_autoloader = CHADA_AIO_PLUGIN_DIR . 'vendor/autoload.php';
if (is_readable($chada_aio_composer_autoloader)) {
    require_once $chada_aio_composer_autoloader;
} else {
    spl_autoload_register(static function (string $chada_aio_class): void {
        $chada_aio_prefix = 'CHADA_AIO\\';
        if (strpos($chada_aio_class, $chada_aio_prefix) !== 0) {
            return;
        }
        $chada_aio_relative = substr($chada_aio_class, strlen($chada_aio_prefix));
        $chada_aio_file = CHADA_AIO_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $chada_aio_relative) . '.php';
        if (is_readable($chada_aio_file)) {
            require_once $chada_aio_file;
        }
    });
}
unset($chada_aio_composer_autoloader);
register_activation_hook(__FILE__, ['CHADA_AIO\\CHADA_AIO_Installer', 'activate']);
register_deactivation_hook(__FILE__, ['CHADA_AIO\\CHADA_AIO_Installer', 'deactivate']);
add_action('plugins_loaded', static function (): void {
    CHADA_AIO\CHADA_AIO_Plugin::instance()->run();
});
