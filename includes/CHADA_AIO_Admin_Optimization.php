<?php
/** Optimization screen renderer. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

final class CHADA_AIO_Admin_Optimization {
    public const MENU_SLUG = 'chada-aio-optimization';

    public static function render(): void {
        CHADA_AIO_Admin_Menu::optimization();
    }
}
