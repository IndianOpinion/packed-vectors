<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

use ArrayIterator;
use IndianOpinion\PackedVectors\Exception\BuildFailedException;
use IndianOpinion\PackedVectors\Exception\DirectoryNotWritableException;
use IndianOpinion\PackedVectors\Exception\InvalidArgumentException;
use IndianOpinion\PackedVectors\Exception\InvalidVectorException;
use IndianOpinion\PackedVectors\Exception\UnavailableException;
use IndianOpinion\PackedVectors\Internal\Errors;
use IndianOpinion\PackedVectors\Internal\Header;
use IndianOpinion\PackedVectors\Internal\Vectors;
use Iterator;
use IteratorIterator;
use Throwable;

/**
 * Rebuilds a packed vector file atomically from an iterable source.
 *
 * The file is written to a temporary sibling, every write is checked, the
 * data is fsynced, and only then is the temporary file renamed over the
 * target. Readers therefore see the old file or the new one, never a mix.
 *
 * Not final only so tests can override the protected seams; treat it as
 * final.
 */
class Builder
{
    private readonly BuildOptions $options;

    public function __construct(
        private readonly string $path,
        private readonly int $dim,
        ?BuildOptions $options = null
    ) {
        Environment::assertSupported();
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('path must be a non-empty string without NUL bytes');
        }
        if (str_ends_with($path, '/') || str_ends_with($path, DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException(sprintf('path must name a file, not a directory: %s', $path));
        }
        Format::assertDim($dim);
        $this->options = $options ?? new BuildOptions();
    }

    /**
     * Builds the file from $records and replaces the target atomically.
     *
     * Each item is a Record or a list [int $id, int $ref, list<float|int> $vector].
     *
     * @param iterable<mixed> $records
     * @throws DirectoryNotWritableException before the source is touched
     * @throws BuildFailedException on any write, close, rename or source failure
     * @throws InvalidVectorException with onBadRecord = throw
     * @throws InvalidArgumentException when an item is neither a Record nor a three-element list
     */
    public function build(iterable $records): BuildReport
    {
        $report = $this->run(static fn (): iterable => $records, null);
        assert($report instanceof BuildReport);

        return $report;
    }

    /**
     * Rebuilds only when the file is missing, unreadable, corrupt, of another
     * version, built for another dim or normalisation setting, or older than
     * $maxAgeSeconds. Returns null when the file is fresh.
     *
     * $recordsFactory is called only when a rebuild runs, after the lock is
     * taken, and must return an iterable of records.
     *
     * @param callable(): iterable<mixed> $recordsFactory
     */
    public function rebuildIfStale(int $maxAgeSeconds, callable $recordsFactory): ?BuildReport
    {
        if ($maxAgeSeconds < 0) {
            throw new InvalidArgumentException(sprintf('maxAgeSeconds must be 0 or more, got %d', $maxAgeSeconds));
        }
        $stale = fn (): bool => $this->isStale($maxAgeSeconds);
        if (!$stale()) {
            return null;
        }

        return $this->run($recordsFactory, $stale);
    }

