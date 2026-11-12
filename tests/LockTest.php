<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests;

use Generator;
use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PackedVectors\Tests\Support\Child;
use IndianOpinion\PackedVectors\Tests\Support\Flag;
use IndianOpinion\PackedVectors\Tests\Support\TestCase;

/**
 * Builder lock: PRD cases 23, 24 and 58.
 */
final class LockTest extends TestCase
{
    /** Acceptance 22 (case 23): busy lock, no wait. */
    public function testBusyLockSkipsWithoutTouchingSource(): void
    {
        $path = $this->path();
        $other = fopen($path . '.lock', 'c');
        self::assertIsResource($other);
        self::assertTrue(flock($other, LOCK_EX | LOCK_NB));

        $touched = new Flag();
        $source = (function () use ($touched): Generator {
            $touched->set = true;
            yield [1, 0, [1, 0]];
        })();
        $started = microtime(true);
        $report = (new Builder($path, 2, self::opts()))->build($source);
        self::assertLessThan(0.5, microtime(true) - $started);
        self::assertTrue($report->skippedForLock());
        self::assertSame(0, $report->written());
        self::assertFalse($touched->set);
        self::assertFileDoesNotExist($path);
        self::assertStringContainsString('lock busy', (string) $report);

        $calls = 0;
        $result = (new Builder($path, 2, self::opts()))->rebuildIfStale(60, function () use (&$calls): array {
            $calls++;

            return [];
        });
        self::assertNotNull($result);
        self::assertTrue($result->skippedForLock());
        self::assertSame(0, $calls, 'the factory must not run while another builder holds the lock');

        flock($other, LOCK_UN);
        fclose($other);
    }

    /** Acceptance 22 (case 23): with a wait timeout it builds once the lock is released. */
    public function testWaitTimeoutBuildsAfterRelease(): void
    {
        $path = $this->path();
        $child = new Child(__DIR__ . '/fixtures/hold-lock.php', [$path . '.lock', '0.4']);
        self::assertSame('locked', $child->readLine());

        $started = microtime(true);
        $report = (new Builder($path, 2, self::opts()->withLockWaitSeconds(10.0)))->build([[1, 0, [1, 0]]]);
        $waited = microtime(true) - $started;
        self::assertSame(0, $child->wait());

        self::assertFalse($report->skippedForLock());
        self::assertSame(1, $report->written());
        self::assertGreaterThan(0.1, $waited);
        self::assertLessThan(5.0, $waited);
    }

    public function testWaitTimeoutExpires(): void
    {
        $path = $this->path();
        $child = new Child(__DIR__ . '/fixtures/hold-lock.php', [$path . '.lock', '2']);
        self::assertSame('locked', $child->readLine());
        $started = microtime(true);
        $report = (new Builder($path, 2, self::opts()->withLockWaitSeconds(0.2)))->build([[1, 0, [1, 0]]]);
        $waited = microtime(true) - $started;
        self::assertTrue($report->skippedForLock());
        self::assertGreaterThanOrEqual(0.2, $waited);
        self::assertLessThan(1.5, $waited);
        $child->wait();
    }

    /** Acceptance 23 (case 24). */
    public function testScanIgnoresTheLock(): void
    {
        $path = $this->path();
        $this->buildFile($path, 2, [[1, 0, [1, 0]], [2, 0, [0, 1]]]);
        $other = fopen($path . '.lock', 'c');
        self::assertIsResource($other);
        self::assertTrue(flock($other, LOCK_EX | LOCK_NB));

        $hits = (new Scanner($path))->top([1, 0], 1)->hits();
        self::assertSame(1, $hits[0]->id);

        flock($other, LOCK_UN);
        fclose($other);
    }

    public function testLockFileIsLeftInPlaceAndIsNotTheDataFile(): void
    {
        $path = $this->path();
        $this->buildFile($path, 2, [[1, 0, [1, 0]]]);
        self::assertFileExists($path . '.lock');
        self::assertSame(0, filesize($path . '.lock'));
        self::assertSame(['vectors.bin', 'vectors.bin.lock'], $this->listing());
    }

    public function testLockOffCreatesNoLockFile(): void
    {
        $path = $this->path();
        $this->buildFile($path, 2, [[1, 0, [1, 0]]], self::opts()->withLock(false));
        self::assertSame(['vectors.bin'], $this->listing());
    }

    /** Acceptance 54 (case 58). */
    public function testTwoBuildersWithLockOffBothFinish(): void
    {
        $path = $this->path();
        $outer = self::randomRecords(30, 4, 21);
        $inner = self::randomRecords(30, 4, 22);
        $options = self::opts()->withLock(false);
        $innerBytes = '';
        $source = (function () use ($outer, $inner, $path, $options, &$innerBytes): Generator {
            foreach ($outer as $i => $record) {
                if ($i === 10) {
                    $report = (new Builder($path, 4, $options))->build($inner);
                    self::assertSame(30, $report->written());
                    $innerBytes = (string) file_get_contents($path);
                }
                yield $record;
            }
        })();
        $report = (new Builder($path, 4, $options))->build($source);
        self::assertSame(30, $report->written());

        $final = (string) file_get_contents($path);
        $outerFile = $this->path('outer-reference.bin');
        $this->buildFile($outerFile, 4, $outer, $options);
        $reference = (string) file_get_contents($outerFile);
        // Same records: identical except for built_at and the header checksum.
        self::assertSame(substr($reference, 40), substr($final, 40));
        self::assertNotSame(substr($innerBytes, 40), substr($final, 40));
        self::assertTrue((new Scanner($path))->verify()->ok(), 'final file does not verify');
        self::assertSame([], glob($this->dir . '/*.tmp'));
    }
}
