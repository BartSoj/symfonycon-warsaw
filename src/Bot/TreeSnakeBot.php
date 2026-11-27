<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * TreeSnake: an aggressive cutter. For every move it plays a one-ply minimax over
 * a Voronoi split of the board (who reaches each cell first) and picks the move
 * that grabs the most territory from the opponent, while never boxing itself in.
 */
final class TreeSnakeBot implements Bot
{
    public function name(): string { return 'TreeSnake'; }
    public function author(): string { return 'TreeSnake Crew'; }
    public function color(): string { return '#7851a9'; }

    public function move(Arena $arena): Direction
    {
        $me = $arena->me;
        $safe = $arena->safeDirections();
        if ($safe === []) {
            return $me->direction;
        }

        $w = $arena->width;
        $h = $arena->height;
        $free = [];
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $free[$y * $w + $x] = $arena->isFree($x, $y);
            }
        }

        $opp = $arena->opponents[0] ?? null;
        $oppMoves = [];
        if ($opp !== null) {
            foreach (Direction::cases() as $d) {
                $nx = $opp->x + $d->dx();
                $ny = $opp->y + $d->dy();
                if ($nx >= 0 && $ny >= 0 && $nx < $w && $ny < $h && $free[$ny * $w + $nx]) {
                    $oppMoves[] = $ny * $w + $nx;
                }
            }
        }

        $best = $safe[0];
        $bestScore = PHP_INT_MIN;
        foreach ($safe as $d) {
            $mine = ($me->y + $d->dy()) * $w + ($me->x + $d->dx());

            if ($opp === null || $oppMoves === []) {
                // Opponent is stuck: just keep the most room for ourselves.
                $score = 100000 + $this->voronoi($free, $w, $h, $mine, -1);
            } else {
                $score = PHP_INT_MAX;
                foreach ($oppMoves as $theirs) {
                    if ($theirs === $mine) {
                        $s = -50000; // head-on: a draw, better than dying
                    } else {
                        $s = $this->voronoi($free, $w, $h, $mine, $theirs);
                    }
                    $score = min($score, $s);
                }
            }

            // Small tie-breaker: prefer going straight.
            if ($d === $me->direction) {
                $score += 0.5;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best;
    }

    /**
     * Simultaneous BFS from both heads. Returns (cells I reach first) - (cells they reach first).
     * If I'm trapped in nothing, returns a big negative number.
     */
    private function voronoi(array $free, int $w, int $h, int $mine, int $theirs): float
    {
        $free[$mine] = false;
        if ($theirs >= 0) {
            $free[$theirs] = false;
        }

        $owner = [$mine => 1];
        $queue = [[$mine, 1]];
        if ($theirs >= 0) {
            $owner[$theirs] = 2;
            $queue[] = [$theirs, 2];
        }

        $myCount = 0;
        $theirCount = 0;
        $i = 0;
        while ($i < \count($queue)) {
            [$c, $o] = $queue[$i++];
            $x = $c % $w;
            $y = intdiv($c, $w);
            foreach ([[$x + 1, $y], [$x - 1, $y], [$x, $y + 1], [$x, $y - 1]] as [$nx, $ny]) {
                if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                    continue;
                }
                $n = $ny * $w + $nx;
                if (!$free[$n] || isset($owner[$n])) {
                    continue;
                }
                $owner[$n] = $o;
                $queue[] = [$n, $o];
                if ($o === 1) {
                    ++$myCount;
                } else {
                    ++$theirCount;
                }
            }
        }

        if ($myCount === 0) {
            return -100000.0;
        }

        return (float) ($myCount - $theirCount);
    }
}