    /**
     * Why the current file needs a rebuild, or null if it is fresh.
     */
    public function staleReason(int $maxAgeSeconds): ?string
    {
        try {
            $info = (new Scanner($this->path))->info();
        } catch (UnavailableException $e) {
            return $e->getMessage();
        }
        if ($info->dim !== $this->dim) {
            return sprintf('file dim %d, builder dim %d', $info->dim, $this->dim);
        }
        if ($info->normalised() !== $this->options->normalise) {
            return 'normalisation setting changed';
        }
        if (time() - $info->builtAt > $maxAgeSeconds) {
            return sprintf('built %d s ago, limit %d s', time() - $info->builtAt, $maxAgeSeconds);
        }

        return null;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function lockPath(): string
    {
        return $this->path . '.lock';
    }

    /**
     * Name for a new temporary file: the target name, 64 random bits in
     * hex, and ".tmp". Seam for tests.
     *
     * @internal
     */
    protected function temporaryPath(): string
    {
        return $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    }

    /**
     * Opens the temporary file with exclusive create, so an existing file
     * or symlink of that name is never followed. Seam for tests.
     *
     * @internal
     * @return resource|false
     */
    protected function openTemporaryFile(string $temporaryPath)
    {
        return fopen($temporaryPath, 'xb');
    }

    private function isStale(int $maxAgeSeconds): bool
    {
        return $this->staleReason($maxAgeSeconds) !== null;
    }

    /**
     * @param callable(): iterable<mixed> $source
     * @param (callable(): bool)|null $stillNeeded re-checked once the lock is held
     */
    private function run(callable $source, ?callable $stillNeeded): ?BuildReport
    {
        $started = (int) hrtime(true);
        $this->assertDirectoryWritable();

        $lock = null;
        if ($this->options->lock) {
            $lock = $this->acquireLock();
            if ($lock === null) {
                return BuildReport::lockBusy($this->path, $this->dim, $this->options->normalise, self::since($started));
            }
        }

        try {
            if ($stillNeeded !== null && !$stillNeeded()) {
                return null;
            }
            $removed = $this->removeStaleTemporaries();
            $records = $source();
            if (!is_iterable($records)) {
                throw new InvalidArgumentException(sprintf(
                    'the records factory must return an iterable, got %s',
                    get_debug_type($records)
                ));
            }

            return $this->write($records, $started, $removed);
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function directory(): string
    {
        return dirname($this->path);
    }

    private function assertDirectoryWritable(): void
    {
        $dir = $this->directory();
        clearstatcache(true, $dir);
        if (!is_dir($dir)) {
            throw new DirectoryNotWritableException($this->path, $dir, 'does not exist');
        }
        if (!is_writable($dir)) {
            throw new DirectoryNotWritableException($this->path, $dir, 'is not writable by this process');
        }
    }

    /**
     * @return resource|null null when another builder holds the lock
     */
    private function acquireLock()
    {
        $lockPath = $this->lockPath();
        $handle = Errors::capture(static fn () => fopen($lockPath, 'c'));
        if (!is_resource($handle)) {
            throw new BuildFailedException(
                $this->path,
                sprintf('cannot open lock file %s: %s', $lockPath, Errors::last())
            );
        }
        $deadline = microtime(true) + $this->options->lockWaitSeconds;
        while (true) {
            $wouldBlock = 0;
            if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                return $handle;
            }
            if ($wouldBlock !== 1) {
                fclose($handle);
                throw new BuildFailedException($this->path, sprintf('cannot lock %s', $lockPath));
            }
            $left = $deadline - microtime(true);
            if ($left <= 0.0) {
                fclose($handle);

                return null;
            }
            usleep((int) (min($left, 0.05) * 1e6) + 1);
        }
    }

    /**
     * Removes temporary files left by crashed builds of this target: only
     * regular files matching this builder's own name pattern and older than
     * the configured age. Anything else is left alone.
     */
    private function removeStaleTemporaries(): int
    {
        $dir = $this->directory();
        $entries = Errors::capture(static fn () => scandir($dir));
        if (!is_array($entries)) {
            return 0;
        }
        $pattern = '/^' . preg_quote(basename($this->path), '/') . '\.[0-9a-f]{16}\.tmp$/D';
        $cutoff = time() - $this->options->staleTempAgeSeconds;
        $removed = 0;
        foreach ($entries as $entry) {
            if (preg_match($pattern, $entry) !== 1) {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            $stat = Errors::capture(static fn () => lstat($full));
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['mtime'] >= $cutoff) {
                continue;
            }
            // A failed removal is not fatal: the new build uses a fresh name.
            if (Errors::capture(static fn () => unlink($full))) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param iterable<mixed> $records
     */
    private function write(iterable $records, int $started, int $staleRemoved): BuildReport
    {
        $tmp = $this->temporaryPath();
        $handle = Errors::capture(fn () => $this->openTemporaryFile($tmp));
        if (!is_resource($handle)) {
            throw new BuildFailedException(
                $this->path,
                sprintf('cannot create temporary file %s: %s', $tmp, Errors::last())
            );
        }

        $done = false;
        $total = 0;
        try {
            $mode = $this->options->fileMode;
            if (!Errors::capture(static fn () => chmod($tmp, $mode))) {
                throw new BuildFailedException(
                    $this->path,
                    sprintf('cannot set mode 0%o on %s: %s', $mode, $tmp, Errors::last())
                );
            }

            $this->writeAll($handle, str_repeat("\0", Format::HEADER_SIZE), $total);

            $normalise = $this->options->normalise;
            $throwOnBad = $this->options->onBadRecord === BuildOptions::THROW;
            $limit = $this->options->writeBufferBytes;
            $crc = hash_init('crc32b');
            $skipped = array_fill_keys(BuildReport::REASONS, 0);
            $written = 0;
            $index = -1;
            $buffer = '';

            $iterator = $this->iterate($records);
            $this->source(static fn () => $iterator->rewind());
            while ($this->source(static fn (): bool => $iterator->valid())) {
                $item = $this->source(static fn (): mixed => $iterator->current());
                $index++;

                [$id, $ref, $vector] = $this->unpackItem($item, $index);
                $norm = 0.0;
                if (!Vectors::validKey($id) || !Vectors::validKey($ref)) {
                    $reason = 'bad_id';
                } elseif (!is_array($vector)) {
                    $reason = 'not_list';
                } else {
                    $reason = Vectors::problem($vector, $this->dim, true, $norm);
                    if ($reason === 'zero_norm' && !$normalise) {
                        $reason = null;
                    }
                }

                if ($reason !== null) {
                    if ($throwOnBad) {
                        throw new InvalidVectorException($reason, Vectors::describe($reason, 'record'), $index, $id);
                    }
                    $skipped[$reason]++;
                } else {
                    /** @var int $id */
                    /** @var int $ref */
                    /** @var list<float|int> $vector */
                    if ($normalise) {
                        $values = [];
                        foreach ($vector as $v) {
                            $values[] = $v / $norm;
                        }
                        $buffer .= pack('VVg*', $id, $ref, ...$values);
                    } else {
                        $buffer .= pack('VVg*', $id, $ref, ...$vector);
                    }
                    $written++;
                    if (strlen($buffer) >= $limit) {
                        hash_update($crc, $buffer);
                        $this->writeAll($handle, $buffer, $total);
                        $buffer = '';
                    }
                }

                $this->source(static fn () => $iterator->next());
            }
            if ($buffer !== '') {
                hash_update($crc, $buffer);
                $this->writeAll($handle, $buffer, $total);
                $buffer = '';
            }

            $payloadCrc = self::crcToInt(hash_final($crc, true));
            $builtAt = time();
            $flags = $normalise ? Format::FLAG_NORMALISED : 0;
            $header = Header::encode($flags, $this->dim, $written, $builtAt, $payloadCrc);

            if (fseek($handle, 0) !== 0) {
                throw new BuildFailedException($this->path, 'cannot seek to the start of the temporary file');
            }
            $headerBytes = 0;
            $this->writeAll($handle, $header, $headerBytes);
            if (!fflush($handle)) {
                throw new BuildFailedException($this->path, 'flush of the temporary file failed');
            }
            if ($this->options->fsync && !Errors::capture(static fn (): bool => fsync($handle))) {
                throw new BuildFailedException($this->path, 'fsync of the temporary file failed: ' . Errors::last());
            }
            $closed = Errors::capture(static fn (): bool => fclose($handle));
            $handle = null;
            if (!$closed) {
                throw new BuildFailedException($this->path, 'close of the temporary file failed: ' . Errors::last());
            }

            $expected = Format::expectedFileSize($this->dim, $written);
            if ($total !== $expected) {
                throw new BuildFailedException(
                    $this->path,
                    'temporary file has the wrong size',
                    null,
                    $expected,
                    $total
                );
            }

            $target = $this->path;
            if (!Errors::capture(static fn (): bool => rename($tmp, $target))) {
                throw new BuildFailedException(
                    $this->path,
                    sprintf('cannot rename %s into place: %s', $tmp, Errors::last())
                );
            }
            $done = true;
            if ($this->options->fsync) {
                $this->syncDirectory();
            }

            return new BuildReport(
                $this->path,
                false,
                $written,
                $skipped,
                $total,
                self::since($started),
                $this->dim,
                $normalise,
                $builtAt,
                $staleRemoved
            );
        } finally {
            if (!$done) {
                if (is_resource($handle)) {
                    Errors::capture(static fn (): bool => fclose($handle));
                }
                clearstatcache(true, $tmp);
                if (file_exists($tmp) || is_link($tmp)) {
                    Errors::capture(static fn (): bool => unlink($tmp));
                }
            }
        }
    }

    /**
     * Writes every byte or throws. Short writes are retried; a write that
     * makes no progress is a failure.
     *
     * @param resource $handle
     */
    private function writeAll($handle, string $data, int &$total): void
    {
        $length = strlen($data);
        $offset = 0;
        $before = $total;
        while ($offset < $length) {
            $chunk = $offset === 0 ? $data : substr($data, $offset);
            $n = Errors::capture(static fn (): int|false => fwrite($handle, $chunk));
            if ($n === false || $n === 0) {
                $total = $before + $offset;
                throw new BuildFailedException(
                    $this->path,
                    'write to the temporary file failed' . (Errors::last() !== '' ? ': ' . Errors::last() : ''),
                    null,
                    $before + $length,
                    $before + $offset
                );
            }
            $offset += $n;
        }
        $total = $before + $length;
    }

    /**
     * fsync the directory so the rename itself survives a power cut. Best
     * effort: the new file is already in place, so a platform that cannot
     * open or sync a directory does not fail the build.
     */
    private function syncDirectory(): void
    {
        $dir = $this->directory();
        $handle = Errors::capture(static fn () => fopen($dir, 'r'));
        if (!is_resource($handle)) {
            return;
        }
        Errors::capture(static fn (): bool => fsync($handle));
        fclose($handle);
    }

    /**
     * @param iterable<mixed> $records
     * @return Iterator<mixed>
     */
    private function iterate(iterable $records): Iterator
    {
        if (is_array($records)) {
            return new ArrayIterator($records);
        }
        if ($records instanceof Iterator) {
            return $records;
        }

        return new IteratorIterator($records);
    }

    /**
     * Runs one step of the caller's source and wraps anything it throws.
     *
     * @template T
     * @param callable(): T $step
     * @return T
     */
    private function source(callable $step): mixed
    {
        try {
            return $step();
        } catch (Throwable $e) {
            throw new BuildFailedException(
                $this->path,
                'the record source threw ' . get_class($e) . ': ' . $e->getMessage(),
                $e
            );
        }
    }

    /**
     * @return array{0: mixed, 1: mixed, 2: mixed}
     */
    private function unpackItem(mixed $item, int $index): array
    {
        if ($item instanceof Record) {
            return [$item->id, $item->ref, $item->vector];
        }
        if (is_array($item) && count($item) === 3 && array_is_list($item)) {
            return [$item[0], $item[1], $item[2]];
        }
        throw new InvalidArgumentException(sprintf(
            'record %d must be a Record or a list [id, ref, vector], got %s',
            $index,
            get_debug_type($item)
        ));
    }

    private static function crcToInt(string $raw): int
    {
        /** @var array{1: int} $u */
        $u = unpack('N', $raw);

        return $u[1];
    }

    private static function since(int $started): float
    {
        return ((int) hrtime(true) - $started) / 1e9;
    }
}
