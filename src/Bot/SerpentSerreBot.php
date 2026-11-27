<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Serpent Serré: hugs walls and trails with tight turns so it fills the board without leaving holes.
 * - While it can still reach the opponent: claim territory (cells it reaches before the opponent).
 * - Once cut off: maximise reachable space, then pick the move that touches the most walls.
 */
final class SerpentSerreBot implements Bot
{
    public function name(): string { return 'Serpent Serré'; }
    public function author(): string { return 'Visiteur'; }
    public function color(): string { return '#9b30ff'; }

    private const DIRS = [Direction::Up, Direction::Down, Direction::Left, Direction::Right];

    /** @var array<int, bool> */
    private array $free = [];
    private int $w = 0;
    private int $h = 0;

    public function move(Arena $arena): Direction
    {
        $me = $arena->me;
        $safe = $arena->safeDirections();
        if ($safe === []) {
            return $me->direction;
        }

        $this->w = $arena->width;
        $this->h = $arena->height;
        $this->free = [];
        for ($y = 0; $y < $this->h; $y++) {
            for ($x = 0; $x < $this->w; $x++) {
                $this->free[$y * $this->w + $x] = $arena->isFree($x, $y);
            }
        }

        $op = $arena->opponents[0] ?? null;
        $opNext = [];
        if ($op !== null) {
            foreach (self::DIRS as $d) {
                $ox = $op->x + $d->dx();
                $oy = $op->y + $d->dy();
                if ($this->isFree($ox, $oy)) {
                    $opNext[$oy * $this->w + $ox] = true;
                }
            }
        }

        $best = $safe[0];
        $bestScore = -PHP_INT_MAX;
        foreach ($safe as $d) {
            $nx = $me->x + $d->dx();
            $ny = $me->y + $d->dy();
            $idx = $ny * $this->w + $nx;

            $this->free[$idx] = false;
            [$area, $touchesOp] = $this->flood($nx, $ny, $op?->x, $op?->y);

            if ($op !== null && $touchesOp) {
                [$mine, $theirs] = $this->voronoi($nx, $ny, $op->x, $op->y);
                $score = ($mine - $theirs) * 1000 + $area * 10;
            } else {
                $score = $area * 1000;
            }

            // Tight moves: prefer cells hugging walls / trails (fewer free neighbours).
            $score += (4 - $this->freeNeighbours($nx, $ny)) * 3;
            // Keep going straight when equal, to avoid zigzag holes.
            if ($d === $me->direction) {
                $score += 1;
            }
            // Avoid head-on collisions.
            if (isset($opNext[$idx])) {
                $score -= 500000;
            }

            $this->free[$idx] = true;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best;
    }

    private function isFree(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < $this->w && $y < $this->h && $this->free[$y * $this->w + $x];
    }

    private function freeNeighbours(int $x, int $y): int
    {
        $n = 0;
        foreach (self::DIRS as $d) {
            if ($this->isFree($x + $d->dx(), $y + $d->dy())) {
                $n++;
            }
        }

        return $n;
    }

    /** @return array{int, bool} reachable cell count, and whether the opponent head is adjacent to that region */
    private function flood(int $sx, int $sy, ?int $ox, ?int $oy): array
    {
        $seen = [$sy * $this->w + $sx => true];
        $queue = [[$sx, $sy]];
        $count = 0;
        $touches = false;
        for ($i = 0; $i < count($queue); $i++) {
            [$x, $y] = $queue[$i];
            foreach (self::DIRS as $d) {
                $x2 = $x + $d->dx();
                $y2 = $y + $d->dy();
                if ($ox !== null && $x2 === $ox && $y2 === $oy) {
                    $touches = true;
                }
                if (!$this->isFree($x2, $y2)) {
                    continue;
                }
                $k = $y2 * $this->w + $x2;
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $count++;
                    $queue[] = [$x2, $y2];
                }
            }
        }

        return [$count, $touches];
    }

    /** @return array{int, int} cells I reach strictly first, cells the opponent reaches first */
    private function voronoi(int $mx, int $my, int $ox, int $oy): array
    {
        $dist = [];
        $owner = [];
        // Opponent has not moved yet: it is one step "behind" in position, so it starts at 0 and I at 1.
        $queue = [[$ox, $oy, 0, 2], [$mx, $my, 1, 1]];
        $dist[$oy * $this->w + $ox] = 0;
        $owner[$oy * $this->w + $ox] = 2;
        $dist[$my * $this->w + $mx] = 1;
        $owner[$my * $this->w + $mx] = 1;
        $mine = 0;
        $theirs = 0;
        // Process in order of distance (BFS with two seeds at different depth).
        $front = [[$ox, $oy, 2]];
        $next = [[$mx, $my, 1]];
        $depth = 0;
        while ($front !== [] || $next !== []) {
            $new = [];
            foreach ($front as [$x, $y, $who]) {
                if ($owner[$y * $this->w + $x] === 0) {
                    continue;
                }
                foreach (self::DIRS as $d) {
                    $x2 = $x + $d->dx();
                    $y2 = $y + $d->dy();
                    if (!$this->isFree($x2, $y2)) {
                        continue;
                    }
                    $k = $y2 * $this->w + $x2;
                    if (!isset($dist[$k])) {
                        $dist[$k] = $depth + 1;
                        $owner[$k] = $who;
                        $new[] = [$x2, $y2, $who];
                    } elseif ($dist[$k] === $depth + 1 && $owner[$k] !== $who) {
                        $owner[$k] = 0; // contested
                    }
                }
            }
            $front = array_merge($new, $next);
            $next = [];
            $depth++;
        }
        foreach ($owner as $k => $who) {
            if ($who === 1) {
                $mine++;
            } elseif ($who === 2) {
                $theirs++;
            }
        }

        return [$mine, $theirs];
    }
}
