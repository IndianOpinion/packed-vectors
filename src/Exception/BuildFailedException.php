<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

use Throwable;

/**
 * A rebuild did not complete. The target file is untouched and the
 * temporary file has been removed.
 *
 * For short writes, $expectedBytes and $writtenBytes say how far it got.
 */
class BuildFailedException extends PackedVectorsException
{
    public function __construct(
        public readonly string $path,
        string $detail,
        ?Throwable $previous = null,
        public readonly ?int $expectedBytes = null,
        public readonly ?int $writtenBytes = null
    ) {
        $bytes = $expectedBytes === null
            ? ''
            : sprintf(' (expected to write %d bytes, wrote %d)', $expectedBytes, (int) $writtenBytes);
        parent::__construct(sprintf('Build of %s failed: %s%s', $path, $detail, $bytes), 0, $previous);
    }
}
