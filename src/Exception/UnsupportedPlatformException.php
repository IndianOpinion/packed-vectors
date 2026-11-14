<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

/**
 * The PHP build cannot run this library (for example a 32-bit build).
 */
final class UnsupportedPlatformException extends PackedVectorsException
{
}
