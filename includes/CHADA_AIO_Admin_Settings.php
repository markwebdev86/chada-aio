<?php
/** Settings screen renderer. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

final class CHADA_AIO_Admin_Settings {
    public const MENU_SLUG = 'chada-aio-settings';

    public static function render(): void {
        CHADA_AIO_Admin_Menu::settings();
    }
}
