<?php
/** Safe request-scoped PHP runtime headroom for Chada AIO operations. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Request routing reads only the plugin's own page and REST URI; no mutation input is processed.

final class CHADA_AIO_Runtime {
    public const DESIRED_EXECUTION_TIME = 600;
    private const DIRECTIVE = 'max_execution_time';
    private static $last_apply;

    /** Raise the request timer when the host permits it, without lowering an existing limit. */
    public static function apply(): array {
        if (self::$last_apply !== null) { return self::$last_apply; }
        $configured = self::read_limit(); $ini_applied = false; $timer_applied = false;
        if ($configured !== null && $configured > 0 && $configured < self::DESIRED_EXECUTION_TIME) {
            if (function_exists('ini_set')) {
                try { $ini_applied = @ini_set(self::DIRECTIVE, (string) self::DESIRED_EXECUTION_TIME) !== false; } catch (\Throwable $error) { $ini_applied = false; }
            }
            if (function_exists('set_time_limit')) {
                try { $timer_applied = @set_time_limit(self::DESIRED_EXECUTION_TIME) !== false; } catch (\Throwable $error) { $timer_applied = false; }
            }
        }
        $effective = self::read_limit(); $state = self::state($configured, $effective, $ini_applied || $timer_applied);
        self::$last_apply = ['directive' => self::DIRECTIVE, 'desired' => self::DESIRED_EXECUTION_TIME, 'configured' => $configured,
            'effective' => $effective, 'state' => $state, 'ini_applied' => $ini_applied, 'timer_applied' => $timer_applied,
            'server_timeout' => 'host-managed'];
        return self::$last_apply;
    }

    /** Read the current request state without changing PHP configuration. */
    public static function status(): array {
        if (self::$last_apply !== null) { return self::$last_apply; }
        $current = self::read_limit();
        return ['directive' => self::DIRECTIVE, 'desired' => self::DESIRED_EXECUTION_TIME, 'configured' => $current,
            'effective' => $current, 'state' => self::state($current, $current, false), 'ini_applied' => false,
            'timer_applied' => false, 'server_timeout' => 'host-managed'];
    }

    /** Return an escaped-at-render-time administrator-facing description of the runtime state. */
    public static function message(array $status): string {
        $state = (string) ($status['state'] ?? 'unavailable'); $seconds = (int) ($status['effective'] ?? 0);
        if ($state === 'no_limit') { return __('No PHP time limit is set for this request.', 'chada-aio'); }
        if ($state === 'already_sufficient') {
            // translators: %d is the observed PHP execution time in seconds.
            return sprintf(__('At least %d seconds.', 'chada-aio'), $seconds);
        }
        if ($state === 'raised') {
            // translators: %d is the desired PHP execution time in seconds.
            return sprintf(__('The plugin requested %d seconds for this request.', 'chada-aio'), (int) ($status['desired'] ?? self::DESIRED_EXECUTION_TIME));
        }
        // translators: %d is the desired PHP execution time in seconds.
        return sprintf(__('The plugin could not raise the limit to %d seconds.', 'chada-aio'), (int) ($status['desired'] ?? self::DESIRED_EXECUTION_TIME));
    }

    /** Normalize a PHP integer directive while preserving an unreadable value as unavailable. */
    private static function read_limit(): ?int {
        $value = trim((string) ini_get(self::DIRECTIVE));
        if ($value === '' || !preg_match('/^-?\d+$/D', $value)) { return null; }
        return max(0, (int) $value);
    }

    /** Classify the effective request state for diagnostics and administrator guidance. */
    private static function state(?int $configured, ?int $effective, bool $applied): string {
        if ($configured === null || $effective === null) { return 'unavailable'; }
        if ($configured === 0 || $effective === 0) { return 'no_limit'; }
        if ($configured >= self::DESIRED_EXECUTION_TIME) { return 'already_sufficient'; }
        if ($effective >= self::DESIRED_EXECUTION_TIME || $applied) { return 'raised'; }
        return 'unavailable';
    }
}
