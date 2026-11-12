<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests;

use IndianOpinion\PackedVectors\Exception\BudgetExceededException;
use IndianOpinion\PackedVectors\Exception\CorruptFileException;
use IndianOpinion\PackedVectors\Exception\DimensionMismatchException;
use IndianOpinion\PackedVectors\Exception\FileMissingException;
use IndianOpinion\PackedVectors\Exception\InvalidArgumentException;
use IndianOpinion\PackedVectors\Exception\InvalidVectorException;
use IndianOpinion\PackedVectors\Exception\PackedVectorsError;
use IndianOpinion\PackedVectors\Exception\UnavailableException;
use IndianOpinion\PackedVectors\Exception\UnreadableFileException;
use IndianOpinion\PackedVectors\Hit;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PackedVectors\ScanOptions;
use IndianOpinion\PackedVectors\Tests\Support\ReadStream;
use IndianOpinion\PackedVectors\Tests\Support\SeamScanner;
use IndianOpinion\PackedVectors\Tests\Support\TestCase;

/**
 * Scanning and inspection: PRD cases 28 to 46.
 */
final class ScannerTest extends TestCase
{
    protected function tearDown(): void
    {
        ReadStream::unregister();
        parent::tearDown();
    }

    /** Acceptance 27 (case 28). */
    public function testSizeComesFromTheOpenHandleNotThePath(): void
    {
        $path = $this->path();
        $old = [[1, 0, [1, 0, 0]], [2, 0, [0, 1, 0]]];
        $this->buildFile($path, 3, $old);
        $newer = $this->path('newer.bin');
        $this->buildFile($newer, 3, self::randomRecords(50, 3, 30, 0, 100));

        $scanner = new SeamScanner($path);
        $scanner->onOpen = static function () use ($newer, $path): void {
            rename($newer, $path);
        };
        $result = $scanner->top([1, 0, 0], 10);
        self::assertSame([1, 2], array_map(static fn (Hit $h): int => $h->id, $result->hits()));
        self::assertSame(2, $result->report()->scanned());
        // The path now names the newer file.
        self::assertSame(50, (new Scanner($path))->info()->count);
    }

    /** Acceptance 28 (case 29). */
    public function testMissingAndUnreadableAreNotCorrupt(): void
    {
        try {
            (new Scanner($this->path('absent.bin')))->top([1.0], 1);
            self::fail('missing file accepted');
        } catch (FileMissingException $e) {
            self::assertInstanceOf(UnavailableException::class, $e);
        }

        // A directory at the path cannot be read as a file (works as root too).
        mkdir($this->path('a-dir'));
        try {
            (new Scanner($this->path('a-dir')))->info();
            self::fail('directory accepted');
        } catch (UnreadableFileException $e) {
            self::assertInstanceOf(UnavailableException::class, $e);
        }

        if (self::isRoot()) {
            return;
        }
        $path = $this->path();
        $this->buildFile($path, 1, [[1, 0, [1.0]]]);
        chmod($path, 0000);
        try {
            (new Scanner($path))->info();
            self::fail('mode 000 file opened');
        } catch (UnreadableFileException $e) {
            self::assertStringContainsString('Permission denied', $e->getMessage());
        }
    }

    public function testUnreadableWithMode000(): void
    {
        if (self::isRoot()) {
            self::markTestSkipped('root can read a mode 000 file');
        }
        $path = $this->path();
        $this->buildFile($path, 1, [[1, 0, [1.0]]]);
        chmod($path, 0000);
        $this->expectException(UnreadableFileException::class);
        (new Scanner($path))->top([1.0], 1);
    }

