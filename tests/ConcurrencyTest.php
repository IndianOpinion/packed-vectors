<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests;

use Generator;
use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\Exception\FileMissingException;
use IndianOpinion\PackedVectors\Hit;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PackedVectors\ScanOptions;
use IndianOpinion\PackedVectors\Tests\Support\Child;
use IndianOpinion\PackedVectors\Tests\Support\SeamScanner;
use IndianOpinion\PackedVectors\Tests\Support\TestCase;

/**
 * A rebuild racing a scan: PRD cases 53 to 59.
 */
final class ConcurrencyTest extends TestCase
{
    /** Acceptance 51 (case 53). */
    public function testOpenScanKeepsReadingTheOldFile(): void
    {
        $path = $this->path();
        $dim = 6;
        $old = [];
        for ($i = 1; $i <= 40; $i++) {
            $old[] = [$i, 1, self::unit($dim, $i % $dim)];
        }
        $this->buildFile($path, $dim, $old);
        $oldBuiltAt = (new Scanner($path))->info()->builtAt;

        $child = new Child(__DIR__ . '/fixtures/pause-scan.php', [$path, (string) $dim]);
        self::assertSame('paused', $child->readLine());

        $new = [];
        for ($i = 100; $i < 160; $i++) {
            $new[] = [$i, 2, self::unit($dim, $i % $dim)];
        }
        $this->buildFile($path, $dim, $new);
        self::assertSame(60, (new Scanner($path))->info()->count);

        $child->send('go');
        /** @var array{hits: list<array{0: int, 1: int}>, scanned: int, verified: bool, built_at: int} $out */
        $out = json_decode($child->readLine(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $child->wait());

        self::assertSame(40, $out['scanned']);
        self::assertTrue($out['verified']);
        self::assertSame($oldBuiltAt, $out['built_at']);
        self::assertCount(40, $out['hits']);
        foreach ($out['hits'] as [$id, $ref]) {
            self::assertSame(1, $ref);
            self::assertLessThanOrEqual(40, $id);
        }
    }

    /** Acceptance 52 (cases 54, 55, 56). */
    public function testScansDuringFiftyRebuildsNeverSeeATornFile(): void
    {
        $path = $this->path();
        $dim = 8;
        $count = 300;
        $records = [];
        for ($i = 1; $i <= $count; $i++) {
            $records[] = [$i, 0, self::unit($dim, $i % $dim)];
        }
        $this->buildFile($path, $dim, $records);

        $child = new Child(__DIR__ . '/fixtures/rebuild-loop.php', [$path, (string) $dim, '50', (string) $count]);
        $scanner = new Scanner($path, new ScanOptions(chunkBytes: 2048));
        $scans = 0;
        $generations = [];
        $deadline = microtime(true) + 60;
        do {
            $result = $scanner->top(array_fill(0, $dim, 1.0), $count);
            $refs = array_unique(array_map(static fn (Hit $h): int => $h->ref, $result->hits()));
            self::assertCount(1, $refs, 'one scan saw records from two builds');
            self::assertCount($count, $result->hits());
            self::assertTrue($result->report()->checksumVerified());
            $generations[reset($refs)] = true;
            $scans++;
        } while ($child->running() && microtime(true) < $deadline);
        self::assertSame('done', $child->readLine());
        self::assertSame(0, $child->wait());

        self::assertGreaterThan(1, $scans);
        self::assertGreaterThan(1, count($generations), 'scans only ever saw one generation');
        self::assertSame(50, $scanner->top(array_fill(0, $dim, 1.0), 1)->hits()[0]->ref);
        self::assertSame([], glob($this->dir . '/*.tmp'));
        self::assertSame(['vectors.bin', 'vectors.bin.lock'], $this->listing());
    }

    /** Acceptance 53 (case 57). */
    public function testScanOfMissingTargetDuringBuildIsFileMissing(): void
    {
        $path = $this->path();
        $seen = null;
        $tmpSeen = false;
        $source = (function () use ($path, &$seen, &$tmpSeen): Generator {
            yield [1, 0, [1, 0]];
            $tmpSeen = glob($this->dir . '/*.tmp') !== [];
            try {
                (new Scanner($path))->top([1, 0], 1);
            } catch (FileMissingException $e) {
                $seen = $e;
            }
            yield [2, 0, [0, 1]];
        })();
        (new Builder($path, 2, self::opts()))->build($source);
        self::assertTrue($tmpSeen);
        self::assertInstanceOf(FileMissingException::class, $seen);
        self::assertCount(2, (new Scanner($path))->top([1, 0], 5)->hits());
    }

    /** Acceptance 55 (case 59). */
    public function testUnlinkingTargetMidScanDoesNotAffectIt(): void
    {
        $path = $this->path();
        $records = self::randomRecords(200, 5, 60);
        $this->buildFile($path, 5, $records);
        $query = $records[17][2];
        $expected = (new Scanner($path))->top($query, 10)->hits();

        $scanner = new SeamScanner($path, new ScanOptions(chunkBytes: 100));
        $scanner->onChunk = static function (int $done) use ($path): void {
            if ($done === 3 && file_exists($path)) {
                unlink($path);
            }
        };
        $result = $scanner->top($query, 10);
        self::assertFileDoesNotExist($path);
        self::assertEquals($expected, $result->hits());
        self::assertSame(200, $result->report()->scanned());
        self::assertTrue($result->report()->checksumVerified());
    }

    /**
     * @return list<float>
     */
    private static function unit(int $dim, int $slot): array
    {
        $v = [];
        for ($j = 0; $j < $dim; $j++) {
            $v[] = $j === $slot ? 1.0 : 0.0;
        }

        return $v;
    }
}
