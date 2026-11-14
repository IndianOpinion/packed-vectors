<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests;

use Generator;
use IndianOpinion\PackedVectors\ChunkedScanner;
use IndianOpinion\PackedVectors\Exception\BudgetExceededException;
use IndianOpinion\PackedVectors\Exception\DimensionMismatchException;
use IndianOpinion\PackedVectors\Exception\InvalidArgumentException;
use IndianOpinion\PackedVectors\Exception\InvalidVectorException;
use IndianOpinion\PackedVectors\Hit;
use IndianOpinion\PackedVectors\Record;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PackedVectors\ScanOptions;
use IndianOpinion\PackedVectors\Tests\Support\TestCase;

/**
 * The fallback chunked scanner: PRD cases 47 to 52.
 */
final class ChunkedScannerTest extends TestCase
{
    /**
     * An in-memory table with keyset pagination, recording each call.
     *
     * @param list<array{0: int, 1: int, 2: mixed}> $rows sorted by id
     * @param list<array{0: int, 1: int}> $calls
     * @return callable(int, int): list<array{0: int, 1: int, 2: mixed}>
     */
    private static function table(array $rows, array &$calls): callable
    {
        return static function (int $afterId, int $limit) use ($rows, &$calls): array {
            $calls[] = [$afterId, $limit];
            $page = [];
            foreach ($rows as $row) {
                if ($row[0] > $afterId) {
                    $page[] = $row;
                    if (count($page) === $limit) {
                        break;
                    }
                }
            }

            return $page;
        };
    }

    /** Acceptance 46 (cases 47, 48). */
    public function testKeysetPagingCallsAndStops(): void
    {
        $rows = self::randomRecords(1001, 4, 50);
        $calls = [];
        $scanner = new ChunkedScanner(self::table($rows, $calls), 4);
        $result = $scanner->top([1, 1, 1, 1], 5);
        self::assertSame([[0, 400], [400, 400], [800, 400]], $calls);
        self::assertSame(1001, $result->report()->scanned());
        self::assertCount(5, $result->hits());
    }

    public function testExactMultipleOfPageSizeNeedsOneEmptyPage(): void
    {
        $rows = self::randomRecords(800, 4, 51);
        $calls = [];
        (new ChunkedScanner(self::table($rows, $calls), 4))->top([1, 1, 1, 1], 5);
        self::assertSame([[0, 400], [400, 400], [800, 400]], $calls);
    }

    public function testCustomPageSizeAndGeneratorPages(): void
    {
        $rows = self::randomRecords(25, 3, 52);
        $calls = 0;
        $fetch = static function (int $after, int $limit) use ($rows, &$calls): Generator {
            $calls++;
            $n = 0;
            foreach ($rows as [$id, $ref, $v]) {
                if ($id > $after && $n < $limit) {
                    $n++;
                    yield new Record($id, $ref, $v);
                }
            }
        };
        $result = (new ChunkedScanner($fetch, 3, null, 10))->top([1, 0, 0], 3);
        self::assertSame(3, $calls);
        self::assertSame(25, $result->report()->scanned());
    }

    /** Acceptance 47 (case 49). */
    public function testSameHitsAsPackedScan(): void
    {
        $rows = self::randomRecords(700, 16, 53, 40);
        $path = $this->path();
        $this->buildFile($path, 16, $rows);
        mt_srand(54);
        $query = self::randomVector(16);
        $calls = [];
        foreach (
            [
                new ScanOptions(),
                new ScanOptions(bestPerRef: true),
                new ScanOptions(minScore: 0.2),
                new ScanOptions(minScore: 0.1, bestPerRef: true),
            ] as $options
        ) {
            $packed = (new Scanner($path, $options))->top($query, 25)->hits();
            $fallback = (new ChunkedScanner(self::table($rows, $calls), 16, $options))->top($query, 25)->hits();
            self::assertSame(
                array_map(static fn (Hit $h): string => $h->id . '/' . $h->ref, $packed),
                array_map(static fn (Hit $h): string => $h->id . '/' . $h->ref, $fallback)
            );
            foreach ($packed as $i => $hit) {
                self::assertEqualsWithDelta($hit->score, $fallback[$i]->score, 1e-5);
            }
        }
    }

    public function testReportShape(): void
    {
        $calls = [];
        $report = (new ChunkedScanner(self::table(self::randomRecords(10, 2, 55), $calls), 2))
            ->top([1, 0], 3)->report();
        self::assertSame(10, $report->scanned());
        self::assertSame(0, $report->skipped());
        self::assertSame(0, $report->bytesRead());
        self::assertNull($report->builtAt());
        self::assertFalse($report->normalised());
        self::assertFalse($report->checksumVerified());
        self::assertSame(2, $report->dim());
    }

