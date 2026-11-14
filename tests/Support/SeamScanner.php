<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests\Support;

use Closure;
use IndianOpinion\PackedVectors\Scanner;

/**
 * Scanner with its protected seams opened up for tests.
 */
final class SeamScanner extends Scanner
{
    /** @var (Closure(): void)|null */
    public ?Closure $onOpen = null;

    /** @var (Closure(int): void)|null */
    public ?Closure $onChunk = null;

    protected function afterOpen($handle): void
    {
        if ($this->onOpen !== null) {
            ($this->onOpen)();
        }
    }

    protected function afterChunk(int $recordsSoFar): void
    {
        if ($this->onChunk !== null) {
            ($this->onChunk)($recordsSoFar);
        }
    }
}
