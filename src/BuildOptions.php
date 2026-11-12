<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

use IndianOpinion\PackedVectors\Exception\InvalidArgumentException;

/**
 * Immutable build settings. Each with-er returns a validated copy.
 */
final class BuildOptions
{
    public const SKIP = 'skip';
    public const THROW = 'throw';

    /**
     * @param 'skip'|'throw' $onBadRecord
     */
    public function __construct(
        public readonly bool $normalise = true,
        public readonly string $onBadRecord = self::SKIP,
        public readonly int $fileMode = 0640,
        public readonly int $writeBufferBytes = 1048576,
        public readonly bool $lock = true,
        public readonly float $lockWaitSeconds = 0.0,
        public readonly int $staleTempAgeSeconds = 3600,
        public readonly bool $fsync = true
    ) {
        if ($onBadRecord !== self::SKIP && $onBadRecord !== self::THROW) {
            throw new InvalidArgumentException(
                sprintf('onBadRecord must be "skip" or "throw", got "%s"', $onBadRecord)
            );
        }
        if ($fileMode < 0 || $fileMode > 0777) {
            throw new InvalidArgumentException(sprintf('fileMode must be 0 to 0777, got 0%o', $fileMode));
        }
        if ($writeBufferBytes < 1 || $writeBufferBytes > 1073741824) {
            throw new InvalidArgumentException(
                sprintf('writeBufferBytes must be 1 to 1 GiB, got %d', $writeBufferBytes)
            );
        }
        if (!is_finite($lockWaitSeconds) || $lockWaitSeconds < 0.0) {
            throw new InvalidArgumentException('lockWaitSeconds must be a finite number of 0 or more');
        }
        if ($staleTempAgeSeconds < 0) {
            throw new InvalidArgumentException(
                sprintf('staleTempAgeSeconds must be 0 or more, got %d', $staleTempAgeSeconds)
            );
        }
    }

    public function withNormalise(bool $normalise): self
    {
        return $this->with(['normalise' => $normalise]);
    }

    /**
     * @param 'skip'|'throw' $onBadRecord
     */
    public function withOnBadRecord(string $onBadRecord): self
    {
        return $this->with(['onBadRecord' => $onBadRecord]);
    }

    public function withFileMode(int $fileMode): self
    {
        return $this->with(['fileMode' => $fileMode]);
    }

    public function withWriteBufferBytes(int $writeBufferBytes): self
    {
        return $this->with(['writeBufferBytes' => $writeBufferBytes]);
    }

    public function withLock(bool $lock): self
    {
        return $this->with(['lock' => $lock]);
    }

    public function withLockWaitSeconds(float $lockWaitSeconds): self
    {
        return $this->with(['lockWaitSeconds' => $lockWaitSeconds]);
    }

    public function withStaleTempAgeSeconds(int $staleTempAgeSeconds): self
    {
        return $this->with(['staleTempAgeSeconds' => $staleTempAgeSeconds]);
    }

    public function withFsync(bool $fsync): self
    {
        return $this->with(['fsync' => $fsync]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function with(array $changes): self
    {
        $current = [
            'normalise' => $this->normalise,
            'onBadRecord' => $this->onBadRecord,
            'fileMode' => $this->fileMode,
            'writeBufferBytes' => $this->writeBufferBytes,
            'lock' => $this->lock,
            'lockWaitSeconds' => $this->lockWaitSeconds,
            'staleTempAgeSeconds' => $this->staleTempAgeSeconds,
            'fsync' => $this->fsync,
        ];

        return new self(...array_merge($current, $changes));
    }
}
