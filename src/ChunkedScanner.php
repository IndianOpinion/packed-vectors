<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors;

use IndianOpinion\PackedVectors\Exception\BudgetExceededException;
use IndianOpinion\PackedVectors\Exception\DimensionMismatchException;
use IndianOpinion\PackedVectors\Exception\InvalidArgumentException;
use IndianOpinion\PackedVectors\Exception\InvalidVectorException;
use IndianOpinion\PackedVectors\Internal\TopK;
use IndianOpinion\PackedVectors\Internal\Vectors;

/**
 * The fallback: ranks vectors the caller supplies page by page from its own
 * store, with the same checks, scoring and ordering as Scanner.
 *
 * The page callback receives the last id seen (0 at the start) and a page
 * size, and returns records with ids strictly greater than that id, in
 * ascending id order (keyset pagination). So the fallback can only rank
 * records whose id is 1 or more.
 */
final class ChunkedScanner
{
    public const MAX_PAGE_SIZE = 100000;

    /** @var callable(int, int): iterable<mixed> */
    private $fetchPage;

    private readonly ScanOptions $options;

    /**
     * @param callable(int $afterId, int $limit): iterable<mixed> $fetchPage
     */
    public function __construct(
        callable $fetchPage,
        private readonly int $dim,
        ?ScanOptions $options = null,
        private readonly int $pageSize = 400
    ) {
        Environment::assertSupported();
        Format::assertDim($dim);
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException(sprintf(
                'pageSize must be 1 to %d, got %d',
                self::MAX_PAGE_SIZE,
                $pageSize
            ));
        }
        $this->fetchPage = $fetchPage;
        $this->options = $options ?? new ScanOptions();
    }

    /**
     * The k best records for $query, best first.
     *
     * Records with the wrong length, a non-numeric, non-finite or out of
     * range value, a zero norm, a bad ref, or a vector that is not an array
     * (for example a JSON string) are skipped and counted, never fatal.
     *
     * @param array<array-key, mixed> $query
     * @throws InvalidArgumentException for a bad k, or a page callback that breaks the contract
     * @throws InvalidVectorException for a bad query
     * @throws DimensionMismatchException when the query length is not dim
     * @throws BudgetExceededException
     */
    public function top(array $query, int $k): ScanResult
    {
        $started = (int) hrtime(true);
        Vectors::assertK($k);
        $norm = Vectors::queryNorm($query);
        /** @var list<float|int> $query */
        $q = Vectors::unitQuery($query, $norm, $this->dim);

        $dim = $this->dim;
        $min = $this->options->minScore ?? -INF;
        $topk = new TopK($k, $this->options->bestPerRef);
        $floor = -INF;
        $budget = $this->options->timeBudgetSeconds;
        $deadline = $budget === null ? null : $started + (int) ($budget * 1e9);
        $checkEvery = Scanner::budgetCheckInterval($dim);
        $scanned = 0;
        $skipped = 0;
        $after = 0;

        while (true) {
            $page = ($this->fetchPage)($after, $this->pageSize);
            if (!is_iterable($page)) {
                throw new InvalidArgumentException(sprintf(
                    'the page callback must return an iterable, got %s',
                    get_debug_type($page)
                ));
            }
            $inPage = 0;
            foreach ($page as $item) {
                $inPage++;
                if ($inPage > $this->pageSize) {
                    throw new InvalidArgumentException(sprintf(
                        'the page callback returned more than the %d records asked for',
                        $this->pageSize
                    ));
                }
                [$id, $ref, $vector] = self::unpackItem($item);
                if (!is_int($id) || $id <= $after || $id > Format::MAX_ID) {
                    throw new InvalidArgumentException(sprintf(
                        'the page callback returned id %s after id %d; ids must be integers, strictly increasing, '
                        . 'and at most %d',
                        is_int($id) ? (string) $id : get_debug_type($id),
                        $after,
                        Format::MAX_ID
                    ));
                }
                $after = $id;
                $scanned++;

                $vnorm = 0.0;
                if (
                    !Vectors::validKey($ref)
                    || !is_array($vector)
                    || Vectors::problem($vector, $dim, true, $vnorm) !== null
                ) {
                    $skipped++;
                } else {
                    /** @var int $ref */
                    /** @var list<float|int> $vector */
                    $dot = 0.0;
                    for ($j = 0; $j < $dim; $j++) {
                        $dot += $q[$j] * $vector[$j];
                    }
                    $score = $dot / $vnorm;
                    if ($score > 1.0) {
                        $score = 1.0;
                    } elseif ($score < -1.0) {
                        $score = -1.0;
                    }
                    if ($score >= $floor && $score >= $min) {
                        $floor = $topk->offer($score, $id, $ref);
                    }
                }

                if ($deadline !== null && ($scanned % $checkEvery) === 0) {
                    self::checkBudget($deadline, $started, $scanned, (float) $budget);
                }
            }
            unset($page, $item, $vector);
            if ($deadline !== null) {
                self::checkBudget($deadline, $started, $scanned, (float) $budget);
            }
            if ($inPage < $this->pageSize) {
                break;
            }
        }

        $report = new ScanReport(
            $scanned,
            $skipped,
            0,
            ((int) hrtime(true) - $started) / 1e9,
            $dim,
            null,
            false,
            false
        );

        return new ScanResult($topk->sorted(), $report);
    }

    private static function checkBudget(int $deadline, int $started, int $scanned, float $budget): void
    {
        $now = (int) hrtime(true);
        if ($now > $deadline) {
            throw new BudgetExceededException($scanned, ($now - $started) / 1e9, $budget);
        }
    }

    /**
     * @return array{0: mixed, 1: mixed, 2: mixed}
     */
    private static function unpackItem(mixed $item): array
    {
        if ($item instanceof Record) {
            return [$item->id, $item->ref, $item->vector];
        }
        if (is_array($item) && count($item) === 3 && array_is_list($item)) {
            return [$item[0], $item[1], $item[2]];
        }
        throw new InvalidArgumentException(sprintf(
            'the page callback must yield Record objects or lists [id, ref, vector], got %s',
            get_debug_type($item)
        ));
    }
}
