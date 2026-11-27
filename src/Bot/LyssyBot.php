<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Cycle;
use App\Arena\Direction;

/**
 * Lyssy: a survival organism. Survival comes first, then options, then territory.
 *
 * - Contact phase: a simultaneous-move lookahead (my move x every opponent reply). Each pair is scored by Voronoi
 *   territory (cells I reach first), and it picks the move with the best worst case. Head-on clashes are a last resort.
 * - Sealed phase: once the opponent can't reach us, it stops fighting and fills its own space as tightly as possible.
 * - Time guard: the lookahead drops to a cheap one-ply evaluation if the match time budget runs low.
 */
final class LyssyBot implements Bot
{
    private float $spentMs = 0.0;

    public function name(): string { return 'Lyssy'; }
    public function author(): string { return 'Anonim'; }
    public function color(): string { return '#b04cff'; }

    public function move(Arena $arena): Direction
    {
        $t0 = hrtime(true);
        try {
            return $this->decide($arena);
        } finally {
            $this->spentMs += (hrtime(true) - $t0) / 1e6;
        }
    }

    private function decide(Arena $arena): Direction
    {
        $w = $arena->width;
        $h = $arena->height;
        $me = $arena->me;
        $safe = $arena->safeDirections();
        if ([] === $safe) {
            return $me->direction;
        }

        $blocked = [];
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                if (!$arena->isFree($x, $y)) {
                    $blocked[$y * $w + $x] = true;
                }
            }
        }

        $opp = $arena->opponents[0] ?? null;
        $myReach = $this->bfs($blocked, $w, $h, $me->x, $me->y);
        $contact = null !== $opp && $this->adjacentTo($myReach, $w, $h, $opp, $blocked);

        if (!$contact) {
            return $this->survive($blocked, $w, $h, $me, $safe);
        }

        $oppMoves = [];
        foreach (Direction::cases() as $d) {
            $ox = $opp->x + $d->dx();
            $oy = $opp->y + $d->dy();
            if ($arena->isFree($ox, $oy)) {
                $oppMoves[] = [$ox, $oy];
            }
        }

        $deep = $this->spentMs < 2200;
        $best = $safe[0];
        $bestScore = -PHP_INT_MAX;

        foreach ($safe as $d) {
            $nx = $me->x + $d->dx();
            $ny = $me->y + $d->dy();
            $k = $ny * $w + $nx;

            if (!$deep || [] === $oppMoves) {
                $b = $blocked;
                $b[$k] = true;
                $score = $this->voronoi($b, $w, $h, $nx, $ny, $opp->x, $opp->y, 1);
                foreach ($oppMoves as [$ox, $oy]) {
                    if ($ox === $nx && $oy === $ny) {
                        $score -= 100_000;
                    }
                }
            } else {
                $score = PHP_INT_MAX;
                foreach ($oppMoves as [$ox, $oy]) {
                    if ($ox === $nx && $oy === $ny) {
                        $s = -50_000; // head-on: a draw at best
                    } else {
                        $b = $blocked;
                        $b[$k] = true;
                        $b[$oy * $w + $ox] = true;
                        $s = $this->voronoi($b, $w, $h, $nx, $ny, $ox, $oy, 0);
                    }
                    $score = min($score, $s);
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best;
    }

    /** Sealed off: maximise reachable space, hug walls so no cell is wasted, look 2 steps ahead for dead ends. */
    private function survive(array $blocked, int $w, int $h, Cycle $me, array $safe): Direction
    {
        $best = $safe[0];
        $bestScore = -PHP_INT_MAX;
        foreach ($safe as $d) {
            $nx = $me->x + $d->dx();
            $ny = $me->y + $d->dy();
            $b = $blocked;
            $b[$ny * $w + $nx] = true;
            $area = \count($this->bfs($b, $w, $h, $nx, $ny));

            // second step: best area after one more move (catches pockets that look big but split)
            $next = 0;
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $cx = $nx + $dx;
                $cy = $ny + $dy;
                if ($cx < 0 || $cy < 0 || $cx >= $w || $cy >= $h || isset($b[$cy * $w + $cx])) {
                    continue;
                }
                $b2 = $b;
                $b2[$cy * $w + $cx] = true;
                $next = max($next, 1 + \count($this->bfs($b2, $w, $h, $cx, $cy)));
            }

            $score = min($area, $next) * 100 - $this->freeNeighbours($b, $w, $h, $nx, $ny) * 10;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best;
    }

    /** Territory score: cells I reach strictly first minus cells the opponent reaches first, plus a little breathing room. */
    private function voronoi(array $b, int $w, int $h, int $mx, int $my, int $ox, int $oy, int $oppLag): int
    {
        $mine = $this->bfs($b, $w, $h, $mx, $my);
        $theirs = $this->bfs($b, $w, $h, $ox, $oy);
        if ([] === $mine) {
            return -1_000_000; // dead end
        }
        if ([] === $theirs) {
            return 1_000_000 + \count($mine); // opponent is boxed in
        }
        $m = 0;
        $t = 0;
        foreach ($mine as $c => $md) {
            $od = isset($theirs[$c]) ? $theirs[$c] - $oppLag : PHP_INT_MAX;
            if ($md < $od) {
                ++$m;
            } elseif ($md > $od) {
                ++$t;
            }
        }
        foreach ($theirs as $c => $_) {
            if (!isset($mine[$c])) {
                ++$t;
            }
        }
        $penalty = \count($mine) < 8 ? 200_000 : 0;

        return ($m - $t) * 100 + \count($mine) - $penalty;
    }

    private function adjacentTo(array $reach, int $w, int $h, Cycle $opp, array $blocked): bool
    {
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $cx = $opp->x + $dx;
            $cy = $opp->y + $dy;
            if ($cx >= 0 && $cy >= 0 && $cx < $w && $cy < $h && isset($reach[$cy * $w + $cx])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, int> distance by cell key, start excluded */
    private function bfs(array $blocked, int $w, int $h, int $sx, int $sy): array
    {
        $dist = [];
        $seen = [$sy * $w + $sx => true];
        $qx = [$sx];
        $qy = [$sy];
        $qd = [0];
        for ($i = 0; isset($qx[$i]); ++$i) {
            $x = $qx[$i];
            $y = $qy[$i];
            $nd = $qd[$i] + 1;
            if ($x > 0) {
                $k = $y * $w + $x - 1;
                if (!isset($blocked[$k]) && !isset($seen[$k])) {
                    $seen[$k] = true; $dist[$k] = $nd; $qx[] = $x - 1; $qy[] = $y; $qd[] = $nd;
                }
            }
            if ($x < $w - 1) {
                $k = $y * $w + $x + 1;
                if (!isset($blocked[$k]) && !isset($seen[$k])) {
                    $seen[$k] = true; $dist[$k] = $nd; $qx[] = $x + 1; $qy[] = $y; $qd[] = $nd;
                }
            }
            if ($y > 0) {
                $k = ($y - 1) * $w + $x;
                if (!isset($blocked[$k]) && !isset($seen[$k])) {
                    $seen[$k] = true; $dist[$k] = $nd; $qx[] = $x; $qy[] = $y - 1; $qd[] = $nd;
                }
            }
            if ($y < $h - 1) {
                $k = ($y + 1) * $w + $x;
                if (!isset($blocked[$k]) && !isset($seen[$k])) {
                    $seen[$k] = true; $dist[$k] = $nd; $qx[] = $x; $qy[] = $y + 1; $qd[] = $nd;
                }
            }
        }

        return $dist;
    }

    private function freeNeighbours(array $blocked, int $w, int $h, int $x, int $y): int
    {
        $n = 0;
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $cx = $x + $dx;
            $cy = $y + $dy;
            if ($cx >= 0 && $cy >= 0 && $cx < $w && $cy < $h && !isset($blocked[$cy * $w + $cx])) {
                ++$n;
            }
        }

        return $n;
    }
}
