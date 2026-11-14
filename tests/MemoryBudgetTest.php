<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests;

use Generator;
use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\ChunkedScanner;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PackedVectors\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Section 9 memory budgets (acceptance 14 and 49, and the scan budget).
 * Each runs in its own process so earlier tests do not pollute the peak.
 * The 100,000-record file is about 205 MB; set TMPDIR to a disk with room.
 */
#[Group('slow')]
final class MemoryBudgetTest extends TestCase
{
    private const RECORDS = 100000;
    private const DIM = 512;
    private const BUDGET = 16 * 1024 * 1024;

    /** Acceptance 14 (case 14). */
    #[RunInSeparateProcess]
    public function testBuildMemoryIsBounded(): void
    {
        $path = $this->path();
        $added = self::measure(function () use ($path): void {
            $report = (new Builder($path, self::DIM, self::opts()))->build(self::source(self::RECORDS));
            self::assertSame(self::RECORDS, $report->written());
        });
        self::assertSame(40 + self::RECORDS * (8 + 4 * self::DIM), filesize($path));
        self::assertLessThan(self::BUDGET, $added, sprintf('build added %.1f MiB', $added / 1048576));
    }

    /** Section 9: scan memory, and 100,000 x 512 under memory_limit = 64M. */
    #[RunInSeparateProcess]
    public function testScanMemoryIsBoundedAndFitsIn64M(): void
    {
        $path = $this->path();
        (new Builder($path, self::DIM, self::opts()))->build(self::source(self::RECORDS));
        gc_collect_cycles();

        ini_set('memory_limit', '64M');
        $query = self::vector(12345);
        $result = null;
        $added = self::measure(function () use ($path, $query, &$result): void {
            $result = (new Scanner($path))->top($query, 10);
        });
        self::assertNotNull($result);
        self::assertSame(self::RECORDS, $result->report()->scanned());
        self::assertContains(12345, array_map(static fn ($h): int => $h->id, $result->hits()));
        self::assertGreaterThan(0.99, $result->hits()[0]->score);
        self::assertLessThan(self::BUDGET, $added, sprintf('scan added %.1f MiB', $added / 1048576));
    }

    /** Acceptance 49 (case 51). */
    #[RunInSeparateProcess]
    public function testFallbackMemoryIsOnePagePlusTopK(): void
    {
        $fetch = static function (int $after, int $limit): array {
            $page = [];
            for ($id = $after + 1; $id <= min($after + $limit, self::RECORDS); $id++) {
                $page[] = [$id, 0, self::vector($id)];
            }

            return $page;
        };
        $result = null;
        $added = self::measure(function () use ($fetch, &$result): void {
            $result = (new ChunkedScanner($fetch, self::DIM))->top(self::vector(777), 10);
        });
        self::assertNotNull($result);
        self::assertSame(self::RECORDS, $result->report()->scanned());
        self::assertContains(777, array_map(static fn ($h): int => $h->id, $result->hits()));
        self::assertLessThan(self::BUDGET, $added, sprintf('fallback added %.1f MiB', $added / 1048576));
    }

    /**
     * Peak memory (real allocation, as section 9 specifies) added by $work.
     */
    private static function measure(callable $work): int
    {
        gc_collect_cycles();
        $before = memory_get_usage(true);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $work();

        return memory_get_peak_usage(true) - $before;
    }

    /**
     * @return Generator<int, array{0: int, 1: int, 2: list<float>}>
     */
    private static function source(int $count): Generator
    {
        for ($id = 1; $id <= $count; $id++) {
            yield [$id, $id % 97, self::vector($id)];
        }
    }

    /**
     * A cheap distinct vector per id: a fixed pattern plus a spike whose
     * position and size depend on the id.
     *
     * @return list<float>
     */
    private static function vector(int $id): array
    {
        static $base = null;
        if ($base === null) {
            $base = [];
            for ($j = 0; $j < self::DIM; $j++) {
                $base[] = 0.001 * (($j * 7919) % 13);
            }
        }
        $v = $base;
        $v[$id % self::DIM] = 5.0 + ($id % 1000);
        $v[($id * 31) % self::DIM] += 3.0;

        return $v;
    }
}
