<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Internal;

/**
 * Runs a filesystem call and captures the PHP warning text it raises,
 * instead of suppressing it with the error-control operator.
 *
 * @internal
 */
final class Errors
{
    private static string $last = '';

    private function __construct()
    {
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    public static function capture(callable $call): mixed
    {
        self::$last = '';
        set_error_handler(static function (int $severity, string $message): bool {
            self::$last = $message;

            return true;
        });
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /** Warning text from the last captured call, or '' if it raised none. */
    public static function last(): string
    {
        return self::$last;
    }
}
