<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

use IndianOpinion\PackedVectors\Exception\UnsupportedPlatformException;

/**
 * Platform guard. The format has 64-bit header fields and ids above 2^31,
 * so a 32-bit PHP build cannot read or write it correctly.
 */
final class Environment
{
    private function __construct()
    {
    }

    /**
     * @throws UnsupportedPlatformException on a build where PHP_INT_SIZE is not 8
     */
    public static function assertSupported(int $intSize = PHP_INT_SIZE): void
    {
        if ($intSize !== 8) {
            throw new UnsupportedPlatformException(sprintf(
                'packed-vectors needs a 64-bit PHP build (PHP_INT_SIZE 8); this build has PHP_INT_SIZE %d',
                $intSize
            ));
        }
    }
}
