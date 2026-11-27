<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Turak: black mamba. Fights for territory (Voronoi) while the opponent is reachable,
 * dodges head-on collisions, and once sealed off, hugs walls to fill its own space.
 */
final class TurakBot implements Bot
{
    public function name(): string { return 'Turak'; }
    public function author(): string { return 'Turak'; }
    public function color(): string { return '#1a1a1a'; }

    public function move(Arena $arena): Direction
    {
        $w = $arena->width;
        $h = $arena->height;
        $free = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $free[$y * $w + $x] = $arena->isFree($x, $y);
            }
        }

        $me = $arena->me;
        $opp = $arena->opponents[0] ?? null;

        $oppNext = [];
        if ($opp !== null) {
            foreach (Direction::cases() as $d) {
                $nx = $opp->x + $d->dx();
                $ny = $opp->y + $d->dy();
                if ($nx >= 0 && $ny >= 0 && $nx < $w && $ny < $h && $free[$ny * $w + $nx]) {
                    $oppNext[$ny * $w + $nx] = true;
                }
            }
        }

        $best = null;
        $bestScore = -INF;
        foreach (Direction::cases() as $d) {
            $px = $me->x + $d->dx();
            $py = $me->y + $d->dy();
            if ($px < 0 || $py < 0 || $px >= $w || $py >= $h || !$free[$py * $w + $px]) {
                continue;
            }
            $p = $py * $w + $px;
            $grid = $free;
            $grid[$p] = false;

            $score = $this->evaluate($grid, $w, $h, $p, $opp);
            if (isset($oppNext[$p])) {
                $score -= 1_000_000; // head-on risk: only if nothing else
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best ?? $me->direction;
    }

    private function evaluate(array $grid, int $w, int $h, int $p, $opp): float
    {
        $n = $w * $h;
        // Flood fill from my new head.
        $seen = [$p => true];
        $queue = [$p];
        $connected = false;
        $oppIdx = $opp !== null ? $opp->y * $w + $opp->x : -1;
        for ($i = 0; $i < count($queue); $i++) {
            $c = $queue[$i];
            foreach ($this->neighbours($c, $w, $h) as $nb) {
                if ($nb === $oppIdx) {
                    $connected = true;
                }
                if (!isset($seen[$nb]) && $grid[$nb]) {
                    $seen[$nb] = true;
                    $queue[] = $nb;
                }
            }
        }
        $reach = count($queue) - 1;

        if (!$connected || $opp === null) {
            // Survival mode: maximise space, hug walls to waste nothing.
            $exits = 0;
            foreach ($this->neighbours($p, $w, $h) as $nb) {
                if ($grid[$nb]) {
                    $exits++;
                }
            }

            return $reach * 10 - $exits;
        }

        // Battle mode: Voronoi territory. I already moved (dist 1), opponent at dist 0.
        $dist = array_fill(0, $n, PHP_INT_MAX);
        $owner = array_fill(0, $n, 0);
        $dist[$oppIdx] = 0;
        $owner[$oppIdx] = 2;
        $dist[$p] = 1;
        $owner[$p] = 1;
        $q = [$oppIdx, $p];
        $mine = 0;
        $theirs = 0;
        for ($i = 0; $i < count($q); $i++) {
            $c = $q[$i];
            $o = $owner[$c];
            if ($o === 3) {
                continue;
            }
            $nd = $dist[$c] + 1;
            foreach ($this->neighbours($c, $w, $h) as $nb) {
                if (!$grid[$nb]) {
                    continue;
                }
                if ($dist[$nb] === PHP_INT_MAX) {
                    $dist[$nb] = $nd;
                    $owner[$nb] = $o;
                    $q[] = $nb;
                    $o === 1 ? $mine++ : $theirs++;
                } elseif ($dist[$nb] === $nd && $owner[$nb] !== $o && $owner[$nb] !== 3) {
                    $owner[$nb] === 1 ? $mine-- : $theirs--;
                    $owner[$nb] = 3; // contested
                }
            }
        }

        return ($mine - $theirs) * 1000 + $reach;
    }

    /** @return list<int> */
    private function neighbours(int $c, int $w, int $h): array
    {
        $x = $c % $w;
        $y = intdiv($c, $w);
        $r = [];
        if ($x > 0) $r[] = $c - 1;
        if ($x < $w - 1) $r[] = $c + 1;
        if ($y > 0) $r[] = $c - $w;
        if ($y < $h - 1) $r[] = $c + $w;

        return $r;
    }
}
