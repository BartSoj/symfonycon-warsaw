<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * The Divine Unicorn: draws long walls to slice the arena, always keeps the bigger half,
 * and grabs any territory the opponent leaves open.
 */
final class WedgeSamaDivineUnicornBot implements Bot
{
    public function name(): string { return 'TheDivineUnicorn'; }
    public function author(): string { return 'WedgeSama'; }
    public function color(): string { return '#f8d030'; }

    public function move(Arena $arena): Direction
    {
        $w = $arena->width;
        $h = $arena->height;
        $free = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($arena->isFree($x, $y)) {
                    $free[$y * $w + $x] = true;
                }
            }
        }

        $me = $arena->me;
        $opp = $arena->opponents[0] ?? null;

        // Cells the opponent could step into next tick (head-on danger).
        $oppNext = [];
        if ($opp !== null) {
            foreach (Direction::cases() as $d) {
                $nx = $opp->x + $d->dx();
                $ny = $opp->y + $d->dy();
                if ($nx >= 0 && $ny >= 0 && $nx < $w && $ny < $h && isset($free[$ny * $w + $nx])) {
                    $oppNext[] = $ny * $w + $nx;
                }
            }
        }

        $best = $me->direction;
        $bestScore = -INF;
        foreach ([$me->direction, $me->direction->left(), $me->direction->right()] as $d) {
            if (!$arena->isSafe($d)) {
                continue;
            }
            $start = ($me->y + $d->dy()) * $w + ($me->x + $d->dx());
            $score = $this->territory($free, $w, $h, $start, array_values(array_diff($oppNext, [$start])));
            if (in_array($start, $oppNext, true)) {
                $score -= 500; // head-on is a gamble, only if nothing else
            }
            if ($d === $me->direction) {
                $score += 0.5; // keep drawing long straight walls
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best;
    }

    /**
     * Race me (from $start) against the opponent (from its next cells).
     * Still connected: cells I reach first minus cells they reach first, so cutting them off pays.
     * Separated: just my own reachable space, so I always keep the bigger half.
     */
    private function territory(array $free, int $w, int $h, int $start, array $oppStarts): float
    {
        $owner = [$start => 1];
        $dist = [$start => 0];
        $queue = [$start];
        foreach ($oppStarts as $c) {
            $owner[$c] = 2;
            $dist[$c] = 0;
            $queue[] = $c;
        }
        $mine = 0;
        $theirs = 0;
        $touched = false;
        for ($i = 0; $i < count($queue); $i++) {
            $c = $queue[$i];
            $o = $owner[$c];
            if ($o === 1) {
                $mine++;
            } elseif ($o === 2) {
                $theirs++;
            }
            $x = $c % $w;
            $y = intdiv($c, $w);
            $next = [];
            if ($x > 0) { $next[] = $c - 1; }
            if ($x < $w - 1) { $next[] = $c + 1; }
            if ($y > 0) { $next[] = $c - $w; }
            if ($y < $h - 1) { $next[] = $c + $w; }
            foreach ($next as $n) {
                if (!isset($free[$n]) || $n === $start) {
                    continue;
                }
                if (!isset($owner[$n])) {
                    $owner[$n] = $o;
                    $dist[$n] = $dist[$c] + 1;
                    $queue[] = $n;
                } elseif ($owner[$n] !== $o && $owner[$n] !== 0) {
                    $touched = true;
                    if ($dist[$n] === $dist[$c] + 1) {
                        $owner[$n] = 0; // contested tie
                    }
                }
            }
        }

        if (!$touched) {
            // Separated: maximise my own room (huge bonus for being on the bigger side).
            return 10000 + $mine - $theirs;
        }

        return $mine - $theirs;
    }
}
