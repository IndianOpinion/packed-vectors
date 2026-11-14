<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests\Support;

/**
 * Mutable flag and counter shared with closures and generators in tests.
 */
final class Flag
{
    public bool $set = false;

    public int $count = 0;
}
