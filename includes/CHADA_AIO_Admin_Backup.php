<?php
/** Backup screen renderer. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

final class CHADA_AIO_Admin_Backup {
    public const MENU_SLUG = 'chada-aio-backup';

    public static function render(): void {
        CHADA_AIO_Admin_Menu::backup();
    }
}
