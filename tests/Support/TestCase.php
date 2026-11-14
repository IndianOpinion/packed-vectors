<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests\Support;

use IndianOpinion\PackedVectors\BuildOptions;
use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\BuildReport;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Gives each test its own directory and a few file helpers.
 */
abstract class TestCase extends BaseTestCase
{
    protected string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'packed-vectors-tests';
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        $this->dir = $base . DIRECTORY_SEPARATOR . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            self::removeTree($this->dir);
        }
        parent::tearDown();
    }

    protected function path(string $name = 'vectors.bin'): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Fast options for tests: no fsync.
     */
    protected static function opts(): BuildOptions
    {
        return (new BuildOptions())->withFsync(false);
    }

    /**
     * @param iterable<mixed> $records
     */
    protected function buildFile(string $path, int $dim, iterable $records, ?BuildOptions $options = null): BuildReport
    {
        return (new Builder($path, $dim, $options ?? self::opts()))->build($records);
    }

    /**
     * Deterministic pseudo-random vectors in [-1, 1).
     *
     * @return list<array{0: int, 1: int, 2: list<float>}>
     */
    protected static function randomRecords(int $count, int $dim, int $seed, int $refs = 0, int $firstId = 1): array
    {
        mt_srand($seed);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [$firstId + $i, $refs > 0 ? mt_rand(1, $refs) : 0, self::randomVector($dim)];
        }

        return $out;
    }

    /**
     * @return list<float>
     */
    protected static function randomVector(int $dim): array
    {
        $v = [];
        for ($j = 0; $j < $dim; $j++) {
            $v[] = mt_rand() / mt_getrandmax() * 2.0 - 1.0;
        }

        return $v;
    }

    /**
     * Cosine similarity in double precision, the reference for every score test.
     *
     * @param list<float|int> $a
     * @param list<float|int> $b
     */
    protected static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $x) {
            $dot += $x * $b[$i];
            $na += $x * $x;
            $nb += $b[$i] * $b[$i];
        }
        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }

        return max(-1.0, min(1.0, $dot / (sqrt($na) * sqrt($nb))));
    }

    /**
     * Overwrites bytes in a file, optionally recomputing the header checksum.
     */
    protected static function patch(string $path, int $offset, string $bytes, bool $fixHeaderCrc = false): void
    {
        $data = (string) file_get_contents($path);
        $data = substr_replace($data, $bytes, $offset, strlen($bytes));
        if ($fixHeaderCrc) {
            $data = substr_replace($data, pack('V', crc32(substr($data, 0, 32))), 32, 4);
        }
        file_put_contents($path, $data);
    }

    /**
     * Decodes a file independently of the library.
     *
     * @return array{header: array<string, int|string>, records: list<array{0: int, 1: int, 2: list<float>}>}
     */
    protected static function decodeFile(string $path): array
    {
        $data = (string) file_get_contents($path);
        /** @var array<string, int|string> $h */
        $h = unpack('a4magic/vversion/vflags/Vdim/Pcount/PbuiltAt/VpayloadCrc/VheaderCrc/Vreserved', $data);
        $dim = (int) $h['dim'];
        $size = 8 + 4 * $dim;
        $records = [];
        for ($off = 40; $off < strlen($data); $off += $size) {
            /** @var array{1: int, 2: int} $ids */
            $ids = unpack('V2', $data, $off);
            /** @var array<int, float> $vals */
            $vals = unpack('g' . $dim, $data, $off + 8);
            $records[] = [$ids[1], $ids[2], array_values($vals)];
        }

        return ['header' => $h, 'records' => $records];
    }

    /**
     * Rounds a double to float32 and back.
     */
    protected static function f32(float $v): float
    {
        /** @var array{1: float} $u */
        $u = unpack('g', pack('g', $v));

        return $u[1];
    }

    /**
     * @return list<string>
     */
    protected function listing(?string $dir = null): array
    {
        $entries = scandir($dir ?? $this->dir);
        self::assertIsArray($entries);

        return array_values(array_diff($entries, ['.', '..']));
    }

    protected static function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    protected static function removeTree(string $dir): void
    {
        chmod($dir, 0700);
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full) && !is_link($full)) {
                self::removeTree($full);
            } else {
                unlink($full);
            }
        }
        rmdir($dir);
    }
}
