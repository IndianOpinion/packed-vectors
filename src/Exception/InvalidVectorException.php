<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Exception;

/**
 * A vector (query or build record) is not usable: not a list, wrong length,
 * non-numeric, non-finite, out of float32 range, zero norm, or a bad id.
 *
 * For build records, $index is the zero-based position in the source and
 * $id is the id as given (it may be invalid itself).
 */
final class InvalidVectorException extends PackedVectorsException
{
    public function __construct(
        public readonly string $reason,
        string $detail,
        public readonly ?int $index = null,
        public readonly mixed $id = null
    ) {
        $where = $index === null ? '' : sprintf(' (record %d, id %s)', $index, self::describe($id));
        parent::__construct(sprintf('Invalid vector (%s): %s%s', $reason, $detail, $where));
    }

    private static function describe(mixed $id): string
    {
        if (is_int($id)) {
            return (string) $id;
        }
        if (is_string($id)) {
            return '"' . substr($id, 0, 40) . '"';
        }
        return get_debug_type($id);
    }
}
