<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * xSnek: a strategy-switching hunter.
 *
 * - HUNT (arena still mostly empty): 1-ply simultaneous minimax scored by Voronoi territory,
 *   plus a pull towards a 4-6 cell gap from the opponent's head, so it closes in and cuts them off.
 * - CONTEST (arena filling up, still connected): pure Voronoi minimax, no aggression term.
 * - SAFE / FILL (cramped, danger nearby or separated from the opponent): maximise reachable space,
 *   hugging walls and its own trail so turns become tight zigzags without leaving holes.
 */
final class MarekXSnekBot implements Bot
{
    private const DIRS = [Direction::Up, Direction::Right, Direction::Down, Direction::Left];

    private int $w = 0;
    private int $h = 0;
    /** @var array<int, bool> */
    private array $free = [];
    private float $timeUsed = 0.0;

    public function name(): string { return 'xSnek'; }
    public function author(): string { return 'Marek from XTB'; }
    public function color(): string { return '#e01b24'; }

    public function move(Arena $arena): Direction
    {
        $start = hrtime(true);
        try {
            return $this->decide($arena);
        } finally {
            $this->timeUsed += (hrtime(true) - $start) / 1e9;
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

        $this->w = $arena->width;
        $this->h = $arena->height;
        $this->free = [];
        $freeCount = 0;
        for ($y = 0; $y < $this->h; $y++) {
            for ($x = 0; $x < $this->w; $x++) {
                $f = $arena->isFree($x, $y);
                $this->free[$y * $this->w + $x] = $f;
                if ($f) {
                    $freeCount++;
                }
            }
        }

        $myPos = $me->y * $this->w + $me->x;
        $opp = $arena->opponents[0] ?? null;

        // Out of time budget: simplest safe strategy.
        if ($opp === null || $this->timeUsed > 2.4) {
            return $this->fillMove($myPos, $safe, null);
        }

        $oppPos = $opp->y * $this->w + $opp->x;
        $oppMoves = [];
        foreach (self::DIRS as $d) {
            $n = $this->step($oppPos, $d);
            if ($n !== null && $this->free[$n]) {
                $oppMoves[] = $n;
            }
        }

        // Separated from the opponent? Then it's a pure space-filling race.
        if (!$this->connected($myPos, $oppPos)) {
            return $this->fillMove($myPos, $safe, null);
        }

        $freeRatio = $freeCount / ($this->w * $this->h);

        // Danger guard: few free neighbours or a tiny pocket ahead → safe, simple mode.
        $myFreeNeighbours = count($safe);
        $bestReach = 0;
        foreach ($safe as $d) {
            $bestReach = max($bestReach, $this->reach($this->step($myPos, $d), []));
        }
        if ($bestReach < 40 || ($myFreeNeighbours <= 2 && $freeRatio < 0.35)) {
            return $this->fillMove($myPos, $safe, $oppMoves);
        }

        $aggressive = $freeRatio > 0.55;

        $bestDir = $safe[0];
        $bestScore = -INF;
        foreach ($safe as $d) {
            $m = $this->step($myPos, $d);
            $worst = INF;
            $replies = $oppMoves === [] ? [null] : $oppMoves;
            foreach ($replies as $o) {
                if ($o === $m) {
                    $score = -5000; // head-on = draw, not a win
                } else {
                    $score = $this->evaluate($m, $o ?? $oppPos, $o === null, $aggressive);
                }
                $worst = min($worst, $score);
            }
            // Tie-break: hug obstacles (tight turns, no holes).
            $worst += 0.1 * $this->blockedNeighbours($m);
            if ($worst > $bestScore) {
                $bestScore = $worst;
                $bestDir = $d;
            }
        }

        return $bestDir;
    }

    /** Voronoi score for positions after both moved. */
    private function evaluate(int $m, int $o, bool $oppDead, bool $aggressive): float
    {
        if ($oppDead) {
            return 10000 + $this->reach($m, []);
        }

        $this->free[$m] = false;
        $this->free[$o] = false;
        $dMe = $this->bfs($m);
        $dOp = $this->bfs($o);
        $this->free[$m] = true;
        $this->free[$o] = true;

        $mine = 0;
        $theirs = 0;
        $headDist = PHP_INT_MAX;
        foreach ($dMe as $cell => $dm) {
            $do = $dOp[$cell] ?? PHP_INT_MAX;
            if ($dm < $do) {
                $mine++;
            } elseif ($do < $dm) {
                $theirs++;
            }
        }
        foreach ($dOp as $cell => $do) {
            if (!isset($dMe[$cell])) {
                $theirs++;
            }
        }
        // Distance between heads: smallest my-distance to a cell next to their head.
        foreach (self::DIRS as $d) {
            $n = $this->step($o, $d);
            if ($n !== null && isset($dMe[$n])) {
                $headDist = min($headDist, $dMe[$n] + 1);
            }
        }

        $connected = $headDist !== PHP_INT_MAX;
        if (!$connected) {
            // Cut off: whoever has more room wins.
            return 1000 * (count($dMe) <=> count($dOp)) + (count($dMe) - count($dOp));
        }

        $score = (float) ($mine - $theirs);
        if ($aggressive) {
            // Close the gap to ~4-6 cells, don't hug their head.
            if ($headDist > 6) {
                $score -= 1.5 * ($headDist - 6);
            } elseif ($headDist < 4) {
                $score -= 3 * (4 - $headDist);
            }
        }

        return $score;
    }

    /** Safe / fill move: most reachable space, avoid opponent's next cells, hug walls & own trail. */
    private function fillMove(int $myPos, array $safe, ?array $oppMoves): Direction
    {
        $avoid = $oppMoves ? array_flip($oppMoves) : [];
        $best = $safe[0];
        $bestScore = -INF;
        foreach ($safe as $d) {
            $n = $this->step($myPos, $d);
            $space = $this->reach($n, [$myPos]);
            $score = $space * 10 + $this->blockedNeighbours($n) * 3;
            if (isset($avoid[$n])) {
                $score -= 1000;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best;
    }

    private function step(int $pos, Direction $d): ?int
    {
        $x = $pos % $this->w + $d->dx();
        $y = intdiv($pos, $this->w) + $d->dy();
        if ($x < 0 || $y < 0 || $x >= $this->w || $y >= $this->h) {
            return null;
        }

        return $y * $this->w + $x;
    }

    /** @return int[] */
    private function neighbours(int $pos): array
    {
        $x = $pos % $this->w;
        $out = [];
        if ($pos >= $this->w) { $out[] = $pos - $this->w; }
        if ($pos < $this->w * ($this->h - 1)) { $out[] = $pos + $this->w; }
        if ($x > 0) { $out[] = $pos - 1; }
        if ($x < $this->w - 1) { $out[] = $pos + 1; }

        return $out;
    }

    private function blockedNeighbours(int $pos): int
    {
        $n = $this->neighbours($pos);
        $blocked = 4 - count($n);
        foreach ($n as $c) {
            if (!$this->free[$c]) {
                $blocked++;
            }
        }

        return $blocked;
    }

    /** BFS distances from a head cell (the head itself is treated as occupied). */
    private function bfs(int $start): array
    {
        $dist = [];
        $queue = [$start];
        $depth = [$start => 0];
        for ($i = 0; $i < count($queue); $i++) {
            $c = $queue[$i];
            $dc = $depth[$c];
            foreach ($this->neighbours($c) as $n) {
                if ($this->free[$n] && !isset($depth[$n])) {
                    $depth[$n] = $dc + 1;
                    $dist[$n] = $dc + 1;
                    $queue[] = $n;
                }
            }
        }

        return $dist;
    }

    /** Free cells reachable from $start (inclusive), treating $blocked as walls. */
    private function reach(?int $start, array $blocked): int
    {
        if ($start === null || !$this->free[$start]) {
            return 0;
        }
        $seen = [$start => true];
        foreach ($blocked as $b) {
            $seen[$b] = true;
        }
        $stack = [$start];
        $count = 0;
        while ($stack) {
            $c = array_pop($stack);
            $count++;
            foreach ($this->neighbours($c) as $n) {
                if ($this->free[$n] && !isset($seen[$n])) {
                    $seen[$n] = true;
                    $stack[] = $n;
                }
            }
        }

        return $count;
    }

    private function connected(int $myPos, int $oppPos): bool
    {
        $targets = [];
        foreach ($this->neighbours($oppPos) as $n) {
            $targets[$n] = true;
        }
        $seen = [$myPos => true];
        $stack = [$myPos];
        while ($stack) {
            $c = array_pop($stack);
            foreach ($this->neighbours($c) as $n) {
                if (isset($targets[$n]) && $this->free[$n]) {
                    return true;
                }
                if ($this->free[$n] && !isset($seen[$n])) {
                    $seen[$n] = true;
                    $stack[] = $n;
                }
            }
        }

        return false;
    }
}
