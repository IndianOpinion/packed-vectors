<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

/**
 * One ranked result. (Not called Match: match is a reserved word in PHP 8.)
 */
final class Hit
{
    public function __construct(
        public readonly int $id,
        public readonly int $ref,
        public readonly float $score
    ) {
    }
}
