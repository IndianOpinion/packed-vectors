<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception this package throws.
 *
 * Catch it to handle the whole family in one clause.
 */
interface PackedVectorsError extends Throwable
{
}
