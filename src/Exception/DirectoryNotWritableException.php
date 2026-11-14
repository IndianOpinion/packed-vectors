<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

/**
 * The target's directory does not exist or is not writable by this process.
 *
 * The builder never falls back to a system temporary directory, because a
 * rename across filesystems is a copy that readers can see half done.
 */
final class DirectoryNotWritableException extends BuildFailedException
{
    public function __construct(string $path, public readonly string $directory, string $detail)
    {
        parent::__construct($path, sprintf('directory %s %s', $directory, $detail));
    }
}
