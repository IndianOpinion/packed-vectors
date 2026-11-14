<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

/**
 * The query (or the requested build) has a different dimension from the
 * file. Usually the embedding model changed: fall back and rebuild.
 */
final class DimensionMismatchException extends PackedVectorsException implements UnavailableException
{
    public function __construct(
        public readonly int $expected,
        public readonly int $given,
        public readonly string $path = ''
    ) {
        parent::__construct(sprintf(
            'Dimension mismatch: expected %d values, got %d%s',
            $expected,
            $given,
            $path !== '' ? ' (' . $path . ')' : ''
        ));
    }
}
