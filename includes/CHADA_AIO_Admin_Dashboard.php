<?php
/** Dashboard screen renderer. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

final class CHADA_AIO_Admin_Dashboard {
    public const MENU_SLUG = 'chada-aio-dashboard';

    public static function render(): void {
        CHADA_AIO_Admin_Menu::dashboard();
    }
}
