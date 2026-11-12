<?php

/**
 * Use packed-vectors without Composer:
 *
 *     require '/path/to/packed-vectors/bootstrap.php';
 *
 * Registers an autoloader for the IndianOpinion\PackedVectors namespace and
 * refuses to load on a 32-bit PHP build.
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Exception/PackedVectorsError.php';
require_once __DIR__ . '/src/Exception/PackedVectorsException.php';
require_once __DIR__ . '/src/Exception/UnsupportedPlatformException.php';
require_once __DIR__ . '/src/Environment.php';

\IndianOpinion\PackedVectors\Environment::assertSupported();

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\PackedVectors\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
