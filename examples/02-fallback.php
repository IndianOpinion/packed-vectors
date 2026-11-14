<?php

/**
 * The fallback chunked scan over your own store, with keyset pagination.
 *
 *     php examples/02-fallback.php
 *
 * The "table" here is an in-memory array so the example runs anywhere. With
 * PDO the page callback would be:
 *
 *     $stmt = $pdo->prepare(
 *         'SELECT id, doc_id, embedding FROM chunks WHERE id > ? ORDER BY id LIMIT ?'
 *     );
 *     $fetch = function (int $afterId, int $limit) use ($stmt): array {
 *         $stmt->execute([$afterId, $limit]);
 *         $page = [];
 *         foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $doc, $json]) {
 *             $page[] = [(int) $id, (int) $doc, json_decode($json, true)];
 *         }
 *         $stmt->closeCursor(); // release the driver's result buffer
 *         return $page;
 *     };
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\PackedVectors\ChunkedScanner;

$dim = 4;
$table = [];
mt_srand(7);
for ($id = 1; $id <= 2500; $id++) {
    $table[$id] = [mt_rand() / mt_getrandmax(), mt_rand() / mt_getrandmax(), 0.1, -0.2];
}
$table[17] = [1.0, 2.0];          // wrong length: skipped and counted
$table[18] = '[1.0, 2.0, 3, 4]'; // still JSON: skipped and counted

$fetch = static function (int $afterId, int $limit) use ($table): array {
    $page = [];
    foreach ($table as $id => $vector) {
        if ($id > $afterId) {
            $page[] = [$id, 0, $vector];
            if (count($page) === $limit) {
                break;
            }
        }
    }

    return $page;
};

$result = (new ChunkedScanner($fetch, $dim))->top([0.9, 0.1, 0.1, -0.2], 3);
foreach ($result->hits() as $hit) {
    printf("id %4d  score %.4f\n", $hit->id, $hit->score);
}
echo $result->report(), "\n";
