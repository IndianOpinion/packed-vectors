<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

/**
 * The packed file cannot answer this query: it is missing, unreadable,
 * corrupt, of an unknown version, or built for another dimension.
 *
 * Catching this is the documented way to decide "fall back to the chunked
 * scan for this request and queue one rebuild".
 */
interface UnavailableException extends PackedVectorsError
{
}
