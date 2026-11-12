<?php

// Holds an exclusive flock on argv[1] for argv[2] seconds, then exits.
// Prints "locked" once the lock is held.

declare(strict_types=1);

$handle = fopen($argv[1], 'c');
if ($handle === false || !flock($handle, LOCK_EX)) {
    fwrite(STDERR, "cannot lock\n");
    exit(1);
}
echo "locked\n";
usleep((int) ((float) $argv[2] * 1e6));
flock($handle, LOCK_UN);
fclose($handle);
exit(0);
