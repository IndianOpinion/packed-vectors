<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Internal;

use IndianOpinion\PackedVectors\Exception\DimensionMismatchException;
use IndianOpinion\PackedVectors\Exception\InvalidArgumentException;
use IndianOpinion\PackedVectors\Exception\InvalidVectorException;
use IndianOpinion\PackedVectors\Format;

/**
 * Vector validation and normalisation, in double precision.
 *
 * @internal
 */
final class Vectors
{
    public const MAX_K = 10000;

    private function __construct()
    {
    }

    /**
     * Checks a record id or ref.
     */
    public static function validKey(mixed $key): bool
    {
        return is_int($key) && $key >= 0 && $key <= Format::MAX_ID;
    }

    /**
     * Checks one vector. Returns the skip reason, or null when the vector is
     * usable, and sets $norm to its Euclidean norm.
     *
     * The norm is computed with scaling by the largest magnitude, so values
     * near the float32 limit do not overflow and tiny values do not
     * underflow to zero.
     *
     * @param array<array-key, mixed> $vector
     * @param bool $outOfRangeCheck refuse values outside the float32 range
     * @param-out float $norm
     */
    public static function problem(array $vector, int $dim, bool $outOfRangeCheck, float &$norm = 0.0): ?string
    {
        $norm = 0.0;
        if (!array_is_list($vector)) {
            return 'not_list';
        }
        if (count($vector) !== $dim) {
            return 'length';
        }
        $max = 0.0;
        foreach ($vector as $v) {
            if (!is_float($v) && !is_int($v)) {
                return 'not_numeric';
            }
            $v = (float) $v;
            if (is_nan($v) || is_infinite($v)) {
                return 'non_finite';
            }
            $a = abs($v);
            if ($outOfRangeCheck && $a > Format::FLOAT32_MAX) {
                return 'out_of_range';
            }
            if ($a > $max) {
                $max = $a;
            }
        }
        if ($max === 0.0) {
            return 'zero_norm';
        }
        $sum = 0.0;
        foreach ($vector as $v) {
            $s = $v / $max;
            $sum += $s * $s;
        }
        $norm = $max * sqrt($sum);

        return null;
    }

    /**
     * Validates a query's shape and values and returns its norm. Does not
     * check the dimension, so it can run before any file is opened.
     *
     * @param array<array-key, mixed> $query
     * @throws InvalidVectorException
     */
    public static function queryNorm(array $query): float
    {
        if (!array_is_list($query)) {
            throw new InvalidVectorException(
                'not_list',
                'the query must be a list with keys 0 to n-1; unpack() returns keys starting at 1, '
                . 'so pass array_values(unpack(...))'
            );
        }
        $norm = 0.0;
        $problem = self::problem($query, count($query), false, $norm);
        if ($problem !== null) {
            throw new InvalidVectorException($problem, self::describe($problem, 'query'));
        }

        return $norm;
    }

    /**
     * Checks the query length against the file and returns the query
     * divided by $norm, as a 0-based list of doubles. Never truncates or pads.
     *
     * @param list<float|int> $query
     * @return list<float>
     * @throws DimensionMismatchException
     */
    public static function unitQuery(array $query, float $norm, int $dim, string $path = ''): array
    {
        if (count($query) !== $dim) {
            throw new DimensionMismatchException($dim, count($query), $path);
        }
        $out = [];
        foreach ($query as $v) {
            $out[] = $v / $norm;
        }

        return $out;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertK(int $k): void
    {
        if ($k < 1 || $k > self::MAX_K) {
            throw new InvalidArgumentException(sprintf('k must be 1 to %d, got %d', self::MAX_K, $k));
        }
    }

    public static function describe(string $reason, string $what): string
    {
        return match ($reason) {
            'not_list' => $what . ' is not a list (keys must be 0 to n-1)',
            'length' => $what . ' has the wrong number of values',
            'not_numeric' => $what . ' holds a value that is not an int or a float',
            'non_finite' => $what . ' holds NaN or an infinite value',
            'out_of_range' => $what . ' holds a value outside the float32 range',
            'zero_norm' => $what . ' has zero length and cannot be normalised',
            'bad_id' => $what . ' has an id or ref that is not an integer from 0 to 4294967295',
            default => $what . ' is invalid',
        };
    }
}
