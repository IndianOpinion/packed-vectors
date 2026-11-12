<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

use IndianOpinion\PackedVectors\Exception\BudgetExceededException;
use IndianOpinion\PackedVectors\Exception\CorruptFileException;
use IndianOpinion\PackedVectors\Exception\DimensionMismatchException;
use IndianOpinion\PackedVectors\Exception\FileMissingException;
use IndianOpinion\PackedVectors\Exception\InvalidArgumentException;
use IndianOpinion\PackedVectors\Exception\InvalidVectorException;
use IndianOpinion\PackedVectors\Exception\UnreadableFileException;
use IndianOpinion\PackedVectors\Exception\UnsupportedVersionException;
use IndianOpinion\PackedVectors\Internal\Errors;
use IndianOpinion\PackedVectors\Internal\Header;
use IndianOpinion\PackedVectors\Internal\TopK;
use IndianOpinion\PackedVectors\Internal\Vectors;
use Throwable;

/**
 * Exact cosine top-k over a packed vector file, in bounded memory.
 *
 * The file is opened once; the header and the size are checked through
 * that handle, and every later read uses it. The scanner never writes,
 * locks, renames or deletes anything.
 *
 * Not final only so tests can override the protected seams; treat it as
 * final.
 */
class Scanner
{
    /**
     * Most float values decoded by one unpack() call. Bounds the memory of
     * the decoded array (about 1 MiB on PHP 8.1) whatever chunkBytes is.
     */
    public const UNPACK_BLOCK_VALUES = 32768;

    /** The time budget is checked once every this many records. */
    public const BUDGET_CHECK_EVERY = 256;

    private readonly ScanOptions $options;

