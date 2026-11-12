<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests;

use Generator;
use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\BuildOptions;
use IndianOpinion\PackedVectors\Exception\BuildFailedException;
use IndianOpinion\PackedVectors\Exception\DirectoryNotWritableException;
use IndianOpinion\PackedVectors\Exception\InvalidArgumentException;
use IndianOpinion\PackedVectors\Exception\InvalidVectorException;
use IndianOpinion\PackedVectors\Record;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PackedVectors\Tests\Support\FaultyWriteStream;
use IndianOpinion\PackedVectors\Tests\Support\Flag;
use IndianOpinion\PackedVectors\Tests\Support\SeamBuilder;
use IndianOpinion\PackedVectors\Tests\Support\TestCase;
use RuntimeException;

/**
 * Building: PRD cases 6 to 22 and 25 to 27.
 */
final class BuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        FaultyWriteStream::unregister();
        FaultyWriteStream::$limit = PHP_INT_MAX;
        parent::tearDown();
    }

    /** Acceptance 6 (case 6). */
    public function testAcceptsRecordObjectsAndArrays(): void
    {
        $path = $this->path();
        $report = $this->buildFile($path, 2, [
            new Record(1, 10, [1.0, 0.0]),
            [2, 20, [0, 1]],
            new Record(3, 30, [0.5, 0.5]),
        ]);
        self::assertSame(3, $report->written());
        $records = self::decodeFile($path)['records'];
        self::assertSame([1, 2, 3], array_column($records, 0));
        self::assertSame([10, 20, 30], array_column($records, 1));
    }

    public function testAcceptsIteratorAggregate(): void
    {
        $source = new \ArrayObject([[1, 0, [1, 2]], [2, 0, [3, 4]]]);
        self::assertSame(2, $this->buildFile($this->path(), 2, $source)->written());
    }

    public function testMalformedItemIsInvalidArgumentAndLeavesNoTemporary(): void
    {
        $path = $this->path();
        try {
            $this->buildFile($path, 2, [[1, 0, [1, 2]], 'not a record']);
            self::fail('accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('record 1', $e->getMessage());
        }
        self::assertFileDoesNotExist($path);
        self::assertSame(['vectors.bin.lock'], $this->listing());
    }

    /** Acceptance 7 (case 7). */
    public function testTemporaryFileIsSiblingAndNeverFollowsSymlink(): void
    {
        $path = $this->path();
        $builder = new SeamBuilder($path, 2, self::opts());
        $seen = [];
        $builder->build((function () use (&$seen): Generator {
            $seen = $this->listing();
            yield [1, 0, [1, 0]];
        })());
        self::assertCount(1, $builder->temporaryPaths);
        $tmp = $builder->temporaryPaths[0];
        self::assertSame($this->dir, dirname($tmp));
        self::assertMatchesRegularExpression('/^vectors\.bin\.[0-9a-f]{16}\.tmp$/', basename($tmp));
        self::assertContains(basename($tmp), $seen);
        self::assertFileDoesNotExist($tmp);

        // A pre-existing symlink at the temporary name is never followed.
        $victim = $this->path('victim.txt');
        file_put_contents($victim, 'keep me');
        $forced = $path . '.00000000deadbeef.tmp';
        symlink($victim, $forced);
        $builder = new SeamBuilder($path, 2, self::opts());
        $builder->forcedTemporaryPath = $forced;
        $before = (string) file_get_contents($path);
        try {
            $builder->build([[1, 0, [1, 0]]]);
            self::fail('built through a symlink');
        } catch (BuildFailedException $e) {
            self::assertStringContainsString('temporary', $e->getMessage());
        }
        self::assertSame('keep me', file_get_contents($victim));
        self::assertSame($before, file_get_contents($path));
        self::assertTrue(is_link($forced), 'the attacker symlink is not ours to remove');
    }

    /** Acceptance 8 (case 8). */
    public function testModeIsSetBeforeFirstRecord(): void
    {
        $path = $this->path();
        $modes = [];
        $source = function () use (&$modes): Generator {
            foreach (glob($this->dir . '/*.tmp') ?: [] as $tmp) {
                clearstatcache(true, $tmp);
                $modes[] = fileperms($tmp) & 0777;
            }
            yield [1, 0, [1, 0]];
        };
        $old = umask(0);
        try {
            $this->buildFile($path, 2, $source());
        } finally {
            umask($old);
        }
        self::assertSame([0640], $modes);
        self::assertSame(0640, fileperms($path) & 0777);

        $this->buildFile($path, 2, [[1, 0, [1, 0]]], self::opts()->withFileMode(0600));
        clearstatcache();
        self::assertSame(0600, fileperms($path) & 0777);
    }

    /** Acceptance 10 (case 10). */
    public function testShortWriteFailsAndKeepsOldFile(): void
    {
        $path = $this->path();
        $this->buildFile($path, 8, self::randomRecords(50, 8, 4));
        $old = (string) file_get_contents($path);

        FaultyWriteStream::register();
        FaultyWriteStream::$limit = 1000;
        $builder = new SeamBuilder($path, 8, self::opts()->withWriteBufferBytes(256));
        $builder->faultyWrites = true;
        try {
            $builder->build(self::randomRecords(200, 8, 5));
            self::fail('a short write was not noticed');
        } catch (BuildFailedException $e) {
            self::assertSame($path, $e->path);
            self::assertNotNull($e->expectedBytes);
            self::assertSame(1000, $e->writtenBytes);
            self::assertGreaterThan(1000, $e->expectedBytes);
            self::assertStringContainsString('expected to write', $e->getMessage());
        }
        self::assertSame($old, file_get_contents($path));
        self::assertSame([], glob($this->dir . '/*.tmp'));
    }

    /** Acceptance 11 (case 11). */
    public function testFailingRenameRemovesTemporary(): void
    {
        $path = $this->path('target');
        mkdir($path);
        file_put_contents($path . '/occupant', 'x');
        try {
            $this->buildFile($path, 2, [[1, 0, [1, 0]]]);
            self::fail('renamed over a directory');
        } catch (BuildFailedException $e) {
            self::assertStringContainsString('rename', $e->getMessage());
        }
        self::assertDirectoryExists($path);
        self::assertSame([], glob($this->dir . '/*.tmp'));
    }

    /** Acceptance 12 (case 12): missing directory. */
    public function testMissingDirectoryFailsBeforeSource(): void
    {
        $advanced = new Flag();
        $source = (function () use ($advanced): Generator {
            $advanced->set = true;
            yield [1, 0, [1, 0]];
        })();
        try {
            $this->buildFile($this->dir . '/nope/vectors.bin', 2, $source);
            self::fail('built into a missing directory');
        } catch (DirectoryNotWritableException $e) {
            self::assertSame($this->dir . '/nope', $e->directory);
            self::assertInstanceOf(BuildFailedException::class, $e);
        }
        self::assertFalse($advanced->set);
        self::assertSame([], glob(sys_get_temp_dir() . '/vectors.bin*'));
    }

    /** Acceptance 12 (case 12): read-only directory. */
    public function testReadOnlyDirectoryFailsBeforeSource(): void
    {
        if (self::isRoot()) {
            self::markTestSkipped('root can write to a read-only directory');
        }
        $ro = $this->dir . '/ro';
        mkdir($ro, 0555);
        $advanced = new Flag();
        $source = (function () use ($advanced): Generator {
            $advanced->set = true;
            yield [1, 0, [1, 0]];
        })();
        try {
            $this->buildFile($ro . '/vectors.bin', 2, $source);
            self::fail('built into a read-only directory');
        } catch (DirectoryNotWritableException $e) {
            self::assertStringContainsString('not writable', $e->getMessage());
        }
        self::assertFalse($advanced->set);
        self::assertSame([], $this->listing($ro));
    }

    /** Acceptance 13 (case 13). */
    public function testThrowingSourceIsWrappedAndOldFileKept(): void
    {
        $path = $this->path();
        $this->buildFile($path, 2, [[1, 0, [1, 0]]]);
        $old = (string) file_get_contents($path);
        $boom = new RuntimeException('database went away');
        $source = (function () use ($boom): Generator {
            yield [1, 0, [0, 1]];
            yield [2, 0, [0, 1]];
            throw $boom;
        })();
        try {
            $this->buildFile($path, 2, $source);
            self::fail('source failure ignored');
        } catch (BuildFailedException $e) {
            self::assertSame($boom, $e->getPrevious());
            self::assertStringContainsString('database went away', $e->getMessage());
        }
        self::assertSame($old, file_get_contents($path));
        self::assertSame([], glob($this->dir . '/*.tmp'));
    }

    /** Acceptance 15 (case 15). */
    public function testWrongLengthIsSkipped(): void
    {
        $records = [[1, 0, array_fill(0, 511, 0.1)], [2, 0, array_fill(0, 512, 0.1)], [3, 0, array_fill(0, 513, 0.1)]];
        $report = $this->buildFile($this->path(), 512, $records);
        self::assertSame(1, $report->written());
        self::assertSame(2, $report->skipped()['length']);
    }

    /** Acceptance 16 (case 16). */
    public function testNonFiniteAndOutOfRangeAreSkippedSubnormalAccepted(): void
    {
        $path = $this->path();
        $report = $this->buildFile($path, 2, [
            [1, 0, [NAN, 1.0]],
            [2, 0, [INF, 1.0]],
            [3, 0, [1.0, -INF]],
            [4, 0, [1e39, 1.0]],
            [5, 0, [1.0, -1e39]],
            [6, 0, [1e-40, 0.0]],
            [7, 0, [3.4e38, 1.0]],
        ], self::opts()->withNormalise(false));
        self::assertSame(3, $report->skipped()['non_finite']);
        self::assertSame(2, $report->skipped()['out_of_range']);
        self::assertSame(2, $report->written());
        $records = self::decodeFile($path)['records'];
        self::assertSame([6, 7], array_column($records, 0));
        self::assertGreaterThan(0.0, $records[0][2][0]);
        self::assertEqualsWithDelta(1e-40, $records[0][2][0], 1e-44);
    }

    public function testNonNumericAndNonListVectorsAreSkipped(): void
    {
        $report = $this->buildFile($this->path(), 2, [
            [1, 0, ['1', 2]],
            [2, 0, [1 => 1.0, 2 => 2.0]],
            [3, 0, '[1, 2]'],
            [4, 0, [true, 1.0]],
            [5, 0, [1, 2]],
        ]);
        self::assertSame(2, $report->skipped()['not_numeric']);
        self::assertSame(2, $report->skipped()['not_list']);
        self::assertSame(1, $report->written());
    }

    /** Acceptance 17 (case 17). */
    public function testBadIdsAreSkippedAndMaxIdRoundTrips(): void
    {
        $path = $this->path();
        $report = $this->buildFile($path, 2, [
            [-1, 0, [1, 0]],
            [4294967296, 0, [1, 0]],
            ['5', 0, [1, 0]],
            [1.0, 0, [1, 0]],
            [6, -1, [1, 0]],
            [7, 4294967296, [1, 0]],
            [4294967295, 4294967295, [1, 0]],
        ]);
        self::assertSame(6, $report->skipped()['bad_id']);
        self::assertSame(1, $report->written());
        $records = self::decodeFile($path)['records'];
        self::assertSame(4294967295, $records[0][0]);
        self::assertSame(4294967295, $records[0][1]);
        $hit = (new Scanner($path))->top([1, 0], 1)->hits()[0];
        self::assertSame(4294967295, $hit->id);
        self::assertSame(4294967295, $hit->ref);
    }

    /** Acceptance 18 (case 18). */
    public function testZeroVectorSkippedWhenNormalisingAcceptedOtherwise(): void
    {
        $report = $this->buildFile($this->path('a.bin'), 3, [[1, 0, [0, 0, 0]], [2, 0, [0.0, -0.0, 0]]]);
        self::assertSame(2, $report->skipped()['zero_norm']);
        self::assertSame(0, $report->written());

        $report = $this->buildFile($this->path('b.bin'), 3, [[1, 0, [0, 0, 0]]], self::opts()->withNormalise(false));
        self::assertSame(1, $report->written());
        self::assertSame(0, $report->skipped()['zero_norm']);
    }

    /** Acceptance 19 (case 19). */
    public function testThrowPolicyAbortsWithIndexAndId(): void
    {
        $path = $this->path();
        $this->buildFile($path, 2, [[1, 0, [1, 0]]]);
        $old = (string) file_get_contents($path);
        try {
            $this->buildFile(
                $path,
                2,
                [[1, 0, [1, 0]], [2, 0, [1, 0]], [77, 0, [NAN, 0]], [78, 0, [0, 0, 0]]],
                self::opts()->withOnBadRecord(BuildOptions::THROW)
            );
            self::fail('bad record accepted');
        } catch (InvalidVectorException $e) {
            self::assertSame(2, $e->index);
            self::assertSame(77, $e->id);
            self::assertSame('non_finite', $e->reason);
        }
        self::assertSame($old, file_get_contents($path));
        self::assertSame([], glob($this->dir . '/*.tmp'));
    }

    /** Acceptance 20 (case 20). */
    public function testNormalisedVectorsHaveUnitNorm(): void
    {
        $path = $this->path();
        $records = self::randomRecords(200, 33, 6);
        $records[] = [999, 0, array_fill(0, 33, 3.0e38)];
        $records[] = [1000, 0, array_merge([1e-30], array_fill(0, 32, 0.0))];
        $this->buildFile($path, 33, $records);
        $decoded = self::decodeFile($path);
        self::assertSame(1, $decoded['header']['flags']);
        self::assertCount(202, $decoded['records']);
        foreach ($decoded['records'] as [$id, , $v]) {
            $n = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $v)));
            self::assertEqualsWithDelta(1.0, $n, 1e-6, 'record ' . $id);
        }
    }

    /** Acceptance 21 (case 21). */
    public function testUnnormalisedValuesAreFloat32RoundedInput(): void
    {
        $path = $this->path();
        $records = self::randomRecords(20, 6, 7);
        foreach ($records as $i => $r) {
            $records[$i][2] = array_map(static fn (float $x): float => $x * 1000.0, $r[2]);
        }
        $this->buildFile($path, 6, $records, self::opts()->withNormalise(false));
        $decoded = self::decodeFile($path);
        self::assertSame(0, $decoded['header']['flags']);
        foreach ($decoded['records'] as $i => [, , $v]) {
            foreach ($v as $j => $x) {
                self::assertSame(self::f32($records[$i][2][$j]), $x);
            }
        }
    }

    /** Acceptance 24 (case 25). */
    public function testStaleTemporaryCleanup(): void
    {
        $path = $this->path();
        $stale = $path . '.0123456789abcdef.tmp';
        $young = $path . '.fedcba9876543210.tmp';
        $unrelated = $this->path('vectors.bin.backup.tmp');
        $otherTarget = $this->path('other.bin.0123456789abcdef.tmp');
        $staleDir = $path . '.aaaaaaaaaaaaaaaa.tmp';
        foreach ([$stale, $young, $unrelated, $otherTarget] as $f) {
            file_put_contents($f, 'x');
        }
        mkdir($staleDir);
        $old = time() - 7200;
        foreach ([$stale, $unrelated, $otherTarget, $staleDir] as $f) {
            touch($f, $old);
        }

        $report = $this->buildFile($path, 2, [[1, 0, [1, 0]]]);
        self::assertSame(1, $report->staleTempsRemoved());
        self::assertFileDoesNotExist($stale);
        self::assertFileExists($young);
        self::assertFileExists($unrelated);
        self::assertFileExists($otherTarget);
        self::assertDirectoryExists($staleDir);

        // With a shorter age the younger one goes too.
        touch($young, time() - 10);
        $this->buildFile($path, 2, [[1, 0, [1, 0]]], self::opts()->withStaleTempAgeSeconds(5));
        clearstatcache();
        self::assertFileDoesNotExist($young);
    }

    /** Acceptance 25 (case 26). */
    public function testReportCountsAddUp(): void
    {
        $yielded = new Flag();
        $source = (function () use ($yielded): Generator {
            mt_srand(8);
            for ($i = 1; $i <= 500; $i++) {
                $yielded->count++;
                $v = self::randomVector(4);
                $kind = $i % 7;
                if ($kind === 1) {
                    $v[] = 1.0;
                } elseif ($kind === 2) {
                    $v[0] = NAN;
                } elseif ($kind === 3) {
                    $v = [0, 0, 0, 0];
                } elseif ($kind === 4) {
                    $v[1] = 1e39;
                }
                yield [$kind === 5 ? -$i : $i, 0, $v];
            }
        })();
        $report = $this->buildFile($this->path(), 4, $source);
        self::assertSame(500, $yielded->count);
        self::assertSame($yielded->count, $report->written() + array_sum($report->skipped()));
        self::assertSame($report->skippedTotal(), array_sum($report->skipped()));
        foreach (['length', 'non_finite', 'zero_norm', 'out_of_range', 'bad_id'] as $reason) {
            self::assertGreaterThan(0, $report->skipped()[$reason], $reason);
        }
        self::assertSame(40 + $report->written() * 24, $report->bytes());
        self::assertSame(4, $report->dim());
        self::assertTrue($report->normalised());
        self::assertFalse($report->skippedForLock());
        self::assertGreaterThan(0.0, $report->seconds());
        self::assertStringContainsString('written=' . $report->written(), (string) $report);
    }

    /** Acceptance 26 (case 27, and the ruling that a dim change forces a rebuild). */
    public function testRebuildIfStale(): void
    {
        $path = $this->path();
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;

            return [[1, 0, [1, 0]]];
        };
        $builder = new Builder($path, 2, self::opts());

        // Missing.
        self::assertNotNull($builder->rebuildIfStale(3600, $factory));
        self::assertSame(1, $calls);

        // Fresh: factory not called.
        self::assertNull($builder->rebuildIfStale(3600, $factory));
        self::assertSame(1, $calls);

        // Corrupt header.
        self::patch($path, 0, 'JUNK');
        self::assertNotNull($builder->rebuildIfStale(3600, $factory));
        self::assertSame(2, $calls);

        // Old built_at.
        self::patch($path, 20, pack('P', time() - 7200), true);
        self::assertNotNull($builder->rebuildIfStale(3600, $factory));
        self::assertSame(3, $calls);
        self::assertNull($builder->staleReason(3600));

        // Built for another dim.
        $this->buildFile($path, 3, [[1, 0, [1, 0, 0]]]);
        self::assertStringContainsString('dim 3', (string) $builder->staleReason(3600));
        self::assertNotNull($builder->rebuildIfStale(3600, $factory));
        self::assertSame(4, $calls);
        self::assertSame(2, (new Scanner($path))->info()->dim);

        // Built with the other normalisation setting.
        $this->buildFile($path, 2, [[1, 0, [1, 0]]], self::opts()->withNormalise(false));
        self::assertNotNull($builder->rebuildIfStale(3600, $factory));
        self::assertSame(5, $calls);
    }

    public function testRebuildIfStaleRejectsBadFactoryAndAge(): void
    {
        $builder = new Builder($this->path(), 2, self::opts());
        try {
            $builder->rebuildIfStale(-1, static fn (): array => []);
            self::fail('negative age accepted');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore argument.type */
        $builder->rebuildIfStale(10, static fn (): string => 'nope');
    }

    public function testConstructorValidates(): void
    {
        foreach (
            [
                static fn () => new Builder('', 2),
                static fn () => new Builder("a\0b", 2),
                static fn () => new Builder('/tmp/', 2),
                static fn () => new Builder('/tmp/x', 0),
                static fn () => new Builder('/tmp/x', 65536),
            ] as $i => $make
        ) {
            try {
                $make();
                self::fail('case ' . $i . ' accepted');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFsyncOnBuildWorks(): void
    {
        $path = $this->path();
        $report = (new Builder($path, 2))->build([[1, 0, [1, 0]]]);
        self::assertSame(1, $report->written());
        self::assertSame(0640, fileperms($path) & 0777);
    }
}
