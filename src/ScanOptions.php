<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

use IndianOpinion\PackedVectors\Exception\InvalidArgumentException;

/**
 * Immutable scan settings, shared by Scanner and ChunkedScanner.
 */
final class ScanOptions
{
    public function __construct(
        public readonly ?float $minScore = null,
        public readonly bool $bestPerRef = false,
        public readonly bool $verifyChecksum = true,
        public readonly int $chunkBytes = 4194304,
        public readonly ?float $timeBudgetSeconds = null
    ) {
        if ($minScore !== null && !is_finite($minScore)) {
            throw new InvalidArgumentException('minScore must be a finite number or null');
        }
        if ($chunkBytes < 1 || $chunkBytes > 1073741824) {
            throw new InvalidArgumentException(sprintf('chunkBytes must be 1 to 1 GiB, got %d', $chunkBytes));
        }
        if ($timeBudgetSeconds !== null && (!is_finite($timeBudgetSeconds) || $timeBudgetSeconds <= 0.0)) {
            throw new InvalidArgumentException('timeBudgetSeconds must be a finite number above 0, or null');
        }
    }

    public function withMinScore(?float $minScore): self
    {
        return $this->with(['minScore' => $minScore]);
    }

    public function withBestPerRef(bool $bestPerRef): self
    {
        return $this->with(['bestPerRef' => $bestPerRef]);
    }

    public function withVerifyChecksum(bool $verifyChecksum): self
    {
        return $this->with(['verifyChecksum' => $verifyChecksum]);
    }

    public function withChunkBytes(int $chunkBytes): self
    {
        return $this->with(['chunkBytes' => $chunkBytes]);
    }

    public function withTimeBudgetSeconds(?float $timeBudgetSeconds): self
    {
        return $this->with(['timeBudgetSeconds' => $timeBudgetSeconds]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function with(array $changes): self
    {
        $current = [
            'minScore' => $this->minScore,
            'bestPerRef' => $this->bestPerRef,
            'verifyChecksum' => $this->verifyChecksum,
            'chunkBytes' => $this->chunkBytes,
            'timeBudgetSeconds' => $this->timeBudgetSeconds,
        ];

        return new self(...array_merge($current, $changes));
    }
}