    /** Acceptance 29 (case 30). */
    public function testQueryMustBeAListOfTheFileDim(): void
    {
        $path = $this->path();
        $this->buildFile($path, 4, [[1, 0, [1, 2, 3, 4]]]);
        $scanner = new Scanner($path);

        $fromUnpack = unpack('g4', pack('g4', 1.0, 2.0, 3.0, 4.0));
        self::assertIsArray($fromUnpack);
        self::assertSame([1, 2, 3, 4], array_keys($fromUnpack));
        try {
            $scanner->top($fromUnpack, 1);
            self::fail('an unpack() array was accepted as a query');
        } catch (InvalidVectorException $e) {
            self::assertSame('not_list', $e->reason);
            self::assertStringContainsString('array_values', $e->getMessage());
        }
        self::assertCount(1, $scanner->top(array_values($fromUnpack), 1)->hits());

        try {
            $scanner->top([1, 2, 3], 1);
            self::fail('short query accepted');
        } catch (DimensionMismatchException $e) {
            self::assertSame(4, $e->expected);
            self::assertSame(3, $e->given);
            self::assertInstanceOf(UnavailableException::class, $e);
        }
        $this->expectException(DimensionMismatchException::class);
        $scanner->top([1, 2, 3, 4, 5], 1);
    }

    /** Acceptance 30 (case 31). */
    public function testNonFiniteOrZeroQueryIsRejected(): void
    {
        $path = $this->path();
        $this->buildFile($path, 3, [[1, 0, [1, 2, 3]]]);
        $scanner = new Scanner($path);
        foreach ([[NAN, 1, 1], [1, INF, 1], [1, 1, -INF], [0, 0, 0], [0.0, -0.0, 0], ['1', 1, 1], []] as $query) {
            try {
                $scanner->top($query, 1);
                self::fail('query accepted: ' . json_encode($query));
            } catch (InvalidVectorException $e) {
                self::assertInstanceOf(PackedVectorsError::class, $e);
            }
        }
    }

    /** Acceptance 31 (cases 32, 33). */
    public function testScoresMatchDoublePrecisionCosine(): void
    {
        $records = self::randomRecords(300, 24, 31);
        $scaled = $records;
        foreach ($scaled as $i => $r) {
            $scaled[$i][2] = array_map(static fn (float $x): float => $x * (1 + $i % 5) * 7.5, $r[2]);
        }
        $this->buildFile($this->path('n.bin'), 24, $scaled);
        $this->buildFile($this->path('u.bin'), 24, $scaled, self::opts()->withNormalise(false));
        mt_srand(32);
        $query = array_map(static fn (float $x): float => $x * 3.0, self::randomVector(24));

        $byId = [];
        foreach ($scaled as [$id, , $v]) {
            $byId[$id] = self::cosine($query, $v);
        }
        foreach (['n.bin', 'u.bin'] as $file) {
            $result = (new Scanner($this->path($file)))->top($query, 300);
            self::assertCount(300, $result->hits());
            self::assertSame($file === 'n.bin', $result->report()->normalised());
            foreach ($result->hits() as $hit) {
                self::assertEqualsWithDelta($byId[$hit->id], $hit->score, 1e-5, $file . ' id ' . $hit->id);
            }
        }
    }

    /** Acceptance 32 (case 33). */
    public function testZeroVectorInUnnormalisedFileScoresZero(): void
    {
        $path = $this->path();
        $this->buildFile($path, 3, [[1, 0, [0, 0, 0]], [2, 0, [1, 1, 1]]], self::opts()->withNormalise(false));
        $hits = (new Scanner($path))->top([1, 1, 1], 2)->hits();
        self::assertSame(2, $hits[0]->id);
        self::assertSame(1, $hits[1]->id);
        self::assertSame(0.0, $hits[1]->score);
    }

    /** Acceptance 33 (case 34). */
    public function testSelfMatchScoresOneAndNeverAbove(): void
    {
        $path = $this->path();
        $this->buildFile($path, 4, [[1, 0, [0, 0, 1, 0]]]);
        self::assertSame(1.0, (new Scanner($path))->top([0, 0, 5, 0], 1)->hits()[0]->score);

        // Float32 rounding pushes some raw dot products above 1; they must
        // come back as exactly 1.0.
        $records = self::randomRecords(400, 16, 33);
        foreach ([true, false] as $normalise) {
            $file = $this->path($normalise ? 'n.bin' : 'u.bin');
            $this->buildFile($file, 16, $records, self::opts()->withNormalise($normalise));
            $scanner = new Scanner($file);
            $exactOnes = 0;
            foreach ($records as [$id, , $v]) {
                $hit = $scanner->top($v, 1)->hits()[0];
                self::assertSame($id, $hit->id);
                self::assertLessThanOrEqual(1.0, $hit->score);
                self::assertGreaterThan(1.0 - 1e-6, $hit->score);
                if ($hit->score === 1.0) {
                    $exactOnes++;
                }
            }
            self::assertGreaterThan(0, $exactOnes);
        }
    }

