<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests;

use IndianOpinion\PackedVectors\Hit;
use IndianOpinion\PackedVectors\Internal\TopK;
use PHPUnit\Framework\TestCase;

/**
 * The bounded min-heap behind case 37 and case 40.
 */
final class TopKTest extends TestCase
{
    public function testKeepsTheKBestInOrder(): void
    {
        $topk = new TopK(3);
        self::assertSame(-INF, $topk->floor());
        foreach ([[0.1, 1], [0.9, 2], [0.5, 3], [0.7, 4], [0.2, 5], [0.95, 6]] as [$score, $id]) {
            $topk->offer($score, $id, 0);
        }
        self::assertSame(3, $topk->count());
        self::assertSame(0.7, $topk->floor());
        self::assertSame([6, 2, 4], array_map(static fn (Hit $h): int => $h->id, $topk->sorted()));
    }

    public function testEmpty(): void
    {
        self::assertSame([], (new TopK(5))->sorted());
    }

    public function testRandomAgainstSort(): void
    {
        mt_srand(80);
        for ($round = 0; $round < 300; $round++) {
            $k = mt_rand(1, 20);
            $bestPerRef = mt_rand(0, 1) === 1;
            $topk = new TopK($k, $bestPerRef);
            $all = [];
            $n = mt_rand(0, 80);
            for ($i = 0; $i < $n; $i++) {
                $hit = new Hit(mt_rand(1, 50), mt_rand(0, 6), mt_rand(-4, 4) / 4);
                $all[] = $hit;
                if ($hit->score >= $topk->floor()) {
                    $topk->offer($hit->score, $hit->id, $hit->ref);
                }
            }
            usort(
                $all,
                static fn (Hit $a, Hit $b): int => [$b->score, $a->id, $a->ref] <=> [$a->score, $b->id, $b->ref]
            );
            if ($bestPerRef) {
                $seen = [];
                $all = array_values(array_filter($all, static function (Hit $h) use (&$seen): bool {
                    $fresh = !isset($seen[$h->ref]);
                    $seen[$h->ref] = true;

                    return $fresh;
                }));
            }
            self::assertEquals(array_slice($all, 0, $k), $topk->sorted(), 'round ' . $round);
        }
    }
}
