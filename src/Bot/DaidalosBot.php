<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Daidalos: iterative-deepening alpha-beta with killer moves and Voronoi territory while
 * the opponent is reachable; parity-bounded longest-path search once sealed off.
 */
final class DaidalosBot implements Bot
{
    private const WIN = 1_000_000.0;
    private const TOTAL_BUDGET = 2_550_000_000; // ns
    private const MOVE_CAP = 25_000_000;        // ns
    private const END_CAP = 6_000_000;          // ns
    private const DRAW_FACTOR = 0.02;
    private const SEPARATION_BONUS = 40;

    private int $w = 0;
    private int $h = 0;
    private int $lastFree = PHP_INT_MAX;
    /** @var array<int, list<int>> */
    private array $nb = [];
    /** @var array<int, int> */
    private array $col = [];
    /** @var array<int, int> */
    private array $g = [];
    private int $used = 0;
    /** @var array<int, int> */
    private array $killMe = [];
    /** @var array<int, int> */
    private array $killOp = [];
    private int $deadline = 0;
    private int $softStop = 0;
    private bool $abort = false;
    private int $calls = 0;
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

        // 1. Board
        $g = [];
        $free = 0;
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                if ($arena->isFree($x, $y)) {
                    $g[$y * $w + $x] = 0;
                    ++$free;
                } else {
                    $g[$y * $w + $x] = 1;
                }
            }
        }

        // 2. New match detection
        if ($w !== $this->w || $h !== $this->h || $free > $this->lastFree) {
            $this->reset($w, $h);
        }
        $this->lastFree = $free;

        // 3. Heads
        $me = $arena->me->y * $w + $arena->me->x;
        $op = -1;
        if (isset($arena->opponents[0])) {
            $o = $arena->opponents[0];
            if ($o->x >= 0 && $o->y >= 0 && $o->x < $w && $o->y < $h) {
                $op = $o->y * $w + $o->x;
            }
        }
        $g[$me] = 1;
        if ($op >= 0) {
            $g[$op] = 1;
        }
        $this->g = $g;
        unset($g);

        // 4. Moves
        $moves = [];
        foreach ($this->nb[$me] as $c) {
            if ($this->g[$c] === 0) {
                $moves[] = $c;
            }
        }
        if ($moves === []) {
            return $arena->me->direction;
        }
        if (\count($moves) === 1) {
            return $this->dirTo($me, $moves[0]);
        }

        // 5. Draw value
        $this->draw = -self::DRAW_FACTOR * $free;

        // 6. Connected test (same flood fill gives reach)
        [$reach, $connected] = $this->floodFrom($me, $op);

        // 7. Budget and search
        $remaining = self::TOTAL_BUDGET - $this->used;
        $this->abort = false;
        $this->calls = 0;
        if ($connected) {
            $budget = (int) min(self::MOVE_CAP, max(1_000_000, intdiv($remaining, max(20, intdiv($reach, 4)))));
            if ($remaining < 60_000_000) {
                $budget = 500_000;
            }
            $this->deadline = $start + $budget;
            $this->softStop = $start + intdiv($budget, 2);
            $this->killMe = [];
            $this->killOp = [];
            $best = $this->contested($me, $op, $moves);
        } else {
            $budget = (int) min(self::END_CAP, max(300_000, intdiv($remaining, max(10, $reach))));
            $this->deadline = $start + $budget;
            $best = $this->endgame($moves);
        }

        // 8. Direction
        return $this->dirTo($me, $best);
    }

    private function reset(int $w, int $h): void
    {
        $this->w = $w;
        $this->h = $h;
        $this->used = 0;
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

    /** @return array{int, bool} reachable free cells from $from, and whether the fill touches $target */
    private function floodFrom(int $from, int $target): array
    {
        $g = $this->g;
        $nb = $this->nb;
        $seen = [$from => true];
        $stack = [$from];
        $n = 0;
        $touch = false;
        while ($stack !== []) {
            $c = array_pop($stack);
            foreach ($nb[$c] as $x) {
                if ($x === $target) {
                    $touch = true;
                }
                if (isset($seen[$x]) || $g[$x] !== 0) {
                    continue;
                }
                $seen[$x] = true;
                ++$n;
                $stack[] = $x;
            }
        }

        return [$n, $touch];
    }

    // ---------------------------------------------------------------- contested search

    /** @param list<int> $moves */
    private function contested(int $me, int $op, array $moves): int
    {
        $order = $moves;
        $bestMove = $moves[0];
        $bestValue = -INF;

        for ($depth = 1; $depth <= 40; ++$depth) {
            $alpha = -INF;
            $iterBest = $order[0];
            $vals = [];
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
            $bestValue = $alpha;
            usort($order, static fn (int $a, int $b): int => $vals[$b] <=> $vals[$a]);
            if (abs($bestValue) >= self::WIN / 2) {
                break;
            }
            if (hrtime(true) > $this->softStop) {
                break;
            }
        }

        if ($bestValue <= -self::WIN / 2) {
            return $this->biggestFlood($moves);
        }

        return $bestMove;
    }

    private function minNode(int $me, int $op, int $m, int $depth, float $alpha, float $beta): float
    {
        $replies = $this->nb[$op];
        if (isset($this->killOp[$depth])) {
            $k = $op + $this->killOp[$depth];
            $i = array_search($k, $replies, true);
            if ($i !== false && $i !== 0) {
                unset($replies[$i]);
                array_unshift($replies, $k);
            }
        }

        $worst = INF;
        $any = false;
        foreach ($replies as $r) {
            if ($this->g[$r] !== 0) {
                continue;
            }
            $any = true;
            if ($r === $m) {
                $v = $this->draw;
            } else {
                $this->g[$m] = 1;
                $this->g[$r] = 1;
                $v = $this->maxNode($m, $r, $depth - 1, $alpha, min($beta, $worst));
                $this->g[$m] = 0;
                $this->g[$r] = 0;
                if ($this->abort) {
                    return 0.0;
                }
            }
            if ($v < $worst) {
                $worst = $v;
                $this->killOp[$depth] = $r - $op;
                if ($worst <= $alpha) {
                    return $worst;
                }
            }
        }
        if (!$any) {
            return self::WIN;
        }

        return $worst;
    }

    private function maxNode(int $me, int $op, int $depth, float $alpha, float $beta): float
    {
        if (hrtime(true) > $this->deadline) {
            $this->abort = true;

            return 0.0;
        }
        $mine = [];
        foreach ($this->nb[$me] as $c) {
            if ($this->g[$c] === 0) {
                $mine[] = $c;
            }
        }
        $theirs = false;
        foreach ($this->nb[$op] as $c) {
            if ($this->g[$c] === 0) {
                $theirs = true;
                break;
            }
        }
        if ($mine === []) {
            return $theirs ? -self::WIN + 1 : $this->draw;
        }
        if (!$theirs) {
            return self::WIN - 1;
        }
        if ($depth <= 0) {
            return $this->evaluate($me, $op);
        }
        if (isset($this->killMe[$depth])) {
            $k = $me + $this->killMe[$depth];
            $i = array_search($k, $mine, true);
            if ($i !== false && $i !== 0) {
                unset($mine[$i]);
                array_unshift($mine, $k);
            }
        }

        $best = -INF;
        foreach ($mine as $m) {
            $v = $this->minNode($me, $op, $m, $depth, max($alpha, $best), $beta);
            if ($this->abort) {
                return 0.0;
            }
            if ($v > $best) {
                $best = $v;
                $this->killMe[$depth] = $m - $me;
                if ($best >= $beta) {
                    return $best;
                }
            }
        }

        return $best;
    }

    /** Voronoi territory. own: 0 free, 1 blocked, 2 mine, 3 theirs, 4 pending mine, 5 tie. */
    private function evaluate(int $me, int $op): float
    {
        $own = $this->g;
        $nb = $this->nb;
        $fa = [$me];
        $fb = [$op];
        $ca = 0;
        $cb = 0;
        $contact = false;

        while ($fa !== [] || $fb !== []) {
            $na = [];
            foreach ($fa as $c) {
                foreach ($nb[$c] as $x) {
                    $v = $own[$x];
                    if ($v === 0) {
                        $own[$x] = 4;
                        $na[] = $x;
                    } elseif ($v === 3) {
                        $contact = true;
                    }
                }
            }
            $nfb = [];
            foreach ($fb as $c) {
                foreach ($nb[$c] as $x) {
                    $v = $own[$x];
                    if ($v === 0) {
                        $own[$x] = 3;
                        $nfb[] = $x;
                    } elseif ($v === 4) {
                        $own[$x] = 5;
                        $contact = true;
                    } elseif ($v === 2) {
                        $contact = true;
                    }
                }
            }
            $fa = [];
            foreach ($na as $x) {
                if ($own[$x] === 4) {
                    $own[$x] = 2;
                    $fa[] = $x;
                }
            }
            $fb = $nfb;
            $ca += \count($fa);
            $cb += \count($fb);
        }

        if (!$contact) {
            $diff = $ca - $cb;
            if ($diff === 0) {
                return 0.5 * $this->draw;
            }

            return $diff > 0 ? $diff + self::SEPARATION_BONUS : $diff - self::SEPARATION_BONUS;
        }

        return (float) ($ca - $cb);
    }

    // ---------------------------------------------------------------- endgame search

    /** @param list<int> $moves */
    private function endgame(array $moves): int
    {
        $fn = [];
        $fb = [];
        foreach ($moves as $m) {
            $this->g[$m] = 1;
            $fn[$m] = $this->freeNeighbours($m);
            $fb[$m] = $this->fillBound($m);
            $this->g[$m] = 0;
        }
        usort($moves, static fn (int $a, int $b): int => [$fb[$b], $fn[$a]] <=> [$fb[$a], $fn[$b]]);

        $order = $moves;
        $bestMove = $order[0];

        for ($depth = 1; $depth <= 400; ++$depth) {
            $iterationBest = 0;
            $iterMove = $order[0];
            foreach ($order as $m) {
                $this->g[$m] = 1;
                $v = 1 + $this->longest($m, $depth - 1, $iterationBest - 1);
                $this->g[$m] = 0;
                if ($this->abort) {
                    break 2;
                }
                if ($v > $iterationBest) {
                    $iterationBest = $v;
                    $iterMove = $m;
                }
            }
            $bestMove = $iterMove;
            $bestValue = $iterationBest;
            $i = array_search($bestMove, $order, true);
            if ($i !== false && $i !== 0) {
                unset($order[$i]);
                array_unshift($order, $bestMove);
            }
            if ($depth >= $bestValue || hrtime(true) > $this->deadline) {
                break;
            }
        }

        return $bestMove;
    }

    private function longest(int $c, int $depth, int $need): int
    {
        if ((++$this->calls & 7) === 0 && hrtime(true) > $this->deadline) {
            $this->abort = true;

            return 0;
        }
        $bound = $this->fillBound($c);
        if ($depth <= 0 || $bound <= 0 || $bound <= $need) {
            return $bound;
        }
        $kids = [];
        foreach ($this->nb[$c] as $x) {
            if ($this->g[$x] === 0) {
                $kids[] = $x;
            }
        }
        $kfn = [];
        foreach ($kids as $x) {
            $kfn[$x] = $this->freeNeighbours($x);
        }
        usort($kids, static fn (int $a, int $b): int => $kfn[$a] <=> $kfn[$b]);

        $best = 0;
        foreach ($kids as $x) {
            $this->g[$x] = 1;
            $v = 1 + $this->longest($x, $depth - 1, max($need, $best) - 1);
            $this->g[$x] = 0;
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
        $a = $p[1 - $col[$c]];
        $b = $p[$col[$c]];

        return $a > $b ? 2 * $b + 1 : 2 * $a;
    }

    /** @param list<int> $moves */
    private function biggestFlood(array $moves): int
    {
        $best = $moves[0];
        $bestN = -1;
        foreach ($moves as $m) {
            $this->g[$m] = 1;
            [$n] = $this->floodFrom($m, -1);
            $this->g[$m] = 0;
            if ($n > $bestN) {
                $bestN = $n;
                $best = $m;
            }
        }

        return $best;
    }

    // ---------------------------------------------------------------- fallback

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
                foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dy]) {
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
