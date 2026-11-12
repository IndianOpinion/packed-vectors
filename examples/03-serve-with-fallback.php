<?php

/**
 * The request-path pattern: scan the packed file; if it is missing,
 * corrupt or built for another dimension, use the slow safe path for this
 * request and let the job rebuild it. Plus the job's rebuildIfStale().
 *
 *     php examples/03-serve-with-fallback.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\ChunkedScanner;
use IndianOpinion\PackedVectors\Exception\UnavailableException;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PackedVectors\ScanOptions;
use IndianOpinion\PackedVectors\ScanResult;

$dir = sys_get_temp_dir() . '/packed-vectors-example-' . bin2hex(random_bytes(4));
mkdir($dir, 0750);
$path = $dir . '/corpus.vec';
$dim = 3;

/** @var array<int, list<float>> $store your source of truth */
$store = [1 => [1.0, 0.0, 0.0], 2 => [0.0, 1.0, 0.0], 3 => [0.7, 0.7, 0.0], 4 => [0.0, 0.0, 1.0]];

$fetchPage = static function (int $afterId, int $limit) use ($store): array {
    $page = [];
    foreach ($store as $id => $v) {
        if ($id > $afterId && count($page) < $limit) {
            $page[] = [$id, 0, $v];
        }
    }

    return $page;
};

/**
 * @param list<float> $query
 */
function search(string $path, array $query, callable $fetchPage, int $dim): ScanResult
{
    $options = new ScanOptions(timeBudgetSeconds: 0.5);
    try {
        return (new Scanner($path, $options))->top($query, 2);
    } catch (UnavailableException $e) {
        // Missing, unreadable, corrupt, unknown version or wrong dimension.
        // Log it, queue one (debounced) rebuild, and serve this request slowly.
        error_log('packed-vectors unavailable, using fallback: ' . $e->getMessage());

        return (new ChunkedScanner($fetchPage, $dim, $options))->top($query, 2);
    }
}

// 1. Before the first build: the fallback answers.
$result = search($path, [1.0, 0.2, 0.0], $fetchPage, $dim);
printf(
    "before build: top id %d (builtAt %s)\n",
    $result->hits()[0]->id,
    var_export($result->report()->builtAt(), true)
);

// 2. The background job (cron, queue) keeps the file fresh.
$builder = new Builder($path, $dim);
$records = static function () use ($store): Generator {
    foreach ($store as $id => $v) {
        yield [$id, 0, $v];
    }
};
$report = $builder->rebuildIfStale(3600, $records);
echo 'job run 1: ', $report === null ? 'fresh, nothing to do' : (string) $report, "\n";
$report = $builder->rebuildIfStale(3600, $records);
echo 'job run 2: ', $report === null ? 'fresh, nothing to do' : (string) $report, "\n";

// 3. After the build: the packed file answers.
$result = search($path, [1.0, 0.2, 0.0], $fetchPage, $dim);
printf("after build:  top id %d (builtAt %d)\n", $result->hits()[0]->id, (int) $result->report()->builtAt());

unlink($path);
unlink($path . '.lock');
rmdir($dir);
