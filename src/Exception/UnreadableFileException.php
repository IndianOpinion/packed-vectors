<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

/**
 * The packed file exists but cannot be opened or read (permissions, not a
 * regular file, I/O error).
 */
final class UnreadableFileException extends PackedVectorsException implements UnavailableException
{
    public function __construct(public readonly string $path, string $detail = '')
    {
        parent::__construct(
            sprintf('Packed vector file is not readable: %s', $path) . ($detail !== '' ? ' (' . $detail . ')' : '')
        );
    }
}
