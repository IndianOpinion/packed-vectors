<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

/**
 * The file declares a format version this release does not read. The reader
 * never guesses a layout.
 */
final class UnsupportedVersionException extends PackedVectorsException implements UnavailableException
{
    public function __construct(
        public readonly string $path,
        public readonly int $version,
        public readonly int $supported
    ) {
        parent::__construct(sprintf(
            'Packed vector file %s has format version %d; this release reads version %d only',
            $path,
            $version,
            $supported
        ));
    }
}
