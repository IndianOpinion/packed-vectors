<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

/**
 * Ranked hits plus the scan report.
 */
final class ScanResult
{
    /**
     * @param list<Hit> $hits best first
     */
    public function __construct(private readonly array $hits, private readonly ScanReport $report)
    {
    }

    /**
     * Best first: higher score, then lower id, then lower ref.
     *
     * @return list<Hit>
     */
    public function hits(): array
    {
        return $this->hits;
    }

    public function report(): ScanReport
    {
        return $this->report;
    }
}