    /** Acceptance 34 (case 35). */
    public function testRandomShortReadsGiveSameHits(): void
    {
        $path = $this->path();
        $records = self::randomRecords(500, 12, 34, 20);
        $this->buildFile($path, 12, $records);
        mt_srand(35);
        $query = self::randomVector(12);
        $options = new ScanOptions(chunkBytes: 1000);

        $plain = (new Scanner($path, $options))->top($query, 25);
        ReadStream::register();
        ReadStream::$shortReads = true;
        mt_srand(36);
        $wrapped = (new Scanner(ReadStream::url($path), $options))->top($query, 25);

        self::assertEquals($plain->hits(), $wrapped->hits());
        self::assertSame($plain->report()->bytesRead(), $wrapped->report()->bytesRead());
        self::assertTrue($wrapped->report()->checksumVerified());
    }

    /** Acceptance 34 (case 35): a file cut mid-record. */
    public function testFileCutMidRecordIsCorruptSize(): void
    {
        $path = $this->path();
        $this->buildFile($path, 12, self::randomRecords(10, 12, 37));
        $data = (string) file_get_contents($path);
        file_put_contents($path, substr($data, 0, strlen($data) - 7));
        try {
            (new Scanner($path))->top(array_fill(0, 12, 1.0), 3);
            self::fail('truncated file accepted');
        } catch (CorruptFileException $e) {
            self::assertSame('size', $e->reason);
        }
    }

    /**
     * Acceptance 35 (case 36). The one-indexed unpack() trap: a scanner that
     * indexed unpack() output from 0 would read null for the first value
     * and drop the last, so these vectors would score 0, not 1.
     */
    public function testFirstAndLastValuesBothCount(): void
    {
        // The trap itself, documented: unpack() keys start at 1.
        $u = unpack('g3', pack('g3', 7.0, 8.0, 9.0));
        self::assertIsArray($u);
        self::assertArrayNotHasKey(0, $u);
        self::assertSame(7.0, $u[1]);
        self::assertSame(9.0, $u[3]);

        $dim = 9;
        $records = [];
        $id = 1;
        foreach ([0, $dim - 1] as $slot) {
            for ($copy = 0; $copy < 3; $copy++) {
                $v = array_fill(0, $dim, 0.0);
                $v[$slot] = 2.5;
                $records[] = [$id++, $slot, $v];
            }
        }
        // Noise records with no weight in the first or last slot.
        mt_srand(38);
        for ($i = 0; $i < 50; $i++) {
            $v = self::randomVector($dim);
            $v[0] = 0.0;
            $v[$dim - 1] = 0.0;
            $records[] = [$id++, 99, $v];
        }
        foreach ([true, false] as $normalise) {
            $path = $this->path($normalise ? 'n.bin' : 'u.bin');
            // Small chunks and blocks so records sit at many offsets.
            $this->buildFile($path, $dim, $records, self::opts()->withNormalise($normalise));
            $scanner = new Scanner($path, new ScanOptions(chunkBytes: 3 * (8 + 4 * $dim) + 5));
            foreach ([0, $dim - 1] as $slot) {
                $query = array_fill(0, $dim, 0.0);
                $query[$slot] = 1.0;
                $hits = $scanner->top($query, 3)->hits();
                self::assertCount(3, $hits);
                foreach ($hits as $hit) {
                    self::assertSame($slot, $hit->ref);
                    self::assertSame(1.0, $hit->score);
                }
            }
        }
    }

