<?php
/** Shared backup and operation record grid renderer. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

final class CHADA_AIO_Admin_Records {
    public static function render(string $operation): void {
        CHADA_AIO_Admin_Menu::grid($operation);
    }
}
