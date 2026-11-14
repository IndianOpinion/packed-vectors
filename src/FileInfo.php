<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

/**
 * Header fields of a valid file, plus its size. Read-only.
 */
final class FileInfo
{
    public function __construct(
        public readonly string $path,
        public readonly int $version,
        public readonly int $flags,
        public readonly int $dim,
        public readonly int $count,
        public readonly int $builtAt,
        public readonly int $payloadCrc,
        public readonly int $headerCrc,
        public readonly int $recordSize,
        public readonly int $fileSize
    ) {
    }

    public function normalised(): bool
    {
        return ($this->flags & Format::FLAG_NORMALISED) !== 0;
    }

    /** Seconds since built_at, never negative. */
    public function ageSeconds(?int $now = null): int
    {
        return max(0, ($now ?? time()) - $this->builtAt);
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'version' => $this->version,
            'flags' => $this->flags,
            'normalised' => $this->normalised(),
            'dim' => $this->dim,
            'count' => $this->count,
            'built_at' => $this->builtAt,
            'payload_crc' => sprintf('%08x', $this->payloadCrc),
            'header_crc' => sprintf('%08x', $this->headerCrc),
            'record_size' => $this->recordSize,
            'file_size' => $this->fileSize,
        ];
    }
}
