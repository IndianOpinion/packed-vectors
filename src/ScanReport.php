<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

/**
 * What a scan did. Read-only.
 */
final class ScanReport
{
    public function __construct(
        private readonly int $scanned,
        private readonly int $skipped,
        private readonly int $bytesRead,
        private readonly float $seconds,
        private readonly int $dim,
        private readonly ?int $builtAt,
        private readonly bool $normalised,
        private readonly bool $checksumVerified
    ) {
    }

    /**
     * Records read. For the fallback this counts every record the pages
     * returned, including the skipped ones.
     */
    public function scanned(): int
    {
        return $this->scanned;
    }

    /** Records the fallback skipped as unusable. Always 0 for the packed scan. */
    public function skipped(): int
    {
        return $this->skipped;
    }

    /** Payload bytes read from the packed file. 0 for the fallback. */
    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    public function dim(): int
    {
        return $this->dim;
    }

    /** The file's build time; null for the fallback. */
    public function builtAt(): ?int
    {
        return $this->builtAt;
    }

    /** Whether the stored vectors were unit-normalised. False for the fallback. */
    public function normalised(): bool
    {
        return $this->normalised;
    }

    public function checksumVerified(): bool
    {
        return $this->checksumVerified;
    }

    public function __toString(): string
    {
        return sprintf(
            'packed-vectors scan: scanned=%d skipped=%d bytes=%d seconds=%.4f dim=%d built_at=%s checksum=%s',
            $this->scanned,
            $this->skipped,
            $this->bytesRead,
            $this->seconds,
            $this->dim,
            $this->builtAt === null ? '-' : (string) $this->builtAt,
            $this->checksumVerified ? 'verified' : ($this->builtAt === null ? 'n/a' : 'off')
        );
    }
}
