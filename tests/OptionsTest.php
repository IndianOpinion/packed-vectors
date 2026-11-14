<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests;

use IndianOpinion\PackedVectors\BuildOptions;
use IndianOpinion\PackedVectors\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Section 7 defaults and option validation.
 */
final class OptionsTest extends TestCase
{
    public function testBuildDefaults(): void
    {
        $o = new BuildOptions();
        self::assertTrue($o->normalise);
        self::assertSame('skip', $o->onBadRecord);
        self::assertSame(0640, $o->fileMode);
        self::assertSame(1048576, $o->writeBufferBytes);
        self::assertTrue($o->lock);
        self::assertSame(0.0, $o->lockWaitSeconds);
        self::assertSame(3600, $o->staleTempAgeSeconds);
        self::assertTrue($o->fsync);
    }

    public function testWithersReturnCopies(): void
    {
        $o = new BuildOptions();
        $p = $o->withNormalise(false)->withOnBadRecord('throw')->withFileMode(0600)->withWriteBufferBytes(4096)
            ->withLock(false)->withLockWaitSeconds(2.5)->withStaleTempAgeSeconds(60)->withFsync(false);
        self::assertTrue($o->normalise);
        self::assertFalse($p->normalise);
        self::assertSame('throw', $p->onBadRecord);
        self::assertSame(0600, $p->fileMode);
        self::assertSame(4096, $p->writeBufferBytes);
        self::assertFalse($p->lock);
        self::assertSame(2.5, $p->lockWaitSeconds);
        self::assertSame(60, $p->staleTempAgeSeconds);
        self::assertFalse($p->fsync);
    }

    public function testBuildValidation(): void
    {
        $o = new BuildOptions();
        foreach (
            [
                /** @phpstan-ignore argument.type */
                static fn () => $o->withOnBadRecord('ignore'),
                static fn () => $o->withFileMode(01000),
                static fn () => $o->withFileMode(-1),
                static fn () => $o->withWriteBufferBytes(0),
                static fn () => $o->withLockWaitSeconds(-0.1),
                static fn () => $o->withLockWaitSeconds(NAN),
                static fn () => $o->withStaleTempAgeSeconds(-1),
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
}
