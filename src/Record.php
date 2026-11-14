<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

/**
 * One source record for a build or a fallback page.
 *
 * This is a plain carrier: it does not validate. The builder and the
 * fallback scanner validate every record so that the bad-record policy
 * (skip and count, or throw) applies to it.
 */
final class Record
{
    /**
     * @param array<array-key, mixed> $vector a list of dim floats or ints
     */
    public function __construct(
        public readonly int $id,
        public readonly int $ref,
        public readonly array $vector
    ) {
    }
}
