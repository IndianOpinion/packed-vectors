<?php

// Opens a scanner on argv[1], scans with small chunks, pauses after the
// first chunk (prints "paused", waits for a line on stdin), then prints
// the hits and the report as JSON.

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PackedVectors\ScanOptions;

$scanner = new class ($argv[1], new ScanOptions(chunkBytes: 1)) extends Scanner {
    private bool $paused = false;

    protected function afterChunk(int $recordsSoFar): void
    {
        if (!$this->paused) {
            $this->paused = true;
            echo "paused\n";
            fgets(STDIN);
        }
    }
};
$dim = (int) $argv[2];
$result = $scanner->top(array_fill(0, $dim, 1.0), 10000);
$hits = [];
foreach ($result->hits() as $hit) {
    $hits[] = [$hit->id, $hit->ref];
}
echo json_encode([
    'hits' => $hits,
    'scanned' => $result->report()->scanned(),
    'verified' => $result->report()->checksumVerified(),
    'built_at' => $result->report()->builtAt(),
]), "\n";
