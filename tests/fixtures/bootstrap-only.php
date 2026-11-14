<?php

// Loads the library through bootstrap.php only (no Composer autoloader),
// builds a tiny file in the system temporary directory and scans it.

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\BuildOptions;
use IndianOpinion\PackedVectors\Scanner;

if (class_exists(\Composer\Autoload\ClassLoader::class, false)) {
    fwrite(STDERR, "Composer is loaded\n");
    exit(1);
}
$dir = sys_get_temp_dir() . '/packed-vectors-bootstrap-' . bin2hex(random_bytes(4));
mkdir($dir);
$path = $dir . '/v.bin';
(new Builder($path, 2, (new BuildOptions())->withFsync(false)->withLock(false)))
    ->build([[1, 0, [1, 0]], [2, 0, [0, 1]]]);
$hit = (new Scanner($path))->top([0, 1], 1)->hits()[0];
unlink($path);
rmdir($dir);
echo 'ok ', $hit->id, "\n";
