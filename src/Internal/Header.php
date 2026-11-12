<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Internal;

use IndianOpinion\PackedVectors\Exception\CorruptFileException;
use IndianOpinion\PackedVectors\Exception\UnsupportedVersionException;
use IndianOpinion\PackedVectors\FileInfo;
use IndianOpinion\PackedVectors\Format;

/**
 * Encodes and checks the 40-byte header.
 *
 * @internal
 */
final class Header
{
    private const PACK = 'a4vvVPPV';
    private const UNPACK = 'a4magic/vversion/vflags/Vdim/Pcount/PbuiltAt/VpayloadCrc/VheaderCrc/Vreserved';

    private function __construct()
    {
    }

    /**
     * The full 40-byte header, with the header checksum filled in.
     */
    public static function encode(int $flags, int $dim, int $count, int $builtAt, int $payloadCrc): string
    {
        $first = pack(self::PACK, Format::MAGIC, Format::VERSION, $flags, $dim, $count, $builtAt, $payloadCrc);

        return $first . pack('VV', crc32($first), 0);
    }

    /**
     * Checks a header against the file size taken from the open handle.
     *
     * Order: size of the header itself, magic, version (a newer layout is
     * never judged by version 1 rules), header checksum, reserved bits,
     * dim, then the exact file size.
     *
     * @throws CorruptFileException
     * @throws UnsupportedVersionException
     */
    public static function decode(string $bytes, string $path, int $fileSize): FileInfo
    {
        if (strlen($bytes) < Format::HEADER_SIZE || $fileSize < Format::HEADER_SIZE) {
            throw new CorruptFileException(
                $path,
                CorruptFileException::REASON_SIZE,
                sprintf('file is %d bytes, shorter than the %d-byte header', $fileSize, Format::HEADER_SIZE)
            );
        }
        if (substr($bytes, 0, 4) !== Format::MAGIC) {
            throw new CorruptFileException($path, CorruptFileException::REASON_MAGIC, 'not a packed vector file');
        }
        /** @var array{magic: string, version: int, flags: int, dim: int, count: int, builtAt: int, payloadCrc: int, headerCrc: int, reserved: int} $h */
        $h = unpack(self::UNPACK, $bytes);
        if ($h['version'] !== Format::VERSION) {
            throw new UnsupportedVersionException($path, $h['version'], Format::VERSION);
        }
        $actualCrc = crc32(substr($bytes, 0, 32));
        if ($actualCrc !== $h['headerCrc']) {
            throw new CorruptFileException(
                $path,
                CorruptFileException::REASON_HEADER_CRC,
                sprintf('header checksum is %08x, header says %08x', $actualCrc, $h['headerCrc'])
            );
        }
        if ($h['reserved'] !== 0 || ($h['flags'] & ~Format::KNOWN_FLAGS) !== 0) {
            throw new CorruptFileException(
                $path,
                CorruptFileException::REASON_RESERVED,
                sprintf('reserved field %08x, flags %04x', $h['reserved'], $h['flags'])
            );
        }
        $dim = $h['dim'];
        if ($dim < 1 || $dim > Format::MAX_DIM) {
            throw new CorruptFileException(
                $path,
                CorruptFileException::REASON_DIM,
                sprintf('dim %d is outside 1 to %d', $dim, Format::MAX_DIM)
            );
        }
        $recordSize = Format::RECORD_PREFIX_SIZE + 4 * $dim;
        $count = $h['count'];
        // count is bounded before multiplying, so the arithmetic cannot overflow.
        $maxCount = intdiv($fileSize - Format::HEADER_SIZE, $recordSize);
        if ($count < 0 || $count > $maxCount || Format::HEADER_SIZE + $count * $recordSize !== $fileSize) {
            throw new CorruptFileException(
                $path,
                CorruptFileException::REASON_SIZE,
                sprintf(
                    'header says %s records of %d bytes (%s bytes in all), file is %d bytes',
                    self::unsigned($count),
                    $recordSize,
                    $count >= 0 && $count <= $maxCount
                        ? (string) (Format::HEADER_SIZE + $count * $recordSize)
                        : 'more than the file holds',
                    $fileSize
                )
            );
        }

        return new FileInfo(
            $path,
            $h['version'],
            $h['flags'],
            $dim,
            $count,
            $h['builtAt'],
            $h['payloadCrc'],
            $h['headerCrc'],
            $recordSize,
            $fileSize
        );
    }

    private static function unsigned(int $n): string
    {
        return $n >= 0 ? (string) $n : sprintf('%u', $n);
    }
}
