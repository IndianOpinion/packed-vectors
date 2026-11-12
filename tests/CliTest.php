<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests;

use IndianOpinion\PackedVectors\Cli;
use IndianOpinion\PackedVectors\Tests\Support\TestCase;

/**
 * bin/packed-vectors (acceptance 57).
 */
final class CliTest extends TestCase
{
    /**
     * Runs the real script in a child process (no shell).
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function cli(string ...$args): array
    {
        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/packed-vectors', ...array_values($args)];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    public function testInfoAndVerifyOnGoodFile(): void
    {
        $path = $this->path();
        $this->buildFile($path, 3, self::randomRecords(9, 3, 70));

        [$code, $out] = self::cli('info', $path);
        self::assertSame(0, $code);
        self::assertStringContainsString("dim: 3\n", $out);
        self::assertStringContainsString("count: 9\n", $out);
        self::assertStringContainsString('file_size: ' . (40 + 9 * 20) . "\n", $out);
        self::assertStringContainsString("normalised: yes\n", $out);

        [$code, $out] = self::cli('verify', $path);
        self::assertSame(0, $code);
        self::assertStringContainsString("status: ok\n", $out);
    }

    public function testNonZeroOnCorruptOrMissing(): void
    {
        $path = $this->path();
        $this->buildFile($path, 3, self::randomRecords(9, 3, 71));
        $data = (string) file_get_contents($path);

        // Payload damage: info passes (it reads the header only), verify fails.
        $damaged = $data;
        $damaged[50] = chr(ord($damaged[50]) ^ 0x10);
        file_put_contents($path, $damaged);
        self::assertSame(0, self::cli('info', $path)[0]);
        [$code, $out, $err] = self::cli('verify', $path);
        self::assertSame(1, $code);
        self::assertStringContainsString("status: failed\n", $out);
        self::assertStringContainsString('checksum', $err);

        // Header damage: both fail.
        file_put_contents($path, 'XXXX' . substr($data, 4));
        foreach (['info', 'verify'] as $command) {
            [$code, , $err] = self::cli($command, $path);
            self::assertSame(1, $code, $command);
            self::assertStringContainsString('corrupt (magic)', $err);
        }

        [$code, , $err] = self::cli('info', $this->path('missing.bin'));
        self::assertSame(1, $code);
        self::assertStringContainsString('not found', $err);
    }

    public function testUsage(): void
    {
        self::assertSame(2, self::cli()[0]);
        self::assertSame(2, self::cli('build', 'x')[0]);
        self::assertSame(2, self::cli('info')[0]);
        self::assertSame(2, self::cli('info', 'a', 'b')[0]);
        [$code, $out] = self::cli('--help');
        self::assertSame(0, $code);
        self::assertStringContainsString('Usage', $out);
    }

    public function testMainInProcess(): void
    {
        $path = $this->path();
        $this->buildFile($path, 2, [[1, 0, [1, 0]]]);
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        self::assertSame(Cli::OK, Cli::main(['packed-vectors', 'verify', $path], $out, $err));
        rewind($out);
        self::assertStringContainsString('status: ok', (string) stream_get_contents($out));
    }
}
