<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Crimson Voronoi by REMI.
 *
 * While the two cycles share space: a one-ply simultaneous-move minimax over
 * Voronoi territory (cells I reach strictly before the opponent), the evaluation
 * used by the strongest Tron bots. It naturally cuts the opponent off early.
 * Once the cycles are separated: survival fill mode, maximising reachable space
 * and hugging walls so no cell is wasted.
 */
final class RemiCrimsonBot implements Bot
{
    private const INF = 1 << 20;

    private float $spent = 0.0;
    private int $w = 0;
    private int $h = 0;
    /** @var array<int, bool> */
    private array $free = [];

    public function name(): string { return 'Crimson Voronoi'; }
    public function author(): string { return 'REMI'; }
    public function color(): string { return '#e01b24'; }

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
        $safe = $arena->safeDirections();
        if ($safe === []) {
            return $me->direction;
        }
        if (count($safe) === 1) {
            return $safe[0];
        }

        $this->w = $w = $arena->width;
        $this->h = $h = $arena->height;
        $free = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $free[$y * $w + $x] = $arena->isFree($x, $y);
            }
        }
        $this->free = $free;

        $opp = $arena->opponents[0] ?? null;
        $myCells = [];
        foreach ($safe as $d) {
            $myCells[$d->name] = ($me->y + $d->dy()) * $w + ($me->x + $d->dx());
        }

        // Cheap fallback if the match time budget is running low.
        if ($opp === null || $this->spent > 2.3) {
            return $this->survival($safe, $myCells);
        }

        $oppHead = $opp->y * $w + $opp->x;
        $oppCells = [];
        foreach ([Direction::Up, Direction::Down, Direction::Left, Direction::Right] as $d) {
            if ($d === $opp->direction->opposite()) {
                continue;
            }
            $nx = $opp->x + $d->dx();
            $ny = $opp->y + $d->dy();
            if ($nx >= 0 && $ny >= 0 && $nx < $w && $ny < $h && $free[$ny * $w + $nx]) {
                $oppCells[] = $ny * $w + $nx;
            }
        }

        // Are we still in the same region? If not, just fill our space perfectly.
        $reach = $this->bfs($me->y * $w + $me->x, -1);
        $connected = false;
        foreach ([$oppHead - $w, $oppHead + $w, $oppHead - 1, $oppHead + 1] as $c) {
            if (isset($reach[$c]) && $this->adjacent($oppHead, $c)) {
                $connected = true;
                break;
            }
        }
        if (!$connected || $oppCells === []) {
            return $this->survival($safe, $myCells);
        }

        // Distance maps for each candidate head.
        $myDist = [];
        foreach ($myCells as $k => $c) {
            $myDist[$k] = $this->bfs($c, $oppHead);
        }
        $oppDist = [];
        foreach ($oppCells as $i => $c) {
            $oppDist[$i] = $this->bfs($c, $me->y * $w + $me->x);
        }

        $best = null;
        $bestScore = -PHP_INT_MAX;
        foreach ($safe as $d) {
            $k = $d->name;
            $mc = $myCells[$k];
            $worst = PHP_INT_MAX;
            foreach ($oppCells as $i => $oc) {
                if ($oc === $mc) {
                    $score = -50000; // head-on: draw, avoid unless everything else loses
                } else {
                    $score = $this->voronoi($myDist[$k], $oppDist[$i], $mc, $oc);
                }
                if ($score < $worst) {
                    $worst = $score;
                }
            }
            // Tiebreak: keep a bit of breathing room.
            $worst = $worst * 8 + $this->freeNeighbours($mc, $oppHead);
            if ($worst > $bestScore) {
                $bestScore = $worst;
                $best = $d;
            }
        }

        return $best ?? $safe[0];
    }

    /** Territory score: cells I reach first minus cells the opponent reaches first. */
    private function voronoi(array $mine, array $theirs, int $mc, int $oc): int
    {
        $score = 0;
        foreach ($mine as $c => $dm) {
            if ($c === $oc) {
                continue;
            }
            $dt = $theirs[$c] ?? self::INF;
            if ($dm < $dt) {
                $score++;
            } elseif ($dt < $dm) {
                $score--;
            }
        }
        foreach ($theirs as $c => $dt) {
            if ($c !== $mc && !isset($mine[$c])) {
                $score--;
            }
        }

        return $score;
    }

    /** Separated: maximise reachable space, hug walls, avoid splitting our own region. */
    private function survival(array $safe, array $myCells): Direction
    {
        $best = $safe[0];
        $bestScore = -PHP_INT_MAX;
        foreach ($safe as $d) {
            $c = $myCells[$d->name];
            $this->free[$c] = false;
            $space = $this->bestChildSpace($c);
            $this->free[$c] = true;
            $score = $space * 16 - $this->freeNeighbours($c, -1) * 2;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best;
    }

    /** Two-step lookahead: the largest region we can still enter from cell $c. */
    private function bestChildSpace(int $c): int
    {
        $best = 0;
        foreach ($this->neighbours($c) as $n) {
            if ($this->free[$n]) {
                $best = max($best, count($this->bfs($n, -1)));
            }
        }

        return $best;
    }

    /** @return array<int, int> cell => distance */
    private function bfs(int $start, int $blocked): array
    {
        $free = $this->free;
        $w = $this->w;
        $size = $w * $this->h;
        $dist = [$start => 0];
        $queue = [$start];
        $head = 0;
        while (isset($queue[$head])) {
            $c = $queue[$head++];
            $nd = $dist[$c] + 1;
            $x = $c % $w;
            if ($x > 0) {
                $n = $c - 1;
                if ($free[$n] && $n !== $blocked && !isset($dist[$n])) { $dist[$n] = $nd; $queue[] = $n; }
            }
            if ($x < $w - 1) {
                $n = $c + 1;
                if ($free[$n] && $n !== $blocked && !isset($dist[$n])) { $dist[$n] = $nd; $queue[] = $n; }
            }
            $n = $c - $w;
            if ($n >= 0 && $free[$n] && $n !== $blocked && !isset($dist[$n])) { $dist[$n] = $nd; $queue[] = $n; }
            $n = $c + $w;
            if ($n < $size && $free[$n] && $n !== $blocked && !isset($dist[$n])) { $dist[$n] = $nd; $queue[] = $n; }
        }

        return $dist;
    }

    /** @return list<int> */
    private function neighbours(int $c): array
    {
        $w = $this->w;
        $x = $c % $w;
        $out = [];
        if ($x > 0) { $out[] = $c - 1; }
        if ($x < $w - 1) { $out[] = $c + 1; }
        if ($c - $w >= 0) { $out[] = $c - $w; }
        if ($c + $w < $w * $this->h) { $out[] = $c + $w; }

        return $out;
    }

    private function adjacent(int $a, int $b): bool
    {
        return in_array($b, $this->neighbours($a), true);
    }

    private function freeNeighbours(int $c, int $blocked): int
    {
        $n = 0;
        foreach ($this->neighbours($c) as $x) {
            if ($this->free[$x] && $x !== $blocked) {
                $n++;
            }
        }

        return $n;
    }
}
