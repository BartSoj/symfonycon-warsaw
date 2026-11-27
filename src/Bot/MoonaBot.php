<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Moona: Voronoi territory minimax (my move x every opponent reply), then
 * flood fill with wall hugging once the two riders are cut off from each other.
 */
final class MoonaBot implements Bot
{
    private float $spent = 0.0;
    private int $w = 0;
    private int $h = 0;

    public function name(): string { return 'Moona'; }
    public function author(): string { return 'Moona'; }
    public function color(): string { return '#ff3355'; }

    public function move(Arena $arena): Direction
    {
        $start = hrtime(true);
        try {
            return $this->decide($arena);
        } finally {
            $this->spent += (hrtime(true) - $start) / 1e9;
        }
    }

    private function decide(Arena $arena): Direction
    {
        $me = $arena->me;
        if ($this->spent > 2.6) {
            return $this->rookie($arena);
        }

        $w = $this->w = $arena->width;
        $h = $this->h = $arena->height;
        $free = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $free[$y * $w + $x] = $arena->isFree($x, $y);
            }
        }

        $myMoves = [];
        foreach (Direction::cases() as $d) {
            $nx = $me->x + $d->dx();
            $ny = $me->y + $d->dy();
            if ($this->ok($free, $nx, $ny)) {
                $myMoves[] = [$d, $ny * $w + $nx];
            }
        }
        if ($myMoves === []) {
            return $me->direction;
        }
        if (count($myMoves) === 1) {
            return $myMoves[0][0];
        }

        $opp = $arena->opponents[0] ?? null;
        $oppMoves = [];
        if ($opp !== null) {
            foreach (Direction::cases() as $d) {
                $nx = $opp->x + $d->dx();
                $ny = $opp->y + $d->dy();
                if ($this->ok($free, $nx, $ny)) {
                    $oppMoves[] = $ny * $w + $nx;
                }
            }
        }

        $fast = $this->spent > 1.8;
        $connected = $oppMoves !== [] && !$fast && $this->connected($free, $me->y * $w + $me->x, $oppMoves);

        $best = null;
        $bestScore = -INF;
        foreach ($myMoves as [$d, $cell]) {
            if ($connected) {
                $worst = INF;
                foreach ($oppMoves as $oc) {
                    if ($oc === $cell) {
                        $s = -5000.0; // head-on: draw, avoid unless everything else is lost
                    } else {
                        $g = $free;
                        $g[$cell] = false;
                        $g[$oc] = false;
                        $s = $this->voronoi($g, $cell, $oc);
                    }
                    if ($s < $worst) {
                        $worst = $s;
                    }
                }
                $score = $worst;
            } else {
                $g = $free;
                $g[$cell] = false;
                $score = $this->fill($g, $cell) * 10 - $this->freeNeighbours($g, $cell);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best ?? $myMoves[0][0];
    }

    private function ok(array $free, int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < $this->w && $y < $this->h && $free[$y * $this->w + $x];
    }

    /** @return list<int> */
    private function neighbours(array $g, int $c): array
    {
        $w = $this->w;
        $x = $c % $w;
        $out = [];
        if ($x > 0 && $g[$c - 1]) { $out[] = $c - 1; }
        if ($x < $w - 1 && $g[$c + 1]) { $out[] = $c + 1; }
        if ($c - $w >= 0 && $g[$c - $w]) { $out[] = $c - $w; }
        if ($c + $w < $w * $this->h && $g[$c + $w]) { $out[] = $c + $w; }

        return $out;
    }

    private function freeNeighbours(array $g, int $c): int
    {
        return count($this->neighbours($g, $c));
    }

    private function connected(array $free, int $from, array $targets): bool
    {
        $t = array_flip($targets);
        $seen = [$from => true];
        $queue = [$from];
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($this->neighbours($free, $queue[$i]) as $n) {
                if (isset($t[$n])) {
                    return true;
                }
                if (!isset($seen[$n])) {
                    $seen[$n] = true;
                    $queue[] = $n;
                }
            }
        }

        return false;
    }

    private function fill(array $g, int $from): int
    {
        $seen = [$from => true];
        $queue = [$from];
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($this->neighbours($g, $queue[$i]) as $n) {
                if (!isset($seen[$n])) {
                    $seen[$n] = true;
                    $queue[] = $n;
                }
            }
        }

        return count($queue) - 1;
    }

    /** Cells I reach strictly first minus cells the opponent reaches strictly first. */
    private function voronoi(array $g, int $mine, int $theirs): float
    {
        $owner = [$mine => 1, $theirs => 2];
        $frontier = [[$mine, 1], [$theirs, 2]];
        $score = 0;
        $mineCount = 0;
        while ($frontier !== []) {
            $claims = [];
            foreach ($frontier as [$c, $who]) {
                foreach ($this->neighbours($g, $c) as $n) {
                    if (isset($owner[$n])) {
                        continue;
                    }
                    if (!isset($claims[$n])) {
                        $claims[$n] = $who;
                    } elseif ($claims[$n] !== $who) {
                        $claims[$n] = 0; // contested
                    }
                }
            }
            $frontier = [];
            foreach ($claims as $n => $who) {
                $owner[$n] = $who;
                if ($who === 1) {
                    $score++;
                    $mineCount++;
                } elseif ($who === 2) {
                    $score--;
                }
                if ($who !== 0) {
                    $frontier[] = [$n, $who];
                }
            }
        }

        if ($mineCount === 0) {
            return -10000.0; // dead end
        }

        return $score + 0.01 * $mineCount;
    }

    private function rookie(Arena $arena): Direction
    {
        $d = $arena->me->direction;
        foreach ([$d, $d->left(), $d->right()] as $c) {
            if ($arena->isSafe($c)) {
                return $c;
            }
        }

        return $d;
    }
}
