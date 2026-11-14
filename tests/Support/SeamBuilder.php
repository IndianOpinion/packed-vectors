<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests\Support;

use IndianOpinion\PackedVectors\Builder;

/**
 * Builder with its protected seams opened up for tests.
 */
final class SeamBuilder extends Builder
{
    /** Forced temporary file name, or null for the real random one. */
    public ?string $forcedTemporaryPath = null;

    /** When true, the temporary file is opened through FaultyWriteStream. */
    public bool $faultyWrites = false;

    /** Every temporary path this builder chose. */
    /** @var list<string> */
    public array $temporaryPaths = [];

    protected function temporaryPath(): string
    {
        $path = $this->forcedTemporaryPath ?? parent::temporaryPath();
        $this->temporaryPaths[] = $path;

        return $path;
    }

    protected function openTemporaryFile(string $temporaryPath)
    {
        if ($this->faultyWrites) {
            return fopen(FaultyWriteStream::SCHEME . '://' . $temporaryPath, 'xb');
        }

        return parent::openTemporaryFile($temporaryPath);
    }
}