    public function __construct(private readonly string $path, ?ScanOptions $options = null)
    {
        Environment::assertSupported();
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('path must be a non-empty string without NUL bytes');
        }
        $this->options = $options ?? new ScanOptions();
    }

    /**
     * The k best records for $query, best first.
     *
     * @param array<array-key, mixed> $query a list of dim numbers
     * @throws InvalidArgumentException when k is outside 1 to 10,000
     * @throws InvalidVectorException when the query is not a list, holds a non-finite value or is all zeros
     * @throws FileMissingException
     * @throws UnreadableFileException
     * @throws CorruptFileException
     * @throws UnsupportedVersionException
     * @throws DimensionMismatchException
     * @throws BudgetExceededException
     */
    public function top(array $query, int $k): ScanResult
    {
        $started = (int) hrtime(true);
        Vectors::assertK($k);
        $norm = Vectors::queryNorm($query);

        [$handle, $info] = $this->open();
        try {
            /** @var list<float|int> $query */
            $q = Vectors::unitQuery($query, $norm, $info->dim, $this->path);

            return $this->scan($handle, $info, $q, $k, $started);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Header fields and file size, with the same checks as a scan. Reads
     * only the 40-byte header.
     */
    public function info(): FileInfo
    {
        [$handle, $info] = $this->open();
        fclose($handle);

        return $info;
    }

    /**
     * Reads the whole file once: checks the payload checksum and that every
     * stored value is finite. For operators and tests, not request paths.
     * Header and size problems throw, as in info().
     */
    public function verify(): VerifyReport
    {
        $started = (int) hrtime(true);
        [$handle, $info] = $this->open();
        try {
            $dim = $info->dim;
            $words = $dim + 2;
            $crc = hash_init('crc32b');
            $nonFinite = 0;
            $firstBad = null;
            $record = 0;
            foreach ($this->chunks($handle, $info) as [$chunk, $n]) {
                hash_update($crc, $chunk);
                foreach ($this->blocks($n, $dim) as [$from, $m]) {
                    /** @var array<int, float> $vals */
                    $vals = unpack('g' . ($m * $words), $chunk, $from * $info->recordSize);
                    for ($r = 0; $r < $m; $r++) {
                        $base = $r * $words + 3;
                        $end = $base + $dim;
                        for ($p = $base; $p < $end; $p++) {
                            if (!is_finite($vals[$p])) {
                                $nonFinite++;
                                $firstBad ??= $record;
                            }
                        }
                        $record++;
                    }
                }
            }
            $actual = self::crcToInt(hash_final($crc, true));

            return new VerifyReport($info, $actual, $nonFinite, $firstBad, self::since($started));
        } finally {
            fclose($handle);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Called right after fopen() succeeds, before the size is taken from
     * the handle. Seam for tests.
     *
     * @internal
     * @param resource $handle
     */
    protected function afterOpen($handle): void
    {
    }

    /**
     * Called after each chunk is scored, with the records scored so far.
     * Seam for tests.
     *
     * @internal
     */
    protected function afterChunk(int $recordsSoFar): void
    {
    }

    /**
     * @param resource $handle
     * @param list<float> $q unit query, 0-based
     */
    private function scan($handle, FileInfo $info, array $q, int $k, int $started): ScanResult
    {
        $dim = $info->dim;
        $words = $dim + 2;
        $normalised = $info->normalised();
        $verify = $this->options->verifyChecksum;
        $crc = $verify ? hash_init('crc32b') : null;
        $min = $this->options->minScore ?? -INF;
        $topk = new TopK($k, $this->options->bestPerRef);
        $floor = -INF;
        $budget = $this->options->timeBudgetSeconds;
        $deadline = $budget === null ? null : $started + (int) ($budget * 1e9);
        $scanned = 0;
        $bytes = 0;

        foreach ($this->chunks($handle, $info) as [$chunk, $n]) {
            $bytes += strlen($chunk);
            if ($crc !== null) {
                hash_update($crc, $chunk);
            }
            foreach ($this->blocks($n, $dim) as [$from, $m]) {
                $blockOffset = $from * $info->recordSize;
                // unpack() keys start at 1. Record r's values are words
                // r * (dim + 2) + 2 onwards, so its first value is at key
                // r * (dim + 2) + 3 and its last at r * (dim + 2) + dim + 2.
                /** @var array<int, float> $vals */
                $vals = unpack('g' . ($m * $words), $chunk, $blockOffset);
                for ($r = 0; $r < $m; $r++) {
                    $base = $r * $words + 3;
                    $dot = 0.0;
                    if ($normalised) {
                        for ($j = 0; $j < $dim; $j++) {
                            $dot += $q[$j] * $vals[$base + $j];
                        }
                        $score = $dot;
                    } else {
                        $nn = 0.0;
                        for ($j = 0; $j < $dim; $j++) {
                            $v = $vals[$base + $j];
                            $dot += $q[$j] * $v;
                            $nn += $v * $v;
                        }
                        $score = $nn > 0.0 ? $dot / sqrt($nn) : 0.0;
                    }
                    $scanned++;

                    if (!is_finite($score)) {
                        throw new CorruptFileException(
                            $this->path,
                            CorruptFileException::REASON_NON_FINITE,
                            sprintf('record %d holds a NaN or infinite value', $scanned - 1)
                        );
                    }
                    if ($score > 1.0) {
                        $score = 1.0;
                    } elseif ($score < -1.0) {
                        $score = -1.0;
                    }
                    if ($score >= $floor && $score >= $min) {
                        /** @var array{1: int, 2: int} $key */
                        $key = unpack('V2', $chunk, $blockOffset + $r * $info->recordSize);
                        $floor = $topk->offer($score, $key[1], $key[2]);
                    }

                    if ($deadline !== null && ($scanned % self::BUDGET_CHECK_EVERY) === 0) {
                        $now = (int) hrtime(true);
                        if ($now > $deadline) {
                            throw new BudgetExceededException($scanned, ($now - $started) / 1e9, (float) $budget);
                        }
                    }
                }
            }
            $this->afterChunk($scanned);
        }

        if ($crc !== null) {
            $actual = self::crcToInt(hash_final($crc, true));
            if ($actual !== $info->payloadCrc) {
                throw new CorruptFileException(
                    $this->path,
                    CorruptFileException::REASON_PAYLOAD_CRC,
                    sprintf('payload checksum is %08x, header says %08x', $actual, $info->payloadCrc)
                );
            }
        }

        $report = new ScanReport(
            $scanned,
            0,
            $bytes,
            self::since($started),
            $dim,
            $info->builtAt,
            $normalised,
            $crc !== null
        );

        return new ScanResult($topk->sorted(), $report);
    }

    /**
     * Opens the file and checks the header against the size of the open
     * handle (never the path, which may already name a newer file).
     *
     * @return array{0: resource, 1: FileInfo}
     */
    private function open(): array
    {
        $path = $this->path;
        $handle = Errors::capture(static fn () => fopen($path, 'rb'));
        if (!is_resource($handle)) {
            $why = Errors::last();
            clearstatcache(true, $path);
            if (!Errors::capture(static fn (): bool => file_exists($path))) {
                throw new FileMissingException($path);
            }
            throw new UnreadableFileException($path, $why);
        }

        try {
            $this->afterOpen($handle);
            // Plain files: read exactly what is asked for, no 8 KiB read-ahead.
            // Other stream wrappers may not support this; that is fine.
            Errors::capture(static fn () => stream_set_read_buffer($handle, 0));
            $stat = Errors::capture(static fn () => fstat($handle));
            if (!is_array($stat)) {
                throw new UnreadableFileException($path, 'fstat failed: ' . Errors::last());
            }
            if (($stat['mode'] & 0170000) === 0040000) {
                throw new UnreadableFileException($path, 'it is a directory');
            }
            $size = (int) $stat['size'];
            Errors::capture(static fn () => stream_set_chunk_size($handle, Format::HEADER_SIZE));
            $header = $this->read($handle, min(Format::HEADER_SIZE, max(0, $size)), false);
            $info = Header::decode($header, $path, $size);
        } catch (Throwable $e) {
            fclose($handle);
            throw $e;
        }

        return [$handle, $info];
    }

    /**
     * Yields the payload in chunks of whole records: [bytes, records].
     *
     * @param resource $handle
     * @return \Generator<int, array{0: string, 1: int}>
     */
    private function chunks($handle, FileInfo $info): \Generator
    {
        $perChunk = max(1, intdiv($this->options->chunkBytes, $info->recordSize));
        $wrapperChunk = max(8192, min($perChunk * $info->recordSize, 1 << 22));
        Errors::capture(static fn () => stream_set_chunk_size($handle, $wrapperChunk));
        $done = 0;
        while ($done < $info->count) {
            $n = min($perChunk, $info->count - $done);
            yield [$this->read($handle, $n * $info->recordSize, true), $n];
            $done += $n;
        }
    }

    /**
     * Splits $n records into unpack blocks: [first record, records].
     *
     * @return \Generator<int, array{0: int, 1: int}>
     */
    private function blocks(int $n, int $dim): \Generator
    {
        $perBlock = max(1, intdiv(self::UNPACK_BLOCK_VALUES, $dim + 2));
        for ($from = 0; $from < $n; $from += $perBlock) {
            yield [$from, min($perBlock, $n - $from)];
        }
    }

    /**
     * Reads exactly $want bytes. A short read is not trusted to end on a
     * record boundary: reads continue until $want bytes have arrived. End
     * of file first means the file is corrupt.
     *
     * @param resource $handle
     */
    private function read($handle, int $want, bool $payload): string
    {
        $buffer = '';
        while (true) {
            $have = strlen($buffer);
            $need = $want - $have;
            if ($need <= 0) {
                break;
            }
            $part = Errors::capture(static fn () => fread($handle, $need));
            if ($part === false) {
                throw new UnreadableFileException($this->path, 'read failed: ' . Errors::last());
            }
            if ($part === '') {
                throw new CorruptFileException(
                    $this->path,
                    $payload ? CorruptFileException::REASON_SHORT_READ : CorruptFileException::REASON_SIZE,
                    sprintf('end of file after %d of %d bytes', $have, $want)
                );
            }
            $buffer = $buffer === '' ? $part : $buffer . $part;
        }

        return $buffer;
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