    /** Acceptance 36 (case 37). */
    public function testTiesOrderedByIdThenRefAndDeterministic(): void
    {
        $path = $this->path();
        $records = [
            [5, 2, [1, 0]],
            [3, 9, [1, 0]],
            [5, 1, [1, 0]],
            [4, 0, [0, 1]],
            [3, 1, [1, 0]],
            [1, 0, [-1, 0]],
        ];
        $this->buildFile($path, 2, $records);
        $scanner = new Scanner($path);
        $first = $scanner->top([1, 0], 6)->hits();
        $order = array_map(static fn (Hit $h): string => $h->id . '/' . $h->ref, $first);
        self::assertSame(['3/1', '3/9', '5/1', '5/2', '4/0', '1/0'], $order);
        self::assertEquals($first, $scanner->top([1, 0], 6)->hits());

        // With k smaller than the tie group, the lowest ids win.
        $two = $scanner->top([1, 0], 2)->hits();
        self::assertSame(['3/1', '3/9'], array_map(static fn (Hit $h): string => $h->id . '/' . $h->ref, $two));
    }

    /** Acceptance 37 (case 38). */
    public function testKBounds(): void
    {
        $path = $this->path();
        $this->buildFile($path, 2, [[1, 0, [1, 0]]]);
        $scanner = new Scanner($path);
        foreach ([0, -1, 10001] as $k) {
            try {
                $scanner->top([1, 0], $k);
                self::fail('k ' . $k . ' accepted');
            } catch (InvalidArgumentException $e) {
                self::assertInstanceOf(PackedVectorsError::class, $e);
                self::assertInstanceOf(\InvalidArgumentException::class, $e);
            }
        }
        self::assertCount(1, $scanner->top([1, 0], 10000)->hits());
    }

    /** Acceptance 38 (case 39). */
    public function testMinimumScore(): void
    {
        $path = $this->path();
        $records = self::randomRecords(300, 6, 39);
        $this->buildFile($path, 6, $records);
        $query = $records[0][2];
        $hits = (new Scanner($path, new ScanOptions(minScore: 0.5)))->top($query, 300)->hits();
        self::assertNotEmpty($hits);
        self::assertLessThan(300, count($hits));
        foreach ($hits as $hit) {
            self::assertGreaterThanOrEqual(0.5, $hit->score);
        }
        $expected = 0;
        foreach ($records as [, , $v]) {
            if (self::cosine($query, $v) >= 0.5 + 1e-6) {
                $expected++;
            }
        }
        self::assertGreaterThanOrEqual($expected, count($hits));

        $none = (new Scanner($path, new ScanOptions(minScore: 1.5)))->top($query, 10)->hits();
        self::assertSame([], $none);
    }

    /** Acceptance 39 (case 40): a ref shared by five records comes back once. */
    public function testBestPerRefKeepsOneHitPerRef(): void
    {
        $path = $this->path();
        $records = [
            [1, 7, [1.0, 0.10]],
            [2, 7, [1.0, 0.05]],
            [3, 7, [1.0, 0.20]],
            [4, 7, [1.0, 0.01]],
            [5, 7, [1.0, 0.30]],
            [6, 8, [1.0, 0.50]],
            [7, 9, [1.0, 0.70]],
            [8, 10, [0.0, 1.00]],
        ];
        $this->buildFile($path, 2, $records);
        $hits = (new Scanner($path, new ScanOptions(bestPerRef: true)))->top([1, 0], 3)->hits();
        self::assertSame([4, 6, 7], array_map(static fn (Hit $h): int => $h->id, $hits));
        self::assertSame([7, 8, 9], array_map(static fn (Hit $h): int => $h->ref, $hits));

        $all = (new Scanner($path, new ScanOptions(bestPerRef: true)))->top([1, 0], 10)->hits();
        self::assertSame([7, 8, 9, 10], array_map(static fn (Hit $h): int => $h->ref, $all));
    }

