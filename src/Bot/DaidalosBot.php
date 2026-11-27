<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Daidalos: iterative-deepening alpha-beta over simultaneous moves with a Voronoi
 * evaluation while the opponent is reachable, then a parity-bounded longest-path
 * fill once sealed off.
 */
final class DaidalosBot implements Bot
{
    private const WIN = 1_000_000.0;
    private const TOTAL_BUDGET = 2_550_000_000;     // ns, 15% under the 3 s match limit
    private const CONTESTED_POOL = 1_750_000_000;   // ns, leaves >= 30% for the sealed-off phase
    private const MOVE_CAP = 30_000_000;            // ns, well under the 50 ms move limit

    private int $w = 0;
    private int $h = 0;
    private int $lastFree = PHP_INT_MAX;
    /** @var array<int, list<int>> */
    private array $nb = [];
    /** @var array<int, int> */
    private array $col = [];
    /** @var array<int, int> 1 = blocked */
    private array $g = [];
    private int $used = 0;
    private int $usedContested = 0;
    private int $deadline = 0;
    private bool $abort = false;
    private int $nodes = 0;
    private float $draw = 0.0;

    public function name(): string { return 'Daidalos'; }
    public function author(): string { return 'partners.cz'; }
    public function color(): string { return '#009fb5'; }

    public function move(Arena $arena): Direction
    {
        $start = hrtime(true);
        try {
            $dir = $this->think($arena, $start);
        } catch (\Throwable) {
            $dir = $this->fallback($arena);
        }
        $this->used += hrtime(true) - $start;

        return $dir;
    }

