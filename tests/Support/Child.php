<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests\Support;

use RuntimeException;

/**
 * A PHP child process with line-based stdin and stdout, started without a
 * shell (proc_open with an argument list).
 */
final class Child
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    /**
     * @param list<string> $args
     */
    public function __construct(string $script, array $args = [])
    {
        $command = array_merge([PHP_BINARY, '-d', 'display_errors=stderr', $script], $args);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start ' . $script);
        }
        $this->process = $process;
        $this->pipes = $pipes;
    }

    public function readLine(float $timeoutSeconds = 30.0): string
    {
        $read = [$this->pipes[1]];
        $write = null;
        $except = null;
        $secs = (int) $timeoutSeconds;
        $usecs = (int) (($timeoutSeconds - $secs) * 1e6);
        if (stream_select($read, $write, $except, $secs, $usecs) !== 1) {
            throw new RuntimeException('child did not answer in time; stderr: ' . $this->stderr());
        }
        $line = fgets($this->pipes[1]);
        if ($line === false) {
            throw new RuntimeException('child closed its output; stderr: ' . $this->stderr());
        }

        return rtrim($line, "\n");
    }

    public function send(string $line): void
    {
        fwrite($this->pipes[0], $line . "\n");
        fflush($this->pipes[0]);
    }

    /** Exit code seen by running(); before PHP 8.3 proc_close() cannot report it again. */
    private ?int $exitCode = null;

    public function running(): bool
    {
        if ($this->exitCode !== null) {
            return false;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            return true;
        }
        $this->exitCode = $status['exitcode'];

        return false;
    }

    /**
     * Waits for the child and returns its exit code.
     */
    public function wait(): int
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $code = proc_close($this->process);

        return $this->exitCode ?? $code;
    }

    private function stderr(): string
    {
        stream_set_blocking($this->pipes[2], false);

        return (string) stream_get_contents($this->pipes[2]);
    }
}