    /** Acceptance 39 (case 40): equals a brute-force reference on 1,000 random files. */
    public function testBestPerRefMatchesBruteForce(): void
    {
        $path = $this->path();
        mt_srand(40);
        for ($file = 0; $file < 1000; $file++) {
            $dim = mt_rand(1, 4);
            $count = mt_rand(0, 25);
            $refs = mt_rand(1, 8);
            $k = mt_rand(1, 6);
            $records = [];
            for ($i = 0; $i < $count; $i++) {
                // Small integer values make exact ties common.
                $v = [];
                for ($j = 0; $j < $dim; $j++) {
                    $v[] = mt_rand(-2, 2);
                }
                if ($v === array_fill(0, $dim, 0)) {
                    $v[0] = 1;
                }
                $records[] = [mt_rand(1, 30), mt_rand(1, $refs), $v];
            }
            $query = [];
            for ($j = 0; $j < $dim; $j++) {
                $query[] = mt_rand(-2, 2);
            }
            if ($query === array_fill(0, $dim, 0)) {
                $query[0] = 1;
            }
            $minScore = mt_rand(0, 3) === 0 ? 0.0 : null;
            $this->buildFile($path, $dim, $records, self::opts()->withLock(false));
            $options = new ScanOptions(minScore: $minScore, bestPerRef: true, chunkBytes: mt_rand(1, 200));
            $got = (new Scanner($path, $options))->top($query, $k)->hits();

            $expected = self::bruteForce($path, $query, $k, true, $minScore);
            self::assertSame(
                array_map(static fn (Hit $h): string => $h->id . '/' . $h->ref, $expected),
                array_map(static fn (Hit $h): string => $h->id . '/' . $h->ref, $got),
                'file ' . $file
            );
        }
    }

    /** Plain top-k against the same brute force. */
    public function testTopKMatchesBruteForce(): void
    {
        $path = $this->path();
        mt_srand(41);
        for ($file = 0; $file < 200; $file++) {
            $dim = mt_rand(1, 5);
            $records = [];
            $count = mt_rand(0, 60);
            for ($i = 0; $i < $count; $i++) {
                $v = [];
                for ($j = 0; $j < $dim; $j++) {
                    $v[] = mt_rand(-3, 3);
                }
                $records[] = [mt_rand(1, 40), mt_rand(0, 5), $v];
            }
            $query = array_fill(0, $dim, 1);
            $k = mt_rand(1, 12);
            $this->buildFile($path, $dim, $records, self::opts()->withNormalise(false)->withLock(false));
            $got = (new Scanner($path))->top($query, $k)->hits();
            $expected = self::bruteForce($path, $query, $k, false, null);
            self::assertEquals($expected, $got, 'file ' . $file);
        }
    }

    /** Acceptance 40 (case 41). */
    public function testPayloadBitFlip(): void
    {
        $path = $this->path();
        $this->buildFile($path, 8, self::randomRecords(40, 8, 42));
        $data = (string) file_get_contents($path);
        $offset = 40 + 17 * (8 + 32) + 13;
        $data[$offset] = chr(ord($data[$offset]) ^ 0x01);
        file_put_contents($path, $data);

        try {
            (new Scanner($path))->top(array_fill(0, 8, 1.0), 5);
            self::fail('flipped bit not noticed');
        } catch (CorruptFileException $e) {
            self::assertSame('payload_crc', $e->reason);
        }
        $result = (new Scanner($path, new ScanOptions(verifyChecksum: false)))->top(array_fill(0, 8, 1.0), 5);
        self::assertCount(5, $result->hits());
        self::assertFalse($result->report()->checksumVerified());
    }

    public function testStoredNanWithValidChecksumIsCorrupt(): void
    {
        $path = $this->path();
        $this->buildFile($path, 2, [[1, 0, [1, 0]], [2, 0, [0, 1]]]);
        self::writeValueAndFixChecksums($path, 1, 0, NAN);
        try {
            (new Scanner($path))->top([1, 1], 2);
            self::fail('NaN scored');
        } catch (CorruptFileException $e) {
            self::assertSame('non_finite', $e->reason);
        }
    }

