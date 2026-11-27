<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

final class TopXWonszBot implements Bot
{
    private float $spent = 0.0;
    private int $w = 0;
    private int $h = 0;
    /** @var array<int, bool> */
    private array $free = [];

    public function name(): string { return 'top xWonsz'; }
    public function author(): string { return 'xWonsz'; }
    public function color(): string { return '#9b30ff'; }

    public function move(Arena $arena): Direction
    {
        $start = microtime(true);
        $me = $arena->me;
        $safe = $arena->safeDirections();
        if ($safe === []) {
            return $me->direction;
        }
        if (count($safe) === 1) {
            return $safe[0];
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
        $lowBudget = $this->spent > 2.0;

        // Cells the opponent might move into next tick.
        $opCells = [];
        if ($op !== null) {
            foreach (Direction::cases() as $d) {
                $ox = $op->x + $d->dx();
                $oy = $op->y + $d->dy();
                if ($this->isFreeXY($ox, $oy)) {
                    $opCells[$oy * $this->w + $ox] = true;
                }
            }
        }

        // Survival analysis: reachable space after each safe move.
        $myDist = [];
        $space = [];
        $connected = false;
        foreach ($safe as $i => $d) {
            $n = ($me->y + $d->dy()) * $this->w + ($me->x + $d->dx());
            $this->free[$n] = false;
            $dist = $this->bfs($n);
            $this->free[$n] = true;
            $myDist[$i] = $dist;
            $space[$i] = count($dist);
            if ($op !== null && !$connected) {
                foreach (Direction::cases() as $od) {
                    $ox = $op->x + $od->dx();
                    $oy = $op->y + $od->dy();
                    if ($ox >= 0 && $oy >= 0 && $ox < $this->w && $oy < $this->h && isset($dist[$oy * $this->w + $ox])) {
                        $connected = true;
                        break;
                    }
                }
            }
        }
        $maxSpace = max($space);

        // Opponent distance maps per possible opponent move.
        $opDists = [];
        if ($connected && !$lowBudget) {
            foreach (array_keys($opCells) as $oc) {
                $this->free[$oc] = false;
                $opDists[$oc] = $this->bfs($oc);
                $this->free[$oc] = true;
            }
        }

        $best = $safe[0];
        $bestScore = -INF;
        foreach ($safe as $i => $d) {
            $nx = $me->x + $d->dx();
            $ny = $me->y + $d->dy();
            $n = $ny * $this->w + $nx;
            $score = 0.0;

            // Never walk into a closed pocket clearly smaller than the alternative.
            if ($space[$i] < $maxSpace * 0.7) {
                $score -= 100000 - $space[$i] * 10;
            }
            // Avoid head-on collisions.
            if (isset($opCells[$n])) {
                $score -= 50000;
            }

            if ($connected && $opDists !== []) {
                // Aggressive mode: cut the opponent off, assume its best reply.
                $worst = INF;
                foreach ($opDists as $oc => $od) {
                    if ($oc === $n) {
                        continue;
                    }
                    $mine = 0;
                    $theirs = 0;
                    foreach ($myDist[$i] as $cell => $dm) {
                        if (!isset($od[$cell]) || $dm < $od[$cell]) {
                            $mine++;
                        } elseif ($dm > $od[$cell]) {
                            $theirs++;
                        }
                    }
                    foreach ($od as $cell => $_) {
                        if (!isset($myDist[$i][$cell])) {
                            $theirs++;
                        }
                    }
                    $worst = min($worst, $mine - $theirs);
                }
                if ($worst === INF) {
                    $worst = 1000;
                }
                $score += $worst * 10;
                // Tiebreak: head for the cell in front of the opponent.
                if ($op !== null) {
                    $tx = $op->x + $op->direction->dx() * 2;
                    $ty = $op->y + $op->direction->dy() * 2;
                    $score -= (abs($tx - $nx) + abs($ty - $ny)) * 0.5;
                }
            } else {
                // Survival mode: fill space tightly by hugging walls.
                $score += $space[$i] * 10 - $this->freeNeighbours($nx, $ny);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        $this->spent += microtime(true) - $start;

        return $best;
    }

    private function isFreeXY(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < $this->w && $y < $this->h && $this->free[$y * $this->w + $x];
    }

    private function freeNeighbours(int $x, int $y): int
    {
        $c = 0;
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            if ($this->isFreeXY($x + $dx, $y + $dy)) {
                $c++;
            }
        }

        return $c;
    }

    /** @return array<int, int> cell => distance (start excluded) */
    private function bfs(int $start): array
    {
        $w = $this->w;
        $h = $this->h;
        $dist = [];
        $queue = [$start];
        $d0 = [$start => 0];
        $head = 0;
        while ($head < count($queue)) {
            $c = $queue[$head++];
            $cd = $d0[$c] + 1;
            $x = $c % $w;
            $y = intdiv($c, $w);
            if ($x > 0) { $n = $c - 1; if ($this->free[$n] && !isset($d0[$n])) { $d0[$n] = $cd; $dist[$n] = $cd; $queue[] = $n; } }
            if ($x < $w - 1) { $n = $c + 1; if ($this->free[$n] && !isset($d0[$n])) { $d0[$n] = $cd; $dist[$n] = $cd; $queue[] = $n; } }
            if ($y > 0) { $n = $c - $w; if ($this->free[$n] && !isset($d0[$n])) { $d0[$n] = $cd; $dist[$n] = $cd; $queue[] = $n; } }
            if ($y < $h - 1) { $n = $c + $w; if ($this->free[$n] && !isset($d0[$n])) { $d0[$n] = $cd; $dist[$n] = $cd; $queue[] = $n; } }
        }

        return $dist;
    }
}
