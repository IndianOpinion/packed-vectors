<?php

// Rebuilds argv[1] argv[3] times. Generation g writes argv[4] records whose
// ref is g, so a reader can tell generations apart. Prints "done".

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\BuildOptions;

$path = $argv[1];
$dim = (int) $argv[2];
$times = (int) $argv[3];
$count = (int) $argv[4];
$builder = new Builder($path, $dim, (new BuildOptions())->withFsync(false));
for ($g = 1; $g <= $times; $g++) {
    $records = (static function () use ($g, $dim, $count): Generator {
        for ($i = 1; $i <= $count; $i++) {
            $v = array_fill(0, $dim, 0.0);
            $v[$i % $dim] = 1.0;
            $v[0] += $g / 100;
            yield [$i, $g, $v];
        }
    })();
    $report = $builder->build($records);
    if ($report->written() !== $count) {
        fwrite(STDERR, "generation $g wrote {$report->written()}\n");
        exit(1);
    }
}
echo "done\n";