    /** Acceptance 41 (case 42). */
    public function testTimeBudget(): void
    {
        $path = $this->path();
        $this->buildFile($path, 8, self::randomRecords(10000, 8, 43));
        try {
            (new Scanner($path, new ScanOptions(timeBudgetSeconds: 0.000001)))->top(array_fill(0, 8, 1.0), 10);
            self::fail('budget ignored');
        } catch (BudgetExceededException $e) {
            self::assertLessThan(10000, $e->scanned);
            self::assertSame(0, $e->scanned % Scanner::BUDGET_CHECK_EVERY);
            self::assertGreaterThan(0.000001, $e->seconds);
        }
        $ok = (new Scanner($path, new ScanOptions(timeBudgetSeconds: 60.0)))->top(array_fill(0, 8, 1.0), 10);
        self::assertCount(10, $ok->hits());
    }

    /** Acceptance 42 (case 43). */
    public function testReportFields(): void
    {
        $path = $this->path();
        $build = $this->buildFile($path, 10, self::randomRecords(123, 10, 44));
        $result = (new Scanner($path, new ScanOptions(chunkBytes: 1000)))->top(array_fill(0, 10, 1.0), 7);
        $report = $result->report();
        self::assertSame(123, $report->scanned());
        self::assertSame(0, $report->skipped());
        self::assertSame(123 * 48, $report->bytesRead());
        self::assertSame(10, $report->dim());
        self::assertSame($build->builtAt(), $report->builtAt());
        self::assertTrue($report->normalised());
        self::assertTrue($report->checksumVerified());
        self::assertGreaterThan(0.0, $report->seconds());
        self::assertStringContainsString('scanned=123', (string) $report);
        self::assertCount(7, $result->hits());
    }

    /** Acceptance 43 (case 44). */
    public function testScanInReadOnlyDirectoryChangesNothing(): void
    {
        $sub = $this->dir . '/ro';
        mkdir($sub);
        $path = $sub . '/vectors.bin';
        $this->buildFile($path, 3, self::randomRecords(20, 3, 45), self::opts()->withLock(false));
        chmod($sub, 0555);
        $before = $this->listing($sub);
        $mtime = filemtime($path);

        $scanner = new Scanner($path);
        self::assertCount(5, $scanner->top([1, 1, 1], 5)->hits());
        $scanner->info();
        $scanner->verify();

        clearstatcache();
        self::assertSame($before, $this->listing($sub));
        self::assertSame(['vectors.bin'], $before);
        self::assertSame($mtime, filemtime($path));
    }

    /** Acceptance 44 (case 45). */
    public function testInfoReadsOnlyTheHeader(): void
    {
        $path = $this->path();
        $build = $this->buildFile($path, 64, self::randomRecords(100, 64, 46));
        ReadStream::register();
        $info = (new Scanner(ReadStream::url($path)))->info();
        self::assertSame(40, ReadStream::$highWater);
        self::assertSame(40, ReadStream::$bytesOut);

        self::assertSame(1, $info->version);
        self::assertSame(1, $info->flags);
        self::assertTrue($info->normalised());
        self::assertSame(64, $info->dim);
        self::assertSame(100, $info->count);
        self::assertSame($build->builtAt(), $info->builtAt);
        self::assertSame(8 + 256, $info->recordSize);
        self::assertSame(filesize($path), $info->fileSize);
        self::assertSame(40 + 100 * 264, $info->toArray()['file_size']);
        self::assertGreaterThanOrEqual(0, $info->ageSeconds());
    }

    /** Acceptance 45 (case 46). */
    public function testVerify(): void
    {
        $path = $this->path();
        $this->buildFile($path, 4, self::randomRecords(30, 4, 47));
        $report = (new Scanner($path))->verify();
        self::assertTrue($report->ok());
        self::assertSame([], $report->problems());
        self::assertSame($report->payloadCrcExpected(), $report->payloadCrcActual());

        $good = (string) file_get_contents($path);
        self::patch($path, 40 + 5 * 24 + 9, "\x7F");
        $report = (new Scanner($path))->verify();
        self::assertFalse($report->ok());
        self::assertFalse($report->payloadCrcOk());
        self::assertStringContainsString('checksum', $report->problems()[0]);

        file_put_contents($path, $good);
        self::writeValueAndFixChecksums($path, 7, 3, NAN);
        self::writeValueAndFixChecksums($path, 9, 0, INF);
        $report = (new Scanner($path))->verify();
        self::assertTrue($report->payloadCrcOk());
        self::assertFalse($report->ok());
        self::assertSame(2, $report->nonFiniteValues());
        self::assertSame(7, $report->firstNonFiniteRecord());
        self::assertStringContainsString('non-finite', $report->problems()[0]);
    }

