<?php

/**
 * Build a packed file from a generator, then find the nearest neighbours.
 *
 *     php examples/01-build-and-scan.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PackedVectors\ScanOptions;

// In production, keep the file outside the web root, e.g. /var/lib/<app>/.
$dir = sys_get_temp_dir() . '/packed-vectors-example-' . bin2hex(random_bytes(4));
mkdir($dir, 0750);
$path = $dir . '/articles.vec';

$dim = 8;

// Any iterable works. A generator keeps memory flat however large the
// corpus is: fetch rows page by page and yield them one at a time.
$records = (static function () use ($dim): Generator {
    mt_srand(42);
    for ($id = 1; $id <= 1000; $id++) {
        $vector = [];
        for ($j = 0; $j < $dim; $j++) {
            $vector[] = mt_rand() / mt_getrandmax() - 0.5;
        }
        $documentId = intdiv($id - 1, 4) + 1; // four chunks per document
        yield [$id, $documentId, $vector];
    }
})();

$report = (new Builder($path, $dim))->build($records);
echo $report, "\n";

$query = [0.3, -0.1, 0.2, 0.4, -0.2, 0.1, 0.0, 0.25];

$result = (new Scanner($path))->top($query, 5);
echo "Top 5 chunks:\n";
foreach ($result->hits() as $hit) {
    printf("  id %4d  document %3d  score %.4f\n", $hit->id, $hit->ref, $hit->score);
}
echo $result->report(), "\n";

// One hit per document, with a minimum score and a time budget.
$options = new ScanOptions(minScore: 0.30, bestPerRef: true, timeBudgetSeconds: 0.5);
echo "Top 5 documents (best chunk each, score >= 0.30):\n";
foreach ((new Scanner($path, $options))->top($query, 5)->hits() as $hit) {
    printf("  document %3d  via chunk %4d  score %.4f\n", $hit->ref, $hit->id, $hit->score);
}

unlink($path);
unlink($path . '.lock');
rmdir($dir);