    /** Acceptance 48 (case 50). */
    public function testBadRowsAreSkippedAndCounted(): void
    {
        $rows = [
            [1, 0, [1.0, 0.0, 0.0]],
            [2, 0, [1.0, 0.0]],
            [3, 0, [NAN, 1.0, 0.0]],
            [4, 0, [0.0, 0.0, 0.0]],
            [5, 0, '[0.9, 0.1, 0.0]'],
            [6, 0, [0.9, 0.1, 0.0]],
            [7, 0, [0.0, 1.0, 0.0]],
        ];
        $calls = [];
        $result = (new ChunkedScanner(self::table($rows, $calls), 3))->top([1, 0, 0], 3);
        self::assertSame(4, $result->report()->skipped());
        self::assertSame(7, $result->report()->scanned());
        self::assertSame([1, 6, 7], array_map(static fn (Hit $h): int => $h->id, $result->hits()));
        self::assertSame(1.0, $result->hits()[0]->score);
    }

    /** Acceptance 49 (case 51): see MemoryBudgetTest::testFallbackMemory. */
    public function testPageIsNotKeptAfterScoring(): void
    {
        $alive = [];
        $fetch = static function (int $after, int $limit) use (&$alive): array {
            if ($after >= 1000) {
                return [];
            }
            $page = [];
            for ($i = 1; $i <= $limit; $i++) {
                $page[] = new Record($after + $i, 0, [1.0, (float) $i]);
            }
            $alive[] = \WeakReference::create($page[0]);

            return $page;
        };
        (new ChunkedScanner($fetch, 2, null, 100))->top([1, 0], 3);
        // Every page except possibly the last one has been released.
        $live = count(array_filter($alive, static fn (\WeakReference $w): bool => $w->get() !== null));
        self::assertLessThanOrEqual(1, $live);
    }

    /** Acceptance 50 (case 52). */
    public function testNonIncreasingIdsAreRejected(): void
    {
        foreach (
            [
                'repeats the last id' => static fn (int $after, int $limit): array => [[$after, 0, [1, 0]]],
                'goes backwards' => static fn (int $after, int $limit): array => [[2, 0, [1, 0]], [1, 0, [1, 0]]],
                'returns id 0' => static fn (int $after, int $limit): array => [[0, 0, [1, 0]]],
                'string id' => static fn (int $after, int $limit): array => [['5', 0, [1, 0]]],
                'too many rows' => static fn (int $after, int $limit): array => [[1, 0, [1, 0]], [2, 0, [1, 0]]],
                'not iterable' => static fn (int $after, int $limit): string => 'rows',
                'not a record' => static fn (int $after, int $limit): array => [new \stdClass()],
            ] as $label => $fetch
        ) {
            $pageSize = $label === 'too many rows' ? 1 : 400;
            try {
                /** @phpstan-ignore argument.type (deliberately broken callbacks) */
                (new ChunkedScanner($fetch, 2, null, $pageSize))->top([1, 0], 3);
                self::fail($label . ' accepted');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testQueryChecksMatchThePackedScan(): void
    {
        $calls = [];
        $scanner = new ChunkedScanner(self::table([], $calls), 3);
        try {
            $scanner->top([1 => 1.0, 2 => 0.0, 3 => 0.0], 1);
            self::fail('not-a-list accepted');
        } catch (InvalidVectorException $e) {
            self::assertSame('not_list', $e->reason);
        }
        try {
            $scanner->top([0, 0, 0], 1);
            self::fail('zero query accepted');
        } catch (InvalidVectorException $e) {
            self::assertSame('zero_norm', $e->reason);
        }
        try {
            $scanner->top([1, 0], 1);
            self::fail('short query accepted');
        } catch (DimensionMismatchException $e) {
            self::assertSame(3, $e->expected);
            self::assertSame(2, $e->given);
        }
        self::assertSame([], $calls, 'no page is fetched for a bad query');
        $this->expectException(InvalidArgumentException::class);
        $scanner->top([1, 0, 0], 0);
    }

    public function testTimeBudget(): void
    {
        $fetch = static function (int $after, int $limit): array {
            $page = [];
            for ($i = 1; $i <= $limit; $i++) {
                $page[] = [$after + $i, 0, [1.0, 0.5]];
            }

            return $after > 100000 ? [] : $page;
        };
        $this->expectException(BudgetExceededException::class);
        (new ChunkedScanner($fetch, 2, new ScanOptions(timeBudgetSeconds: 0.000001)))->top([1, 0], 3);
    }

    public function testConstructorValidates(): void
    {
        $fetch = static fn (int $a, int $l): array => [];
        foreach ([[0, 400], [65536, 400], [3, 0], [3, 100001]] as [$dim, $pageSize]) {
            try {
                new ChunkedScanner($fetch, $dim, null, $pageSize);
                self::fail('accepted dim ' . $dim . ' page ' . $pageSize);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
