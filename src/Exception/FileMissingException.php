<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

/**
 * The packed file does not exist yet. This is "not built", not "broken".
 */
final class FileMissingException extends PackedVectorsException implements UnavailableException
{
    public function __construct(public readonly string $path, string $detail = '')
    {
        parent::__construct(
            sprintf('Packed vector file not found: %s', $path) . ($detail !== '' ? ' (' . $detail . ')' : '')
        );
    }
}
