<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

use IndianOpinion\PackedVectors\Exception\InvalidArgumentException;

/**
 * Format constants and pure size helpers. No I/O.
 *
 * Layout (all integers unsigned little-endian, floats IEEE 754 binary32
 * little-endian):
 *
 *     header  40 bytes: magic "PVEC", u16 version, u16 flags, u32 dim,
 *             u64 count, u64 built_at, u32 payload_crc, u32 header_crc,
 *             u32 reserved
 *     record  8 + 4 * dim bytes: u32 id, u32 ref, dim x float32
 */
final class Format
{
    public const MAGIC = 'PVEC';
    public const VERSION = 1;
    public const HEADER_SIZE = 40;
    public const FLAG_NORMALISED = 1;
    public const KNOWN_FLAGS = self::FLAG_NORMALISED;
    public const MAX_DIM = 65535;
    public const MAX_ID = 4294967295;
    public const RECORD_PREFIX_SIZE = 8;

    /** Largest finite float32 value. */
    public const FLOAT32_MAX = 3.4028234663852886e38;

    private function __construct()
    {
    }

    /**
     * Bytes per record for a dimension.
     */
    public static function recordSize(int $dim): int
    {
        self::assertDim($dim);

        return self::RECORD_PREFIX_SIZE + 4 * $dim;
    }

    /**
     * Exact size of a valid file with $count records of $dim values.
     */
    public static function expectedFileSize(int $dim, int $count): int
    {
        if ($count < 0) {
            throw new InvalidArgumentException(sprintf('count must be 0 or more, got %d', $count));
        }
        $recordSize = self::recordSize($dim);
        if ($count > intdiv(PHP_INT_MAX - self::HEADER_SIZE, $recordSize)) {
            throw new InvalidArgumentException(sprintf('count %d is too large for dim %d', $count, $dim));
        }

        return self::HEADER_SIZE + $count * $recordSize;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertDim(int $dim): void
    {
        if ($dim < 1 || $dim > self::MAX_DIM) {
            throw new InvalidArgumentException(sprintf('dim must be 1 to %d, got %d', self::MAX_DIM, $dim));
        }
    }
}
