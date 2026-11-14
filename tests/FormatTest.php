<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests;

use IndianOpinion\PackedVectors\Exception\CorruptFileException;
use IndianOpinion\PackedVectors\Exception\InvalidArgumentException;
use IndianOpinion\PackedVectors\Exception\UnavailableException;
use IndianOpinion\PackedVectors\Exception\UnsupportedVersionException;
use IndianOpinion\PackedVectors\Format;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PackedVectors\Tests\Support\TestCase;

/**
 * File format: PRD cases 1 to 5 and 9 (acceptance tests 1 to 5 and 9).
 */
final class FormatTest extends TestCase
{
    public function testHelpers(): void
    {
        self::assertSame(8 + 4 * 512, Format::recordSize(512));
        self::assertSame(40 + 10 * (8 + 4 * 3), Format::expectedFileSize(3, 10));
        self::assertSame(40, Format::expectedFileSize(65535, 0));
    }

    public function testHelpersRejectBadArguments(): void
    {
        foreach ([0, -1, 65536] as $dim) {
            try {
                Format::recordSize($dim);
                self::fail('dim ' . $dim . ' accepted');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Format::expectedFileSize(65535, PHP_INT_MAX);
    }

    /** Acceptance 1 (case 1). */
    public function testSizeIsExactAndOneExtraByteIsCorrupt(): void
    {
        $path = $this->path();
        $this->buildFile($path, 7, self::randomRecords(13, 7, 1));
        self::assertSame(40 + 13 * (8 + 4 * 7), filesize($path));

        file_put_contents($path, "\0", FILE_APPEND);
        try {
            (new Scanner($path))->info();
            self::fail('a file one byte too long was accepted');
        } catch (CorruptFileException $e) {
            self::assertSame('size', $e->reason);
        }
    }

    public function testFileShorterThanHeaderIsCorruptSize(): void
    {
        $path = $this->path();
        file_put_contents($path, 'PVEC');
        try {
            (new Scanner($path))->info();
            self::fail('accepted');
        } catch (CorruptFileException $e) {
            self::assertSame('size', $e->reason);
        }
    }

    /** Acceptance 2 (cases 2, 22). */
    public function testEmptySourceGivesValidEmptyFile(): void
    {
        $path = $this->path();
        $report = $this->buildFile($path, 4, []);
        self::assertSame(0, $report->written());
        self::assertSame(40, filesize($path));

        $scanner = new Scanner($path);
        self::assertSame(0, $scanner->info()->count);
        $result = $scanner->top([1, 0, 0, 0], 10);
        self::assertSame([], $result->hits());
        self::assertSame(0, $result->report()->scanned());
        self::assertTrue($result->report()->checksumVerified());
    }

    /** Acceptance 3 (case 3). */
    public function testRecordsKeepSourceOrderAndDuplicateIds(): void
    {
        $path = $this->path();
        $records = [[9, 1, [1, 0]], [3, 2, [0, 1]], [9, 3, [1, 1]], [1, 4, [1, -1]]];
        $this->buildFile($path, 2, $records, self::opts()->withNormalise(false));
        $decoded = self::decodeFile($path)['records'];
        self::assertSame([9, 3, 9, 1], array_column($decoded, 0));
        self::assertSame([1, 2, 3, 4], array_column($decoded, 1));

        $hits = (new Scanner($path))->top([1, 0], 4)->hits();
        self::assertCount(4, $hits);
    }

    /** Acceptance 4 (case 4). */
    public function testReservedFieldOrUnknownFlagIsCorrupt(): void
    {
        $path = $this->path();
        $this->buildFile($path, 3, self::randomRecords(2, 3, 2));
        $good = (string) file_get_contents($path);

        self::patch($path, 36, "\x01");
        $this->assertCorrupt($path, 'reserved');

        file_put_contents($path, $good);
        self::patch($path, 6, pack('v', 1 | 4), true);
        $this->assertCorrupt($path, 'reserved');

        file_put_contents($path, $good);
        self::patch($path, 6, pack('v', 1 | 2), true);
        $this->assertCorrupt($path, 'reserved');
    }

    public function testBadMagicAndHeaderChecksum(): void
    {
        $path = $this->path();
        $this->buildFile($path, 3, self::randomRecords(2, 3, 2));
        $good = (string) file_get_contents($path);

        self::patch($path, 0, 'XVEC');
        $this->assertCorrupt($path, 'magic');

        file_put_contents($path, $good);
        self::patch($path, 20, "\xFF");
        $this->assertCorrupt($path, 'header_crc');
    }

    public function testDimOutOfRangeAndOversizedCount(): void
    {
        $path = $this->path();
        $this->buildFile($path, 3, self::randomRecords(2, 3, 2));
        $good = (string) file_get_contents($path);

        self::patch($path, 8, pack('V', 0), true);
        $this->assertCorrupt($path, 'dim');

        file_put_contents($path, $good);
        self::patch($path, 8, pack('V', 70000), true);
        $this->assertCorrupt($path, 'dim');

        // A forged count must not overflow the size arithmetic or allocate.
        file_put_contents($path, $good);
        self::patch($path, 12, pack('P', PHP_INT_MAX), true);
        $this->assertCorrupt($path, 'size');

        file_put_contents($path, $good);
        self::patch($path, 12, "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF", true);
        $this->assertCorrupt($path, 'size');
    }

    /** Acceptance 5 (case 5). */
    public function testUnknownVersionIsUnsupported(): void
    {
        $path = $this->path();
        $this->buildFile($path, 3, self::randomRecords(2, 3, 2));
        self::patch($path, 4, pack('v', 2), true);
        try {
            (new Scanner($path))->info();
            self::fail('version 2 accepted');
        } catch (UnsupportedVersionException $e) {
            self::assertSame(2, $e->version);
            self::assertInstanceOf(UnavailableException::class, $e);
        }
    }

    /** Acceptance 9 (case 9). */
    public function testHeaderFieldsRecomputedIndependently(): void
    {
        $path = $this->path();
        $before = time();
        $report = $this->buildFile($path, 5, self::randomRecords(21, 5, 3));
        $after = time();

        $data = (string) file_get_contents($path);
        $h = self::decodeFile($path)['header'];
        self::assertSame('PVEC', $h['magic']);
        self::assertSame(1, $h['version']);
        self::assertSame(1, $h['flags']);
        self::assertSame(5, $h['dim']);
        self::assertSame(21, $h['count']);
        self::assertGreaterThanOrEqual($before, $h['builtAt']);
        self::assertLessThanOrEqual($after, $h['builtAt']);
        self::assertSame($report->builtAt(), $h['builtAt']);
        self::assertSame(crc32(substr($data, 40)), $h['payloadCrc']);
        self::assertSame(crc32(substr($data, 0, 32)), $h['headerCrc']);
        self::assertSame(0, $h['reserved']);
    }

    private function assertCorrupt(string $path, string $reason): void
    {
        try {
            (new Scanner($path))->info();
            self::fail('corrupt file accepted, expected reason ' . $reason);
        } catch (CorruptFileException $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
            self::assertInstanceOf(UnavailableException::class, $e);
        }
    }
}