    public function testChunkSizeSmallerThanOneRecordStillWorks(): void
    {
        $path = $this->path();
        $records = self::randomRecords(37, 300, 48);
        $this->buildFile($path, 300, $records);
        $a = (new Scanner($path, new ScanOptions(chunkBytes: 1)))->top($records[3][2], 4);
        $b = (new Scanner($path))->top($records[3][2], 4);
        self::assertEquals($a->hits(), $b->hits());
        self::assertSame(3 + 1, $a->hits()[0]->id);
    }

    public function testScanOptionsValidate(): void
    {
        foreach (
            [
                static fn () => new ScanOptions(minScore: NAN),
                static fn () => new ScanOptions(chunkBytes: 0),
                static fn () => new ScanOptions(timeBudgetSeconds: 0.0),
                static fn () => new ScanOptions(timeBudgetSeconds: -1.0),
                static fn () => new ScanOptions(timeBudgetSeconds: INF),
                static fn () => new Scanner(''),
            ] as $i => $make
        ) {
            try {
                $make();
                self::fail('case ' . $i . ' accepted');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $o = (new ScanOptions())->withMinScore(0.3)->withBestPerRef(true)->withVerifyChecksum(false)
            ->withChunkBytes(4096)->withTimeBudgetSeconds(0.5);
        self::assertSame(0.3, $o->minScore);
        self::assertTrue($o->bestPerRef);
        self::assertFalse($o->verifyChecksum);
        self::assertSame(4096, $o->chunkBytes);
        self::assertSame(0.5, $o->timeBudgetSeconds);
    }

    /**
     * Reference ranking straight from the decoded file, sorting every
     * candidate. Uses the library's own float32 values so ties are exact.
     *
     * @param list<int|float> $query
     * @return list<Hit>
     */
    private static function bruteForce(string $path, array $query, int $k, bool $bestPerRef, ?float $minScore): array
    {
        $decoded = self::decodeFile($path);
        $normalised = ((int) $decoded['header']['flags'] & 1) === 1;
        $qn = sqrt(array_sum(array_map(static fn ($x): float => $x * $x, $query)));
        $q = array_map(static fn ($x): float => $x / $qn, $query);
        $all = [];
        foreach ($decoded['records'] as [$id, $ref, $v]) {
            $dot = 0.0;
            $nn = 0.0;
            foreach ($v as $j => $x) {
                $dot += $q[$j] * $x;
                $nn += $x * $x;
            }
            $score = $normalised ? $dot : ($nn > 0.0 ? $dot / sqrt($nn) : 0.0);
            $score = max(-1.0, min(1.0, $score));
            if ($minScore !== null && $score < $minScore) {
                continue;
            }
            $all[] = new Hit($id, $ref, $score);
        }
        usort($all, static fn (Hit $a, Hit $b): int => [$b->score, $a->id, $a->ref] <=> [$a->score, $b->id, $b->ref]);
        if ($bestPerRef) {
            $seen = [];
            $all = array_values(array_filter($all, static function (Hit $h) use (&$seen): bool {
                if (isset($seen[$h->ref])) {
                    return false;
                }
                $seen[$h->ref] = true;

                return true;
            }));
        }

        return array_slice($all, 0, $k);
    }

    private static function writeValueAndFixChecksums(string $path, int $record, int $slot, float $value): void
    {
        $data = (string) file_get_contents($path);
        /** @var array{1: int} $dim */
        $dim = unpack('V', $data, 8);
        $offset = 40 + $record * (8 + 4 * $dim[1]) + 8 + 4 * $slot;
        $data = substr_replace($data, pack('g', $value), $offset, 4);
        $data = substr_replace($data, pack('V', crc32(substr($data, 40))), 28, 4);
        $data = substr_replace($data, pack('V', crc32(substr($data, 0, 32))), 32, 4);
        file_put_contents($path, $data);
    }
}
