<?php
/** Migration screen renderer. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

final class CHADA_AIO_Admin_Migration {
    public const MENU_SLUG = 'chada-aio-migration';

    public static function render(): void {
        CHADA_AIO_Admin_Menu::migration();
    }
}
