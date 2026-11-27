<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * The Destructor 5000: aggressive territory grabber.
 * While the opponent is reachable, it picks the move that wins the most territory
 * (Voronoi) assuming the opponent's best reply, to cut them off.
 * Once separated, it fills its own space by hugging walls and running tight lines.
 */
final class Destructor5000Bot implements Bot
{
    private float $used = 0.0;
    private int $w = 0;
    private int $n = 0;

    public function name(): string { return 'The Destructor 5000'; }
    public function author(): string { return 'Destructor Pilot'; }
    public function color(): string { return '#ffd600'; }

    public function move(Arena $arena): Direction
    {
        $t0 = microtime(true);
        try {
            return $this->decide($arena);
        } finally {
            $this->used += microtime(true) - $t0;
        }
    }

    private function decide(Arena $arena): Direction
    {
        $me = $arena->me;
        $safe = $arena->safeDirections();
        if ($safe === []) {
            return $me->direction;
        }
        if (count($safe) === 1 || $this->used > 2.7) {
            return $safe[0];
        }

        $this->w = $w = $arena->width;
        $h = $arena->height;
        $this->n = $w * $h;
        $free = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $free[] = $arena->isFree($x, $y);
            }
        }

        $myIdx = $me->y * $w + $me->x;
        $opp = $arena->opponents[0] ?? null;

        $oppMoves = [];
        $oppIdx = null;
        if ($opp !== null) {
            $oppIdx = $opp->y * $w + $opp->x;
            foreach (Direction::cases() as $d) {
                $nx = $opp->x + $d->dx();
                $ny = $opp->y + $d->dy();
                if ($nx >= 0 && $ny >= 0 && $nx < $w && $ny < $h && $free[$ny * $w + $nx]) {
                    $oppMoves[] = $ny * $w + $nx;
                }
            }
        }

        // Are we still sharing space with the opponent?
        $separated = true;
        if ($oppIdx !== null && $oppMoves !== []) {
            $dist = $this->bfs($free, $myIdx);
            foreach ($oppMoves as $c) {
                if ($dist[$c] >= 0) {
                    $separated = false;
                    break;
                }
            }
        }

        $best = $safe[0];
        $bestScore = -INF;

        foreach ($safe as $d) {
            $c = ($me->y + $d->dy()) * $w + ($me->x + $d->dx());

            if ($separated || $this->used > 2.0) {
                $score = $this->survivalScore($free, $c, $oppMoves);
            } else {
                $score = INF;
                foreach ($oppMoves as $o) {
                    if ($o === $c) {
                        $s = -5000; // head-on: draw at best
                    } else {
                        $f = $free;
                        $f[$c] = false;
                        $f[$o] = false;
                        $dm = $this->bfs($f, $c);
                        $do = $this->bfs($f, $o);
                        $mine = 0;
                        $theirs = 0;
                        for ($i = 0; $i < $this->n; $i++) {
                            $a = $dm[$i];
                            $b = $do[$i];
                            if ($a >= 0 && ($b < 0 || $a < $b)) {
                                $mine++;
                            } elseif ($b >= 0 && ($a < 0 || $b < $a)) {
                                $theirs++;
                            }
                        }
                        $s = $mine - $theirs;
                        if ($mine === 0) {
                            $s -= 3000; // dead end
                        }
                    }
                    $score = min($score, $s);
                }
                // Small tie-break: hug walls/trails to keep space compact.
                $score = $score * 10 - $this->freeNeighbours($free, $c);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best;
    }

    private function survivalScore(array $free, int $c, array $oppMoves): float
    {
        $f = $free;
        $f[$c] = false;
        $dist = $this->bfs($f, $c);
        $reach = 0;
        foreach ($dist as $v) {
            if ($v > 0) {
                $reach++;
            }
        }
        $score = $reach * 10 - $this->freeNeighbours($f, $c);
        if (in_array($c, $oppMoves, true)) {
            $score -= 100000; // never risk a head-on
        }

        return $score;
    }

    private function freeNeighbours(array $free, int $c): int
    {
        $w = $this->w;
        $x = $c % $w;
        $k = 0;
        if ($x > 0 && $free[$c - 1]) { $k++; }
        if ($x < $w - 1 && $free[$c + 1]) { $k++; }
        if ($c >= $w && $free[$c - $w]) { $k++; }
        if ($c < $this->n - $w && $free[$c + $w]) { $k++; }

        return $k;
    }

    /** @return int[] distance per cell, -1 when unreachable */
    private function bfs(array $free, int $start): array
    {
        $w = $this->w;
        $n = $this->n;
        $dist = array_fill(0, $n, -1);
        $dist[$start] = 0;
        $q = [$start];
        $i = 0;
        while (isset($q[$i])) {
            $c = $q[$i++];
            $d = $dist[$c] + 1;
            $x = $c % $w;
            if ($x > 0) { $nb = $c - 1; if ($free[$nb] && $dist[$nb] < 0) { $dist[$nb] = $d; $q[] = $nb; } }
            if ($x < $w - 1) { $nb = $c + 1; if ($free[$nb] && $dist[$nb] < 0) { $dist[$nb] = $d; $q[] = $nb; } }
            if ($c >= $w) { $nb = $c - $w; if ($free[$nb] && $dist[$nb] < 0) { $dist[$nb] = $d; $q[] = $nb; } }
            if ($c < $n - $w) { $nb = $c + $w; if ($free[$nb] && $dist[$nb] < 0) { $dist[$nb] = $d; $q[] = $nb; } }
        }

        return $dist;
    }
}
