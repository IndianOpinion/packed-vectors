<?php

/**
 * packed-vectors benchmark. Prints the section 9 numbers and the
 * measurements behind the design decisions in docs/ARCHITECTURE.md.
 *
 *     php benchmarks/bench.php [records] [dim] [work-dir]
 *
 * Defaults: 10,000 records, 512 dims, the system temporary directory.
 * Timings depend on the machine and its load; run it on an idle core.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\BuildOptions;
use IndianOpinion\PackedVectors\Internal\TopK;
use IndianOpinion\PackedVectors\Scanner;

$records = (int) ($argv[1] ?? 10000);
$dim = (int) ($argv[2] ?? 512);
$workDir = $argv[3] ?? sys_get_temp_dir();
$microRecords = min($records, 2000);

printf("PHP %s, JIT %s, %d records x %d dims\n\n", PHP_VERSION, jitState(), $records, $dim);

mt_srand(1);
$vectors = [];
for ($i = 0; $i < $microRecords; $i++) {
    $v = [];
    for ($j = 0; $j < $dim; $j++) {
        $v[] = mt_rand() / mt_getrandmax() * 2 - 1;
    }
    $vectors[] = $v;
}
$query = $vectors[0];
$qn = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $query)));
$q = array_map(static fn (float $x): float => $x / $qn, $query);
$packed = '';
foreach ($vectors as $i => $v) {
    $packed .= pack('VVg*', $i + 1, 0, ...$v);
}
$recordSize = 8 + 4 * $dim;
$words = $dim + 2;

// 1. Section 9: the real scanner on a real file.
$path = $workDir . '/packed-vectors-bench-' . getmypid() . '.bin';
$source = static function () use ($records, $dim): Generator {
    mt_srand(2);
    for ($i = 1; $i <= $records; $i++) {
        $v = [];
        for ($j = 0; $j < $dim; $j++) {
            $v[] = mt_rand() / mt_getrandmax() * 2 - 1;
        }
        yield [$i, $i % 50, $v];
    }
};
gc_collect_cycles();
$before = memory_get_usage(true);
resetPeak();
$build = (new Builder($path, $dim, (new BuildOptions())->withFsync(false)))->build($source());
$buildMem = memory_get_peak_usage(true) - $before;
printf(
    "build: %d records in %.2f s (includes generating random vectors), peak added %.1f MiB\n",
    $build->written(),
    $build->seconds(),
    $buildMem / 1048576
);

$scanner = new Scanner($path);
$scanner->top($query, 10);
$best = INF;
$scanMem = 0;
for ($run = 0; $run < 3; $run++) {
    gc_collect_cycles();
    $before = memory_get_usage(true);
    resetPeak();
    $t = hrtime(true);
    $scanner->top($query, 10);
    $best = min($best, (hrtime(true) - $t) / 1e9);
    $scanMem = max($scanMem, memory_get_peak_usage(true) - $before);
}
printf(
    "scan:  top 10, checksum on, best of 3: %.3f s (budget 1.0 s at 10,000 x 512), peak added %.1f MiB\n",
    $best,
    $scanMem / 1048576
);
$t = hrtime(true);
(new Scanner($path, new \IndianOpinion\PackedVectors\ScanOptions(verifyChecksum: false)))->top($query, 10);
printf("scan:  checksum off: %.3f s\n", (hrtime(true) - $t) / 1e9);
$t = hrtime(true);
(new Scanner($path, new \IndianOpinion\PackedVectors\ScanOptions(timeBudgetSeconds: 1000.0)))->top($query, 10);
printf("scan:  with a time budget (checked every 256 records): %.3f s\n\n", (hrtime(true) - $t) / 1e9);
unlink($path);
if (file_exists($path . '.lock')) {
    unlink($path . '.lock');
}

// 2. Unpack strategies and the inner loop, on $microRecords in memory.
printf("Micro benchmarks over %d records x %d dims (lower is better):\n", $microRecords, $dim);
$cases = [
    'block unpack, 1-based offsets, for loop (shipped)' => static function () use (
        $packed,
        $q,
        $dim,
        $words,
        $microRecords
    ): float {
        $perBlock = max(1, intdiv(32768, $words));
        $sum = 0.0;
        for ($from = 0; $from < $microRecords; $from += $perBlock) {
            $m = min($perBlock, $microRecords - $from);
            $vals = floats('g' . ($m * $words), $packed, $from * 4 * $words);
            for ($r = 0; $r < $m; $r++) {
                $base = $r * $words + 3;
                $dot = 0.0;
                for ($j = 0; $j < $dim; $j++) {
                    $dot += $q[$j] * $vals[$base + $j];
                }
                $sum += $dot;
            }
        }
        return $sum;
    },
    'block unpack, foreach over query' => static function () use ($packed, $q, $words, $microRecords): float {
        $perBlock = max(1, intdiv(32768, $words));
        $sum = 0.0;
        for ($from = 0; $from < $microRecords; $from += $perBlock) {
            $m = min($perBlock, $microRecords - $from);
            $vals = floats('g' . ($m * $words), $packed, $from * 4 * $words);
            for ($r = 0; $r < $m; $r++) {
                $base = $r * $words + 3;
                $dot = 0.0;
                foreach ($q as $j => $x) {
                    $dot += $x * $vals[$base + $j];
                }
                $sum += $dot;
            }
        }
        return $sum;
    },
    'per-record unpack + array_values' => static function () use (
        $packed,
        $q,
        $dim,
        $recordSize,
        $microRecords
    ): float {
        $sum = 0.0;
        for ($r = 0; $r < $microRecords; $r++) {
            $vals = array_values(floats('g' . $dim, $packed, $r * $recordSize + 8));
            $dot = 0.0;
            for ($j = 0; $j < $dim; $j++) {
                $dot += $q[$j] * $vals[$j];
            }
            $sum += $dot;
        }
        return $sum;
    },
    'per-record unpack, 1-keyed query, foreach' => static function () use (
        $packed,
        $q,
        $dim,
        $recordSize,
        $microRecords
    ): float {
        $q1 = array_combine(range(1, $dim), $q);
        $sum = 0.0;
        for ($r = 0; $r < $microRecords; $r++) {
            $vals = floats('g' . $dim, $packed, $r * $recordSize + 8);
            $dot = 0.0;
            foreach ($q1 as $j => $x) {
                $dot += $x * $vals[$j];
            }
            $sum += $dot;
        }
        return $sum;
    },
    'block unpack, unrolled by 4' => static function () use ($packed, $q, $dim, $words, $microRecords): float {
        $perBlock = max(1, intdiv(32768, $words));
        $sum = 0.0;
        $end4 = $dim - $dim % 4;
        for ($from = 0; $from < $microRecords; $from += $perBlock) {
            $m = min($perBlock, $microRecords - $from);
            $vals = floats('g' . ($m * $words), $packed, $from * 4 * $words);
            for ($r = 0; $r < $m; $r++) {
                $b = $r * $words + 3;
                $dot = 0.0;
                for ($j = 0; $j < $end4; $j += 4) {
                    $dot += $q[$j] * $vals[$b + $j] + $q[$j + 1] * $vals[$b + $j + 1]
                        + $q[$j + 2] * $vals[$b + $j + 2] + $q[$j + 3] * $vals[$b + $j + 3];
                }
                for (; $j < $dim; $j++) {
                    $dot += $q[$j] * $vals[$b + $j];
                }
                $sum += $dot;
            }
        }
        return $sum;
    },
    'block unpack, unrolled by 8' => static function () use ($packed, $q, $dim, $words, $microRecords): float {
        $perBlock = max(1, intdiv(32768, $words));
        $sum = 0.0;
        $end8 = $dim - $dim % 8;
        for ($from = 0; $from < $microRecords; $from += $perBlock) {
            $m = min($perBlock, $microRecords - $from);
            $vals = floats('g' . ($m * $words), $packed, $from * 4 * $words);
            for ($r = 0; $r < $m; $r++) {
                $b = $r * $words + 3;
                $dot = 0.0;
                for ($j = 0; $j < $end8; $j += 8) {
                    $dot += $q[$j] * $vals[$b + $j] + $q[$j + 1] * $vals[$b + $j + 1]
                        + $q[$j + 2] * $vals[$b + $j + 2] + $q[$j + 3] * $vals[$b + $j + 3]
                        + $q[$j + 4] * $vals[$b + $j + 4] + $q[$j + 5] * $vals[$b + $j + 5]
                        + $q[$j + 6] * $vals[$b + $j + 6] + $q[$j + 7] * $vals[$b + $j + 7];
                }
                for (; $j < $dim; $j++) {
                    $dot += $q[$j] * $vals[$b + $j];
                }
                $sum += $dot;
            }
        }
        return $sum;
    },
];
$reference = null;
$baseline = null;
foreach ($cases as $label => $case) {
    $case();
    $best = INF;
    $result = 0.0;
    for ($run = 0; $run < 5; $run++) {
        $t = hrtime(true);
        $result = $case();
        $best = min($best, (hrtime(true) - $t) / 1e9);
    }
    $reference ??= $result;
    $baseline ??= $best;
    if (abs($result - $reference) > 1e-6 * max(1.0, abs($reference))) {
        printf("  %-50s WRONG RESULT %.9f vs %.9f\n", $label, $result, $reference);
        continue;
    }
    printf("  %-50s %.4f s  %+.1f%%\n", $label, $best, ($best / $baseline - 1) * 100);
}

// 3. Top-k structure.
echo "\nTop-k over 100,000 random scores (best of 5):\n";
mt_srand(3);
$scores = [];
for ($i = 0; $i < 100000; $i++) {
    $scores[] = mt_rand() / mt_getrandmax() * 2 - 1;
}
foreach ([10, 1000] as $k) {
    $heap = static function () use ($scores, $k): int {
        $topk = new TopK($k);
        $floor = -INF;
        foreach ($scores as $i => $s) {
            if ($s >= $floor) {
                $floor = $topk->offer($s, $i, 0);
            }
        }
        return count($topk->sorted());
    };
    $sorted = static function () use ($scores, $k): int {
        /** @var list<float> $kept */
        $kept = [];
        $n = 0;
        foreach ($scores as $s) {
            if ($n === $k && $s <= $kept[$n - 1]) {
                continue;
            }
            $lo = 0;
            $hi = $n;
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if ($kept[$mid] >= $s) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            array_splice($kept, $lo, 0, [$s]);
            if (++$n > $k) {
                array_pop($kept);
                $n = $k;
            }
        }
        return count($kept);
    };
    foreach (['min-heap (shipped)' => $heap, 'sorted array, binary insertion' => $sorted] as $label => $fn) {
        $best = INF;
        for ($run = 0; $run < 5; $run++) {
            $t = hrtime(true);
            $fn();
            $best = min($best, (hrtime(true) - $t) / 1e9);
        }
        printf("  k=%-5d %-32s %.4f s\n", $k, $label, $best);
    }
}

function jitState(): string
{
    if (!function_exists('opcache_get_status')) {
        return 'off';
    }
    $status = opcache_get_status(false);

    return is_array($status) && !empty($status['jit']['on']) ? 'on' : 'off';
}

function resetPeak(): void
{
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
}

/**
 * unpack() for float formats, failing loudly instead of returning false.
 *
 * @return array<int, float>
 */
function floats(string $format, string $data, int $offset): array
{
    $values = unpack($format, $data, $offset);
    if ($values === false) {
        throw new RuntimeException('unpack failed');
    }

    /** @var array<int, float> $values */
    return $values;
}
