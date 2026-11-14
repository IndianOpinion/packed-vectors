<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

use IndianOpinion\PackedVectors\Exception\PackedVectorsError;

/**
 * The bin/packed-vectors command. Kept in a class so it can be tested
 * without a subprocess.
 *
 * @internal
 */
final class Cli
{
    public const OK = 0;
    public const FAILED = 1;
    public const USAGE = 2;

    private function __construct()
    {
    }

    /**
     * @param list<string> $argv
     * @param resource $out
     * @param resource $err
     */
    public static function main(array $argv, $out, $err): int
    {
        $command = $argv[1] ?? '';
        $file = $argv[2] ?? '';
        if (in_array($command, ['-h', '--help', 'help'], true)) {
            fwrite($out, self::usage());

            return self::OK;
        }
        if (!in_array($command, ['info', 'verify'], true) || $file === '' || count($argv) !== 3) {
            fwrite($err, self::usage());

            return self::USAGE;
        }

        try {
            $scanner = new Scanner($file);
            if ($command === 'info') {
                self::printFields($out, $scanner->info()->toArray());

                return self::OK;
            }
            $report = $scanner->verify();
            self::printFields($out, $report->info()->toArray());
            fwrite($out, sprintf("payload_crc_actual: %08x\n", $report->payloadCrcActual()));
            fwrite($out, sprintf("non_finite_values: %d\n", $report->nonFiniteValues()));
            fwrite($out, sprintf("seconds: %.3f\n", $report->seconds()));
            if ($report->ok()) {
                fwrite($out, "status: ok\n");

                return self::OK;
            }
            fwrite($out, "status: failed\n");
            foreach ($report->problems() as $problem) {
                fwrite($err, 'packed-vectors: ' . $problem . "\n");
            }

            return self::FAILED;
        } catch (PackedVectorsError $e) {
            fwrite($err, 'packed-vectors: ' . $e->getMessage() . "\n");

            return self::FAILED;
        }
    }

    /**
     * @param resource $out
     * @param array<string, int|string|bool> $fields
     */
    private static function printFields($out, array $fields): void
    {
        foreach ($fields as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            fwrite($out, $key . ': ' . $value . "\n");
        }
    }

    private static function usage(): string
    {
        return "Usage:\n"
            . "  packed-vectors info <file>     print the header fields and file size\n"
            . "  packed-vectors verify <file>   read the whole file, check the payload checksum and values\n"
            . "Exit codes: 0 ok, 1 missing, unreadable, corrupt or failed verification, 2 usage error.\n";
    }
}
