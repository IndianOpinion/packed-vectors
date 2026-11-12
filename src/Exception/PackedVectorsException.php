<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

use RuntimeException;

/**
 * Base class for the package's runtime failures.
 */
abstract class PackedVectorsException extends RuntimeException implements PackedVectorsError
{
}
