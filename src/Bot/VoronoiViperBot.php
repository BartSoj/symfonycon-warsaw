<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Voronoi Viper: simultaneous-move minimax (1 ply) with Voronoi territory evaluation
 * while the opponent is reachable, and space-filling with wall hugging once separated.
 */
final class VoronoiViperBot implements Bot
{
    private float $spentMs = 0.0;

    public function name(): string { return 'Voronoi Viper'; }
    public function author(): string { return 'Booth Hacker'; }
    public function color(): string { return '#ff2d95'; }

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
        $me = $arena->me;
        $safe = $arena->safeDirections();
        if ($safe === []) {
            return $me->direction;
        }
        if (count($safe) === 1 || $this->spentMs > 2600) {
            return $this->rookie($arena, $safe);
        }

        // Padded grid: border cells are blocked, so neighbours are i±1, i±W with no bounds checks.
        $W = $arena->width + 2;
        $H = $arena->height + 2;
        $free = array_fill(0, $W * $H, false);
        for ($y = 0; $y < $arena->height; ++$y) {
            for ($x = 0; $x < $arena->width; ++$x) {
                if ($arena->isFree($x, $y)) {
                    $free[($y + 1) * $W + $x + 1] = true;
                }
            }
        }
        $offsets = [];
        foreach (Direction::cases() as $d) {
            $offsets[$d->name] = $d->dy() * $W + $d->dx();
        }
        $myHead = ($me->y + 1) * $W + $me->x + 1;

        $opp = $arena->opponents[0] ?? null;
        $oppMoves = [];
        if ($opp !== null) {
            $oppHead = ($opp->y + 1) * $W + $opp->x + 1;
            foreach ($offsets as $off) {
                if ($free[$oppHead + $off]) {
                    $oppMoves[] = $oppHead + $off;
                }
            }
        }

        // Are we still sharing space with the opponent?
        $connected = false;
        if ($oppMoves !== []) {
            $reach = $this->flood($free, $myHead, $W);
            foreach ($oppMoves as $o) {
                if (isset($reach[$o])) {
                    $connected = true;
                    break;
                }
            }
        }

        $best = null;
        $bestScore = -INF;
        foreach ($safe as $d) {
            $m = $myHead + $offsets[$d->name];
            if ($connected) {
                $score = $this->spentMs > 1800
                    ? $this->voronoi($free, $m, $oppHead, $W) // cheaper: opponent treated as static
                    : $this->minOverOpponent($free, $m, $oppMoves, $W);
            } else {
                $free[$m] = false;
                $count = count($this->flood($free, $m, $W));
                $exits = 0;
                foreach ($offsets as $off) {
                    if ($free[$m + $off]) {
                        ++$exits;
                    }
                }
                $free[$m] = true;
                // Maximise room; among equals hug walls (fewer exits) to fill space tightly.
                $score = $count * 10 - $exits + ($exits === 0 ? -100000 : 0);
            }
            if ($d === $me->direction) {
                $score += 0.01;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best ?? $safe[0];
    }

    /** @param list<int> $oppMoves */
    private function minOverOpponent(array &$free, int $m, array $oppMoves, int $W): float
    {
        $worst = INF;
        $sum = 0.0;
        foreach ($oppMoves as $o) {
            $s = $m === $o ? -5000.0 : $this->voronoi($free, $m, $o, $W);
            $sum += $s;
            $worst = min($worst, $s);
        }

        return $worst + $sum / (100 * count($oppMoves));
    }

    /** Territory difference after I move to $m and the opponent to $o. */
    private function voronoi(array &$free, int $m, int $o, int $W): float
    {
        $free[$m] = false;
        $free[$o] = false;
        $myExit = $free[$m + 1] || $free[$m - 1] || $free[$m + $W] || $free[$m - $W];
        $opExit = $free[$o + 1] || $free[$o - 1] || $free[$o + $W] || $free[$o - $W];

        if (!$myExit) {
            $score = $opExit ? -10000.0 : -5000.0;
        } elseif (!$opExit) {
            $score = 10000.0;
        } else {
            $dm = $this->distances($free, $m, $W);
            $do = $this->distances($free, $o, $W);
            $mine = 0;
            $theirs = 0;
            $shared = false;
            foreach ($dm as $cell => $dist) {
                if (!isset($do[$cell])) {
                    ++$mine;
                } else {
                    $shared = true;
                    if ($dist < $do[$cell]) {
                        ++$mine;
                    } elseif ($dist > $do[$cell]) {
                        ++$theirs;
                    }
                }
            }
            foreach ($do as $cell => $_) {
                if (!isset($dm[$cell])) {
                    ++$theirs;
                }
            }
            $score = (float) ($mine - $theirs);
            if (!$shared) {
                // Separated: the bigger room wins outright.
                $score *= 20;
            }
        }

        $free[$m] = true;
        $free[$o] = true;

        return $score;
    }

    /** @return array<int, int> BFS distances from $start (excluded) over free cells */
    private function distances(array $free, int $start, int $W): array
    {
        $dist = [$start => 0];
        $queue = [$start];
        $offs = [1, -1, $W, -$W];
        for ($i = 0; isset($queue[$i]); ++$i) {
            $c = $queue[$i];
            $nd = $dist[$c] + 1;
            foreach ($offs as $off) {
                $n = $c + $off;
                if ($free[$n] && !isset($dist[$n])) {
                    $dist[$n] = $nd;
                    $queue[] = $n;
                }
            }
        }
        unset($dist[$start]);

        return $dist;
    }

    /** @return array<int, true> */
    private function flood(array $free, int $start, int $W): array
    {
        $seen = [$start => true];
        $queue = [$start];
        $offs = [1, -1, $W, -$W];
        for ($i = 0; isset($queue[$i]); ++$i) {
            $c = $queue[$i];
            foreach ($offs as $off) {
                $n = $c + $off;
                if ($free[$n] && !isset($seen[$n])) {
                    $seen[$n] = true;
                    $queue[] = $n;
                }
            }
        }
        unset($seen[$start]);

        return $seen;
    }

    /** @param list<Direction> $safe */
    private function rookie(Arena $arena, array $safe): Direction
    {
        $me = $arena->me;
        foreach ([$me->direction, $me->direction->left(), $me->direction->right()] as $d) {
            if (in_array($d, $safe, true)) {
                return $d;
            }
        }

        return $safe[0];
    }
}
