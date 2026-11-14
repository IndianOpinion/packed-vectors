<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

/**
 * The packed file fails a structural check. No results are returned from a
 * corrupt file.
 */
final class CorruptFileException extends PackedVectorsException implements UnavailableException
{
    public const REASON_MAGIC = 'magic';
    public const REASON_HEADER_CRC = 'header_crc';
    public const REASON_RESERVED = 'reserved';
    public const REASON_DIM = 'dim';
    public const REASON_SIZE = 'size';
    public const REASON_PAYLOAD_CRC = 'payload_crc';
    public const REASON_SHORT_READ = 'short_read';
    public const REASON_NON_FINITE = 'non_finite';

    public function __construct(
        public readonly string $path,
        public readonly string $reason,
        string $detail = ''
    ) {
        parent::__construct(
            sprintf('Packed vector file is corrupt (%s): %s', $reason, $path)
            . ($detail !== '' ? '. ' . $detail : '')
        );
    }
}
