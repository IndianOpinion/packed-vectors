<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

/**
 * The scan ran past its time budget. No partial results are returned.
 */
final class BudgetExceededException extends PackedVectorsException
{
    public function __construct(
        public readonly int $scanned,
        public readonly float $seconds,
        public readonly float $budgetSeconds
    ) {
        parent::__construct(sprintf(
            'Scan exceeded its time budget of %.6f s after %d records (%.6f s)',
            $budgetSeconds,
            $scanned,
            $seconds
        ));
    }
}
