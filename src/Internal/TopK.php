<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Internal;

use IndianOpinion\PackedVectors\Hit;

/**
 * Bounded binary min-heap of the k best hits seen so far.
 *
 * The root is the worst kept hit, so a candidate is compared with one value
 * and usually rejected at once. Ordering: higher score is better; for equal
 * scores, lower id is better, then lower ref.
 *
 * In best-per-ref mode the heap also maps each kept ref to its slot, so a
 * better record for a ref already kept replaces that entry in place. This
 * is exact: an evicted entry was below the cut, so any later record with
 * the same ref and a lower score is below the cut too.
 *
 * Plain parallel arrays are used instead of SplMinHeap so the hot path
 * (one float comparison against $floor) costs no method call.
 *
 * @internal
 */
final class TopK
{
    /** @var array<int, float> */
    private array $score = [];
    /** @var array<int, int> */
    private array $id = [];
    /** @var array<int, int> */
    private array $ref = [];
    /** @var array<int, int> ref => slot, best-per-ref mode only */
    private array $slotOfRef = [];
    private int $size = 0;

    private float $floor = -INF;

    public function __construct(private readonly int $k, private readonly bool $bestPerRef = false)
    {
    }

    /**
     * Offers one candidate. Returns the new floor.
     */
    public function offer(float $score, int $id, int $ref): float
    {
        if ($this->bestPerRef && isset($this->slotOfRef[$ref])) {
            $slot = $this->slotOfRef[$ref];
            if ($this->better($score, $id, $ref, $slot)) {
                $this->score[$slot] = $score;
                $this->id[$slot] = $id;
                $this->siftDown($slot);
                $this->updateFloor();
            }

            return $this->floor;
        }

        if ($this->size < $this->k) {
            $slot = $this->size++;
            $this->place($slot, $score, $id, $ref);
            $this->siftUp($slot);
            $this->updateFloor();

            return $this->floor;
        }

        if (!$this->better($score, $id, $ref, 0)) {
            return $this->floor;
        }
        if ($this->bestPerRef) {
            unset($this->slotOfRef[$this->ref[0]]);
        }
        $this->place(0, $score, $id, $ref);
        $this->siftDown(0);
        $this->updateFloor();

        return $this->floor;
    }

    /**
     * The kept hits, best first.
     *
     * @return list<Hit>
     */
    public function sorted(): array
    {
        if ($this->size === 0) {
            return [];
        }
        $order = range(0, $this->size - 1);
        usort($order, function (int $a, int $b): int {
            if ($this->score[$a] !== $this->score[$b]) {
                return $this->score[$a] > $this->score[$b] ? -1 : 1;
            }
            if ($this->id[$a] !== $this->id[$b]) {
                return $this->id[$a] <=> $this->id[$b];
            }

            return $this->ref[$a] <=> $this->ref[$b];
        });
        $hits = [];
        foreach ($order as $slot) {
            $hits[] = new Hit($this->id[$slot], $this->ref[$slot], $this->score[$slot]);
        }

        return $hits;
    }

    /**
     * Score of the worst kept hit once the heap is full, else -INF.
     * A candidate strictly below it can be dropped without a call.
     */
    public function floor(): float
    {
        return $this->floor;
    }

    public function count(): int
    {
        return $this->size;
    }

    /** True when the candidate ranks above the entry in $slot. */
    private function better(float $score, int $id, int $ref, int $slot): bool
    {
        if ($score !== $this->score[$slot]) {
            return $score > $this->score[$slot];
        }
        if ($id !== $this->id[$slot]) {
            return $id < $this->id[$slot];
        }

        return $ref < $this->ref[$slot];
    }

    /** True when slot $a ranks below slot $b. */
    private function worse(int $a, int $b): bool
    {
        if ($this->score[$a] !== $this->score[$b]) {
            return $this->score[$a] < $this->score[$b];
        }
        if ($this->id[$a] !== $this->id[$b]) {
            return $this->id[$a] > $this->id[$b];
        }

        return $this->ref[$a] > $this->ref[$b];
    }

    private function place(int $slot, float $score, int $id, int $ref): void
    {
        $this->score[$slot] = $score;
        $this->id[$slot] = $id;
        $this->ref[$slot] = $ref;
        if ($this->bestPerRef) {
            $this->slotOfRef[$ref] = $slot;
        }
    }

    private function swap(int $a, int $b): void
    {
        [$this->score[$a], $this->score[$b]] = [$this->score[$b], $this->score[$a]];
        [$this->id[$a], $this->id[$b]] = [$this->id[$b], $this->id[$a]];
        [$this->ref[$a], $this->ref[$b]] = [$this->ref[$b], $this->ref[$a]];
        if ($this->bestPerRef) {
            $this->slotOfRef[$this->ref[$a]] = $a;
            $this->slotOfRef[$this->ref[$b]] = $b;
        }
    }

    private function siftUp(int $slot): void
    {
        while ($slot > 0) {
            $parent = ($slot - 1) >> 1;
            if (!$this->worse($slot, $parent)) {
                return;
            }
            $this->swap($slot, $parent);
            $slot = $parent;
        }
    }

    private function siftDown(int $slot): void
    {
        while (true) {
            $left = 2 * $slot + 1;
            if ($left >= $this->size) {
                return;
            }
            $worst = $left;
            $right = $left + 1;
            if ($right < $this->size && $this->worse($right, $left)) {
                $worst = $right;
            }
            if (!$this->worse($worst, $slot)) {
                return;
            }
            $this->swap($slot, $worst);
            $slot = $worst;
        }
    }

    private function updateFloor(): void
    {
        $this->floor = $this->size >= $this->k ? $this->score[0] : -INF;
    }
}
