<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Niezniszczalny Wąż: hyper-aggressive. It grabs territory (Voronoi), hunts the opponent's head and cuts it off.
 * When it gets cornered it "spits venom": switches to pure survival mode and squeezes every last cell of space.
 */
final class NiezniszczalnyWazBot implements Bot
{
    public function name(): string { return 'Niezniszczalny Wąż'; }
    public function author(): string { return 'Anonim'; }
    public function color(): string { return '#39ff14'; }

    public function move(Arena $arena): Direction
    {
        $w = $arena->width;
        $h = $arena->height;
        $me = $arena->me;
        $opp = $arena->opponents[0] ?? null;

        $blocked = [];
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                if (!$arena->isFree($x, $y)) {
                    $blocked[$y * $w + $x] = true;
                }
            }
        }

        // Cells the opponent could step into next tick (head-on danger zone).
        $oppNext = [];
        if (null !== $opp) {
            foreach (Direction::cases() as $d) {
                $ox = $opp->x + $d->dx();
                $oy = $opp->y + $d->dy();
                if ($arena->isFree($ox, $oy)) {
                    $oppNext[$oy * $w + $ox] = true;
                }
            }
        }

        $best = $me->direction;
        $bestScore = -PHP_INT_MAX;

        foreach ($arena->safeDirections() as $d) {
            $nx = $me->x + $d->dx();
            $ny = $me->y + $d->dy();
            $key = $ny * $w + $nx;

            $b = $blocked;
            $b[$key] = true;

            $myDist = $this->bfs($b, $w, $h, [[$nx, $ny]]);
            $area = \count($myDist);

            $score = 0;
            if (null === $opp) {
                $score = $area * 100;
            } else {
                $oppDist = $this->bfs($b, $w, $h, [[$opp->x, $opp->y]]);
                $separated = true;
                $mine = 0;
                $theirs = 0;
                foreach ($myDist as $c => $md) {
                    if (isset($oppDist[$c])) {
                        $separated = false;
                        // I arrive at md+1 ticks from now, it arrives at oppDist ticks.
                        if ($md + 1 < $oppDist[$c]) {
                            ++$mine;
                        } elseif ($md + 1 > $oppDist[$c]) {
                            ++$theirs;
                        }
                    } else {
                        ++$mine;
                    }
                }
                foreach ($oppDist as $c => $od) {
                    if (!isset($myDist[$c])) {
                        ++$theirs;
                    }
                }

                $score = ($mine - $theirs) * 100;
                if ($separated) {
                    // Venom mode: we're sealed off, so survive as long as possible and hug walls.
                    $score -= $this->freeNeighbours($b, $w, $h, $nx, $ny) * 10;
                } else {
                    // Attack mode: win territory, then get close to its head to cut it off.
                    $dist = abs($nx - $opp->x) + abs($ny - $opp->y);
                    $score += $area * 5 - $dist * 3;
                }

                if (isset($oppNext[$key])) {
                    $score -= 1_000_000; // head-on is only a draw: last resort
                }
            }

            if ($area < 3) {
                $score -= 500_000; // dead end
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best;
    }

    /**
     * @param array<int, bool>     $blocked
     * @param list<array{int,int}> $starts
     *
     * @return array<int, int> distance by cell key (start cells excluded)
     */
    private function bfs(array $blocked, int $w, int $h, array $starts): array
    {
        $dist = [];
        $queue = [];
        foreach ($starts as [$x, $y]) {
            $queue[] = [$x, $y, 0];
        }
        $seen = [];
        foreach ($starts as [$x, $y]) {
            $seen[$y * $w + $x] = true;
        }
        $i = 0;
        while (isset($queue[$i])) {
            [$x, $y, $dd] = $queue[$i++];
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $cx = $x + $dx;
                $cy = $y + $dy;
                if ($cx < 0 || $cy < 0 || $cx >= $w || $cy >= $h) {
                    continue;
                }
                $k = $cy * $w + $cx;
                if (isset($blocked[$k]) || isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $dist[$k] = $dd + 1;
                $queue[] = [$cx, $cy, $dd + 1];
            }
        }

        return $dist;
    }

    /** @param array<int, bool> $blocked */
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
