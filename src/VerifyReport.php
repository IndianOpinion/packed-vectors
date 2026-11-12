<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

/**
 * Result of a full read of a file (Scanner::verify). Read-only.
 */
final class VerifyReport
{
    public function __construct(
        private readonly FileInfo $info,
        private readonly int $payloadCrcActual,
        private readonly int $nonFiniteValues,
        private readonly ?int $firstNonFiniteRecord,
        private readonly float $seconds
    ) {
    }

    public function ok(): bool
    {
        return $this->payloadCrcOk() && $this->nonFiniteValues === 0;
    }

    public function info(): FileInfo
    {
        return $this->info;
    }

    public function payloadCrcOk(): bool
    {
        return $this->payloadCrcActual === $this->info->payloadCrc;
    }

    public function payloadCrcExpected(): int
    {
        return $this->info->payloadCrc;
    }

    public function payloadCrcActual(): int
    {
        return $this->payloadCrcActual;
    }

    /** Stored values that are NaN or infinite. */
    public function nonFiniteValues(): int
    {
        return $this->nonFiniteValues;
    }

    /** Zero-based index of the first record holding a non-finite value. */
    public function firstNonFiniteRecord(): ?int
    {
        return $this->firstNonFiniteRecord;
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    /**
     * Human-readable problems; empty when ok().
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if (!$this->payloadCrcOk()) {
            $problems[] = sprintf(
                'payload checksum mismatch: header says %08x, payload is %08x',
                $this->info->payloadCrc,
                $this->payloadCrcActual
            );
        }
        if ($this->nonFiniteValues > 0) {
            $problems[] = sprintf(
                '%d non-finite value(s), first in record %d',
                $this->nonFiniteValues,
                (int) $this->firstNonFiniteRecord
            );
        }

        return $problems;
    }
}