    private function think(Arena $arena, int $start): Direction
    {
        $w = $arena->width;
        $h = $arena->height;
        $n = $w * $h;

        // Board
        $g = [];
        $free = 0;
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $f = $arena->isFree($x, $y);
                $g[$y * $w + $x] = $f ? 0 : 1;
                if ($f) {
                    ++$free;
                }
            }
        }

        // New match detection: board size change or more free cells than before.
        if ($w !== $this->w || $h !== $this->h || $free > $this->lastFree) {
            $this->reset($w, $h);
        }
        $this->lastFree = $free;

        $me = $arena->me->y * $w + $arena->me->x;
        $op = isset($arena->opponents[0]) ? $arena->opponents[0]->y * $w + $arena->opponents[0]->x : -1;
        $g[$me] = 1;
        if ($op >= 0 && $op < $n) {
            $g[$op] = 1;
        } else {
            $op = -1;
        }
        $this->g = $g;
        unset($g);
        $g = $this->g;

        $moves = [];
        foreach ($this->nb[$me] as $c) {
            if ($g[$c] === 0) {
                $moves[] = $c;
            }
        }
        if ($moves === []) {
            return $arena->me->direction;
        }
        if (\count($moves) === 1) {
            return $this->dirTo($me, $moves[0]);
        }

        $this->draw = -0.1 * $free;

        // Connected?
        $connected = $op >= 0 && $this->connected($me, $op);

        $remaining = self::TOTAL_BUDGET - $this->used;
        if ($connected) {
            $pool = self::CONTESTED_POOL - $this->usedContested;
            $budget = (int) min(self::MOVE_CAP, max(2_000_000, $pool / 15), max(500_000, $remaining / 40));
            $this->deadline = $start + $budget;
            $best = $this->contested($me, $op, $moves);
            $this->usedContested += hrtime(true) - $start;
        } else {
            $region = $this->flood($me);
            $budget = (int) min(self::MOVE_CAP, max(300_000, $remaining / max(10, $region)));
            $this->deadline = $start + $budget;
            $best = $this->endgame($me, $moves);
        }

        return $this->dirTo($me, $best);
    }

    private function reset(int $w, int $h): void
    {
        $this->w = $w;
        $this->h = $h;
        $this->used = 0;
        $this->usedContested = 0;
        $this->nb = [];
        $this->col = [];
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $i = $y * $w + $x;
                $l = [];
                if ($y > 0) { $l[] = $i - $w; }
                if ($x < $w - 1) { $l[] = $i + 1; }
                if ($y < $h - 1) { $l[] = $i + $w; }
                if ($x > 0) { $l[] = $i - 1; }
                $this->nb[$i] = $l;
                $this->col[$i] = ($x + $y) & 1;
            }
        }
    }

    private function dirTo(int $from, int $to): Direction
    {
        $d = $to - $from;
        if ($d === 1) { return Direction::Right; }
        if ($d === -1) { return Direction::Left; }
        if ($d === $this->w) { return Direction::Down; }

        return Direction::Up;
    }

    // ---------------------------------------------------------------- contested phase

    /** @param list<int> $moves */
    private function contested(int $me, int $op, array $moves): int
    {
        $this->abort = false;
        $order = $moves;
        $bestMove = $moves[0];
        $bestVal = -INF;

        for ($depth = 1; $depth <= 40; ++$depth) {
            $vals = [];
            $alpha = -INF;
            $iterBest = $order[0];
            foreach ($order as $m) {
                $v = $this->minNode($me, $op, $m, $depth, $alpha, INF);
                if ($this->abort) {
                    break 2;
                }
                $vals[$m] = $v;
                if ($v > $alpha) {
                    $alpha = $v;
                    $iterBest = $m;
                }
            }
            $bestMove = $iterBest;
            $bestVal = $alpha;
            arsort($vals);
            $order = array_keys($vals);
            if (abs($bestVal) >= self::WIN / 2) {
                break; // forced result found
            }
            if (hrtime(true) > $this->deadline) {
                break;
            }
        }

        if ($bestVal <= -self::WIN / 2) {
            // Every line loses against perfect play: take the most room and hope.
            return $this->biggestFlood($moves);
        }

        return $bestMove;
    }

    /** I move to $m; opponent picks the reply that is worst for me. */
    private function minNode(int $me, int $op, int $m, int $depth, float $alpha, float $beta): float
    {
        $g = &$this->g;
        $worst = INF;
        $any = false;
        foreach ($this->nb[$op] as $o) {
            if ($g[$o] !== 0) {
                continue;
            }
            $any = true;
            if ($o === $m) {
                $v = $this->draw;
            } else {
                $g[$m] = 1;
                $g[$o] = 1;
                $v = $this->maxNode($m, $o, $depth - 1, $alpha, min($beta, $worst));
                $g[$m] = 0;
                $g[$o] = 0;
                if ($this->abort) {
                    return 0.0;
                }
            }
            if ($v < $worst) {
                $worst = $v;
                if ($worst <= $alpha) {
                    return $worst;
                }
            }
        }
        if (!$any) {
            return self::WIN; // opponent crashes, I survive this tick
        }

        return $worst;
    }

    private function maxNode(int $me, int $op, int $depth, float $alpha, float $beta): float
    {
        if ((++$this->nodes & 15) === 0 && hrtime(true) > $this->deadline) {
            $this->abort = true;

            return 0.0;
        }
        $g = $this->g;
        $mine = [];
        foreach ($this->nb[$me] as $c) {
            if ($g[$c] === 0) {
                $mine[] = $c;
            }
        }
        $theirs = false;
        foreach ($this->nb[$op] as $c) {
            if ($g[$c] === 0) {
                $theirs = true;
                break;
            }
        }
        unset($g); // avoid copy-on-write when children modify the board
        if ($mine === []) {
            return $theirs ? -self::WIN + 1 : $this->draw;
        }
        if (!$theirs) {
            // Opponent is stuck; but could they share our only cell? They have none, so we win.
            return self::WIN - 1;
        }
        if ($depth <= 0) {
            return $this->evaluate($me, $op, $sep);
        }
        // Leaf-level check for separation: no point searching deeper once walled off.
        $v0 = $this->evaluate($me, $op, $sep);
        if ($sep) {
            return $v0;
        }

        $best = -INF;
        foreach ($mine as $m) {
            $v = $this->minNode($me, $op, $m, $depth, max($alpha, $best), $beta);
            if ($this->abort) {
                return 0.0;
            }
            if ($v > $best) {
                $best = $v;
                if ($best >= $beta) {
                    return $best;
                }
            }
        }

        return $best;
    }

    /**
     * Voronoi territory (plus a small edge term). When the two heads cannot reach
     * each other, score fillable space difference with a large weight.
     */
    private function evaluate(int $me, int $op, ?bool &$separated): float
    {
        $g = $this->g;
        $nb = $this->nb;
        $col = $this->col;
        $own = [$me => 1, $op => 2];
        $frontA = [$me];
        $frontB = [$op];
        $cntA = 0; $cntB = 0;
        $edgeA = 0; $edgeB = 0;
        $pa = [0, 0]; $pb = [0, 0];
        $connected = false;

        while ($frontA !== [] || $frontB !== []) {
            $newA = [];
            foreach ($frontA as $c) {
                foreach ($nb[$c] as $x) {
                    if (isset($own[$x])) {
                        if ($own[$x] !== 1) {
                            $connected = true;
                        }
                        continue;
                    }
                    if ($g[$x] !== 0) {
                        continue;
                    }
                    if (!isset($newA[$x])) {
                        $newA[$x] = true;
                    }
                }
            }
            $newB = [];
            foreach ($frontB as $c) {
                foreach ($nb[$c] as $x) {
                    if (isset($own[$x])) {
                        if ($own[$x] !== 2) {
                            $connected = true;
                        }
                        continue;
                    }
                    if ($g[$x] !== 0) {
                        continue;
                    }
                    $newB[$x] = true;
                }
            }
            $frontA = [];
            foreach ($newA as $x => $_) {
                if (isset($newB[$x])) {
                    $own[$x] = 3;
                    $connected = true;
                    unset($newB[$x]);
                    continue;
                }
                $own[$x] = 1;
                $frontA[] = $x;
                ++$cntA;
                ++$pa[$col[$x]];
                foreach ($nb[$x] as $y) {
                    if ($g[$y] === 0) {
                        ++$edgeA;
                    }
                }
            }
            $frontB = [];
            foreach ($newB as $x => $_) {
                $own[$x] = 2;
                $frontB[] = $x;
                ++$cntB;
                ++$pb[$col[$x]];
                foreach ($nb[$x] as $y) {
                    if ($g[$y] === 0) {
                        ++$edgeB;
                    }
                }
            }
        }

        $separated = !$connected;
        if ($separated) {
            $fa = self::parity($pa, $col[$me]);
            $fb = self::parity($pb, $col[$op]);
            $diff = $fa - $fb;
            if ($diff === 0) {
                return 0.5 * $this->draw; // likely an even race to the end: a coin flip at best
            }

            return 3.0 * $diff + ($diff > 0 ? 50.0 : -50.0);
        }

        return ($cntA - $cntB) + 0.03 * ($edgeA - $edgeB);
    }

    /** Longest path upper bound from a head of colour $headCol through a region with colour counts $p. */
    private static function parity(array $p, int $headCol): int
    {
        $a = $p[1 - $headCol]; // colour of the first step
        $b = $p[$headCol];

        return $a > $b ? 2 * $b + 1 : 2 * $a;
    }

    private function connected(int $me, int $op): bool
    {
        $g = $this->g;
        $nb = $this->nb;
        $target = [];
        foreach ($nb[$op] as $c) {
            $target[$c] = true;
        }
        $seen = [$me => true];
        $stack = [$me];
        while ($stack !== []) {
            $c = array_pop($stack);
            foreach ($nb[$c] as $x) {
                if ($x === $op) {
                    return true;
                }
                if (isset($seen[$x]) || $g[$x] !== 0) {
                    continue;
                }
                $seen[$x] = true;
                $stack[] = $x;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- sealed-off phase

    /** @param list<int> $moves */
    private function endgame(int $me, array $moves): int
    {
        $g = &$this->g;
        // Order: fewest free neighbours first (wall hugging), largest part last on ties.
        $info = [];
        foreach ($moves as $m) {
            $g[$m] = 1;
            $info[$m] = [$this->freeNeighbours($m), $this->fillBound($m)];
            $g[$m] = 0;
        }
        usort($moves, static fn ($a, $b) => [$info[$b][1], $info[$a][0]] <=> [$info[$a][1], $info[$b][0]]);

        $best = $moves[0];
        $bestVal = $info[$best][1];
        $this->abort = false;

        for ($depth = 1; $depth <= 400; ++$depth) {
            $iterBest = -1;
            $iterVal = -1;
            foreach ($moves as $m) {
                $g[$m] = 1;
                $v = 1 + $this->longest($m, $depth - 1, $iterVal - 1);
                $g[$m] = 0;
                if ($this->abort) {
                    break 2;
                }
                if ($v > $iterVal) {
                    $iterVal = $v;
                    $iterBest = $m;
                }
            }
            $best = $iterBest;
            $bestVal = $iterVal;
            // Put the best first for the next iteration.
            usort($moves, static fn ($a, $b) => ($b === $best) <=> ($a === $best));
            if ($depth >= $bestVal || hrtime(true) > $this->deadline) {
                break; // search reached the bound or out of time
            }
        }

        return $best;
    }

    /** Longest path from $c (already occupied) with depth limit; leaf = parity-bounded fill. */
    private function longest(int $c, int $depth, int $need): int
    {
        if ((++$this->nodes & 7) === 0 && hrtime(true) > $this->deadline) {
            $this->abort = true;

            return 0;
        }
        $bound = $this->fillBound($c);
        if ($depth <= 0 || $bound <= 0 || $bound <= $need) {
            return $bound;
        }
        $g = &$this->g;
        $kids = [];
        foreach ($this->nb[$c] as $x) {
            if ($g[$x] === 0) {
                $kids[$x] = $this->freeNeighbours($x);
            }
        }
        asort($kids);
        $best = 0;
        foreach ($kids as $x => $_) {
            $g[$x] = 1;
            $v = 1 + $this->longest($x, $depth - 1, max($need, $best) - 1);
            $g[$x] = 0;
            if ($this->abort) {
                return 0;
            }
            if ($v > $best) {
                $best = $v;
                if ($best >= $bound) {
                    break;
                }
            }
        }

        return $best;
    }

    private function freeNeighbours(int $c): int
    {
        $n = 0;
        foreach ($this->nb[$c] as $x) {
            if ($this->g[$x] === 0) {
                ++$n;
            }
        }

        return $n;
    }

    /** Parity-bounded number of further steps possible from head $c. */
    private function fillBound(int $c): int
    {
        $g = $this->g;
        $nb = $this->nb;
        $col = $this->col;
        $seen = [$c => true];
        $stack = [$c];
        $p = [0, 0];
        while ($stack !== []) {
            $x = array_pop($stack);
            foreach ($nb[$x] as $y) {
                if (isset($seen[$y]) || $g[$y] !== 0) {
                    continue;
                }
                $seen[$y] = true;
                ++$p[$col[$y]];
                $stack[] = $y;
            }
        }

        return self::parity($p, $col[$c]);
    }

    private function flood(int $c): int
    {
        $g = $this->g;
        $nb = $this->nb;
        $seen = [$c => true];
        $stack = [$c];
        $n = 0;
        while ($stack !== []) {
            $x = array_pop($stack);
            foreach ($nb[$x] as $y) {
                if (isset($seen[$y]) || $g[$y] !== 0) {
                    continue;
                }
                $seen[$y] = true;
                ++$n;
                $stack[] = $y;
            }
        }

        return $n;
    }

    /** @param list<int> $moves */
    private function biggestFlood(array $moves): int
    {
        $g = &$this->g;
        $best = $moves[0];
        $bestN = -1;
        foreach ($moves as $m) {
            $g[$m] = 1;
            $n = $this->flood($m);
            $g[$m] = 0;
            if ($n > $bestN) {
                $bestN = $n;
                $best = $m;
            }
        }

        return $best;
    }

    // ---------------------------------------------------------------- safety net

    private function fallback(Arena $arena): Direction
    {
        $best = $arena->me->direction;
        $bestN = -1;
        foreach ($arena->safeDirections() as $d) {
            $sx = $arena->me->x + $d->dx();
            $sy = $arena->me->y + $d->dy();
            $seen = [$sy * $arena->width + $sx => true];
            $stack = [[$sx, $sy]];
            $n = 0;
            while ($stack !== [] && $n < 600) {
                [$x, $y] = array_pop($stack);
                ++$n;
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    $nx = $x + $dx;
                    $ny = $y + $dy;
                    $k = $ny * $arena->width + $nx;
                    if (!isset($seen[$k]) && $arena->isFree($nx, $ny)) {
                        $seen[$k] = true;
                        $stack[] = [$nx, $ny];
                    }
                }
            }
            if ($n > $bestN) {
                $bestN = $n;
                $best = $d;
            }
        }

        return $best;
    }
}
