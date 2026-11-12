<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

/**
 * What a build did. Read-only.
 */
final class BuildReport
{
    /** Skip reasons, in the order they are checked. */
    public const REASONS = ['bad_id', 'not_list', 'length', 'not_numeric', 'non_finite', 'out_of_range', 'zero_norm'];

    /**
     * @param array<string, int> $skipped
     */
    public function __construct(
        private readonly string $path,
        private readonly bool $skippedForLock,
        private readonly int $written,
        private readonly array $skipped,
        private readonly int $bytes,
        private readonly float $seconds,
        private readonly int $dim,
        private readonly bool $normalised,
        private readonly int $builtAt,
        private readonly int $staleTempsRemoved = 0
    ) {
    }

    /**
     * @internal
     */
    public static function lockBusy(string $path, int $dim, bool $normalised, float $seconds): self
    {
        return new self($path, true, 0, array_fill_keys(self::REASONS, 0), 0, $seconds, $dim, $normalised, 0);
    }

    public function path(): string
    {
        return $this->path;
    }

    /** True when another builder held the lock and this one did nothing. */
    public function skippedForLock(): bool
    {
        return $this->skippedForLock;
    }

    /** Records written to the file. */
    public function written(): int
    {
        return $this->written;
    }

    /**
     * Records left out, by reason. Every reason is present, zero if unused.
     *
     * @return array<string, int>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** Total records left out. */
    public function skippedTotal(): int
    {
        return array_sum($this->skipped);
    }

    /** Bytes in the finished file, header included. */
    public function bytes(): int
    {
        return $this->bytes;
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    public function dim(): int
    {
        return $this->dim;
    }

    public function normalised(): bool
    {
        return $this->normalised;
    }

    /** Unix seconds written into the header; 0 when skipped for the lock. */
    public function builtAt(): int
    {
        return $this->builtAt;
    }

    /** Temporary files from crashed builds removed before this build. */
    public function staleTempsRemoved(): int
    {
        return $this->staleTempsRemoved;
    }

    /** One log line: written, skipped by reason, bytes, seconds, dim. */
    public function __toString(): string
    {
        if ($this->skippedForLock) {
            return sprintf('packed-vectors build %s: skipped, lock busy', $this->path);
        }
        $skipped = [];
        foreach ($this->skipped as $reason => $n) {
            if ($n > 0) {
                $skipped[] = $reason . '=' . $n;
            }
        }

        return sprintf(
            'packed-vectors build %s: written=%d skipped=%d%s bytes=%d seconds=%.3f dim=%d normalised=%s',
            $this->path,
            $this->written,
            $this->skippedTotal(),
            $skipped === [] ? '' : ' (' . implode(' ', $skipped) . ')',
            $this->bytes,
            $this->seconds,
            $this->dim,
            $this->normalised ? 'yes' : 'no'
        );
    }
}
