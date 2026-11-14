<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

/**
 * A caller passed an argument outside its documented range or shape.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements PackedVectorsError
{
}
