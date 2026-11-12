<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests;

use IndianOpinion\PackedVectors\Environment;
use IndianOpinion\PackedVectors\Exception\PackedVectorsError;
use IndianOpinion\PackedVectors\Exception\UnsupportedPlatformException;
use IndianOpinion\PackedVectors\Tests\Support\Child;
use PHPUnit\Framework\TestCase;

/**
 * Section 10: 64-bit only (acceptance 56), and the Composer-free bootstrap.
 */
final class EnvironmentTest extends TestCase
{
    public function testRefuses32BitBuild(): void
    {
        try {
            Environment::assertSupported(4);
            self::fail('a 32-bit build was accepted');
        } catch (UnsupportedPlatformException $e) {
            self::assertInstanceOf(PackedVectorsError::class, $e);
            self::assertStringContainsString('64-bit', $e->getMessage());
        }
    }

    public function testAccepts64BitBuild(): void
    {
        Environment::assertSupported(8);
        Environment::assertSupported();
        self::assertSame(8, PHP_INT_SIZE);
    }

    public function testBootstrapWorksWithoutComposer(): void
    {
        $child = new Child(__DIR__ . '/fixtures/bootstrap-only.php');
        self::assertSame('ok 2', $child->readLine());
        self::assertSame(0, $child->wait());
    }
}
