<?php
/** Features screen renderer. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

final class CHADA_AIO_Admin_Features {
    public const MENU_SLUG = 'chada-aio-features';

    public static function render(): void {
        CHADA_AIO_Admin_Menu::features();
    }
}
