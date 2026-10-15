<?php
/** Instructions and walkthrough screen renderer. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

final class CHADA_AIO_Admin_Instructions {
    public const MENU_SLUG = 'chada-aio-instructions';

    public static function render(): void {
        CHADA_AIO_Admin_Menu::instructions();
    }
}
