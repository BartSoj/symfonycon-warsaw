<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Cobra 666: listens to heavy metal, which makes it super smart.
 * - Never hits walls, trails or itself (only ever picks safe cells when one exists).
 * - Avoids cells the opponent could also strike next tick (no head-on bites).
 * - Most important: keeps as much decision space as possible (flood fill + territory).
 * - Shrinks the opponent's space (Voronoi territory: cells it reaches first).
 * - Learns how often the opponent goes straight and fears that cell accordingly.
 * - Ties are broken by prime numbers, with a little metal chaos to stay unpredictable.
 */
final class Cobra666Bot implements Bot
{
    private const PRIMES = [2, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47, 53, 59, 61, 67, 71, 73, 79, 83, 89, 97];

    private ?Direction $lastOppDir = null;
    private int $oppStraight = 1;
    private int $oppTurns = 1;

    public function name(): string { return 'Cobra 666'; }
    public function author(): string { return 'Metalhead'; }
    public function color(): string { return '#000000'; }

    public function move(Arena $arena): Direction
    {
        $me = $arena->me;
        $opp = $arena->opponents[0] ?? null;
        $w = $arena->width;
        $h = $arena->height;

        // Observe and learn: does the opponent like to go straight?
        if ($opp !== null) {
            if ($this->lastOppDir !== null) {
                $opp->direction === $this->lastOppDir ? $this->oppStraight++ : $this->oppTurns++;
            }
            $this->lastOppDir = $opp->direction;
        }

        $free = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($arena->isFree($x, $y)) {
                    $free[$y * $w + $x] = true;
                }
            }
        }

        // Cells the opponent could move into next tick, with how likely it is.
        $oppNext = [];
        if ($opp !== null) {
            $straightRate = $this->oppStraight / ($this->oppStraight + $this->oppTurns);
            foreach (Direction::cases() as $d) {
                $x = $opp->x + $d->dx();
                $y = $opp->y + $d->dy();
                if (isset($free[$y * $w + $x]) && $x >= 0 && $x < $w) {
                    $oppNext[$y * $w + $x] = $d === $opp->direction ? $straightRate : (1 - $straightRate) / 2;
                }
            }
        }

        $isPrimeTick = in_array($arena->tick % 97, self::PRIMES, true);
        $prime = self::PRIMES[$arena->tick % count(self::PRIMES)];

        $best = null;
        $bestScore = -INF;
        $options = [$me->direction, $me->direction->left(), $me->direction->right()];

        foreach ($options as $i => $d) {
            $nx = $me->x + $d->dx();
            $ny = $me->y + $d->dy();
            if (!$arena->isSafe($d)) {
                continue;
            }
            $cell = $ny * $w + $nx;
            $grid = $free;
            unset($grid[$cell]);

            $myDist = $this->bfs($grid, $w, $h, [$cell]);
            $space = count($myDist);
            $score = 0.0;

            $oppStarts = [];
            foreach ($oppNext as $c => $_) {
                if ($c !== $cell) {
                    $oppStarts[] = $c;
                }
            }

            $oppDist = $oppStarts ? $this->bfs($grid, $w, $h, $oppStarts) : [];
            $touching = false;
            foreach ($oppStarts as $c) {
                if (isset($myDist[$c])) {
                    $touching = true;
                    break;
                }
            }

            if (!$touching) {
                // Separated: just survive longest, hug walls to waste no space.
                $score = $space * 1000 - count($oppDist) * 10 - $this->freeNeighbours($grid, $w, $h, $cell) * 5;
            } else {
                // Territory: cells I reach first are mine, the rest shrink the opponent.
                $mine = 0;
                $theirs = 0;
                foreach ($myDist as $c => $dm) {
                    $do = $oppDist[$c] ?? PHP_INT_MAX;
                    if ($dm + 1 < $do) {
                        $mine++;
                    } elseif ($do < $dm + 1) {
                        $theirs++;
                    }
                }
                foreach ($oppDist as $c => $_) {
                    if (!isset($myDist[$c])) {
                        $theirs++;
                    }
                }
                $score = $space * 40 + ($mine - $theirs) * 60;
            }

            // Careful and mindful: no head-on bites.
            if (isset($oppNext[$cell])) {
                $score -= 1_000_000 * (0.3 + $oppNext[$cell]);
            }

            // Prime number wisdom: on prime ticks the cobra prefers to strike sideways, otherwise straight.
            $score += ($isPrimeTick ? ($i === 0 ? 0 : $prime % 7) : ($i === 0 ? 3 : 0));
            // A pinch of heavy-metal chaos to stay unpredictable.
            $score += mt_rand(0, 2);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best ?? $me->direction;
    }

    /**
     * @param array<int, bool> $grid
     * @param list<int>        $starts
     * @return array<int, int> cell => distance
     */
    private function bfs(array $grid, int $w, int $h, array $starts): array
    {
        $dist = [];
        $queue = [];
        foreach ($starts as $s) {
            $dist[$s] = 0;
            $queue[] = $s;
        }
        for ($q = 0; $q < count($queue); $q++) {
            $c = $queue[$q];
            $x = $c % $w;
            $y = intdiv($c, $w);
            $nd = $dist[$c] + 1;
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx < 0 || $yy < 0 || $xx >= $w || $yy >= $h) {
                    continue;
                }
                $n = $yy * $w + $xx;
                if (isset($grid[$n]) && !isset($dist[$n])) {
                    $dist[$n] = $nd;
                    $queue[] = $n;
                }
            }
        }

        return $dist;
    }

    /** @param array<int, bool> $grid */
    private function freeNeighbours(array $grid, int $w, int $h, int $c): int
    {
        $x = $c % $w;
        $y = intdiv($c, $w);
        $n = 0;
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $xx = $x + $dx;
            $yy = $y + $dy;
            if ($xx >= 0 && $yy >= 0 && $xx < $w && $yy < $h && isset($grid[$yy * $w + $xx])) {
                $n++;
            }
        }

        return $n;
    }
}
