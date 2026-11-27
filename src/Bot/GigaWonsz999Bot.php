<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * GigaWonsz999: space-control bot.
 * - While sharing space with an opponent: iterative-deepening alpha-beta over simultaneous moves,
 *   evaluated with a Voronoi territory difference (cells I reach first minus cells they reach first).
 * - Once walled off: survival mode, deepening search maximizing a parity-aware fill estimate, hugging walls.
 * - Free-for-all: plays minimax against the nearest opponent, counts every opponent in the Voronoi,
 *   and avoids cells other heads could jump into.
 */
final class GigaWonsz999Bot implements Bot
{
    private const WIN = 1000000;

    private int $w = 0;
    private int $h = 0;
    private int $n = 0;
    /** @var list<list<int>> */
    private array $adj = [];
    /** @var list<array<int, int>> cell => [dirIndex => target cell or -1] */
    private array $moveTo = [];
    /** @var list<int> */
    private array $col = [];
    /** @var list<int> */
    private array $blank = [];
    /** @var list<bool> */
    private array $g = [];
    /** @var list<Direction> */
    private array $dirs = [];

    private float $used = 0.0;
    private int $deadline = 0;
    private bool $abort = false;
    private bool $checkTime = false;

    public function name(): string { return 'GigaWonsz999'; }
    public function author(): string { return 'GigaWonsz'; }
    public function color(): string { return '#39ff14'; }

    public function move(Arena $arena): Direction
    {
        $t0 = hrtime(true);
        try {
            $d = $this->think($arena, $t0);
        } catch (\Throwable) {
            $safe = $arena->safeDirections();
            $d = $safe[0] ?? $arena->me->direction;
        }
        $this->used += (hrtime(true) - $t0) / 1e9;

        return $d;
    }

    private function init(Arena $arena): void
    {
        $this->w = $w = $arena->width;
        $this->h = $h = $arena->height;
        $this->n = $w * $h;
        $this->dirs = Direction::cases();
        $this->adj = $this->moveTo = $this->col = [];
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $a = [];
                $m = [];
                foreach ($this->dirs as $k => $d) {
                    $nx = $x + $d->dx();
                    $ny = $y + $d->dy();
                    if ($nx >= 0 && $ny >= 0 && $nx < $w && $ny < $h) {
                        $a[] = $m[$k] = $ny * $w + $nx;
                    } else {
                        $m[$k] = -1;
                    }
                }
                $this->adj[] = $a;
                $this->moveTo[] = $m;
                $this->col[] = ($x + $y) & 1;
            }
        }
        $this->blank = array_fill(0, $this->n, 0);
    }

    private function think(Arena $arena, int $t0): Direction
    {
        if ($this->w !== $arena->width || $this->h !== $arena->height) {
            $this->init($arena);
        }
        $w = $this->w;
        $g = [];
        for ($y = 0; $y < $this->h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $g[] = !$arena->isFree($x, $y);
            }
        }
        $me = $arena->me->y * $w + $arena->me->x;
        $g[$me] = true;
        $enemies = [];
        foreach ($arena->opponents as $o) {
            if ($o->x >= 0 && $o->y >= 0 && $o->x < $w && $o->y < $this->h) {
                $e = $o->y * $w + $o->x;
                $g[$e] = true;
                $enemies[] = $e;
            }
        }
        $this->g = $g;

        $root = [];
        foreach ($this->moveTo[$me] as $k => $c) {
            if ($c >= 0 && !$g[$c]) {
                $root[] = $k;
            }
        }
        if (!$root) {
            return $arena->me->direction;
        }
        if (\count($root) === 1) {
            return $this->dirs[$root[0]];
        }

        // BFS from me: reachable area and distances.
        $dist = [$me => 0];
        $q = [$me];
        for ($i = 0; isset($q[$i]); ++$i) {
            $c = $q[$i];
            foreach ($this->adj[$c] as $x) {
                if (!$g[$x] && !isset($dist[$x])) {
                    $dist[$x] = $dist[$c] + 1;
                    $q[] = $x;
                }
            }
        }
        $reach = \count($q) - 1;

        $target = -1;
        $best = PHP_INT_MAX;
        foreach ($enemies as $e) {
            foreach ($this->adj[$e] as $x) {
                if (!$g[$x] && isset($dist[$x]) && $dist[$x] < $best) {
                    $best = $dist[$x];
                    $target = $e;
                }
            }
        }

        $left = 2.6 - $this->used;
        if ($target < 0) {
            $remaining = max(10, $reach);
        } else {
            $remaining = max(20, intdiv($reach, 2));
        }
        $budget = min(0.035, max(0.0008, $left / $remaining));
        if ($left < 0.05) {
            $budget = 0.0005;
        }
        $this->deadline = $t0 + (int) ($budget * 1e9);
        $this->abort = false;

        if ($target < 0) {
            return $this->dirs[$this->survival($me, $root)];
        }

        $others = [];
        foreach ($enemies as $e) {
            if ($e !== $target) {
                $others[] = $e;
            }
        }

        return $this->dirs[$this->battle($me, $target, $others, $root, $t0, $budget)];
    }

    /** @param list<int> $others @param list<int> $root */
    private function battle(int $me, int $opp, array $others, array $root, int $t0, float $budget): int
    {
        // Cells other (non-target) heads could jump into next tick.
        $danger = [];
        foreach ($others as $o) {
            foreach ($this->adj[$o] as $x) {
                $danger[$x] = true;
            }
        }

        $best = $root[0];
        $order = $root;
        for ($depth = 1; $depth <= 30; ++$depth) {
            $this->checkTime = $depth > 1;
            $alpha = -INF;
            $iterBest = null;
            $scores = [];
            foreach ($order as $k) {
                $c = $this->moveTo[$me][$k];
                $pen = isset($danger[$c]) ? 5000 : 0;
                $v = $this->minNode($c, $opp, $others, $depth, $alpha + $pen, INF) - $pen;
                if ($this->abort) {
                    break 2;
                }
                $scores[$k] = $v;
                if ($v > $alpha) {
                    $alpha = $v;
                    $iterBest = $k;
                }
            }
            if ($iterBest !== null) {
                $best = $iterBest;
            }
            arsort($scores);
            $order = array_keys($scores);
            if (abs($alpha) >= self::WIN / 2) {
                break;
            }
            if ((hrtime(true) - $t0) / 1e9 > $budget / 3) {
                break;
            }
        }

        return $best;
    }

    /** @param list<int> $others */
    private function minNode(int $my, int $opp, array $others, int $depth, float $alpha, float $beta): float
    {
        $v = INF;
        $any = false;
        foreach ($this->moveTo[$opp] as $o) {
            if ($o < 0 || $this->g[$o]) {
                continue;
            }
            $any = true;
            if ($o === $my) {
                $s = 0;
            } else {
                $this->g[$my] = true;
                $this->g[$o] = true;
                $s = $depth <= 1
                    ? $this->voronoi($my, $o, $others)
                    : $this->maxNode($my, $o, $others, $depth - 1, $alpha, $beta);
                $this->g[$my] = false;
                $this->g[$o] = false;
                if ($this->abort) {
                    return 0;
                }
            }
            if ($s < $v) {
                $v = $s;
            }
            if ($v <= $alpha) {
                return $v;
            }
            if ($v < $beta) {
                $beta = $v;
            }
        }

        return $any ? $v : self::WIN + $depth;
    }

    /** @param list<int> $others */
    private function maxNode(int $me, int $opp, array $others, int $depth, float $alpha, float $beta): float
    {
        if ($this->checkTime && hrtime(true) > $this->deadline) {
            $this->abort = true;

            return 0;
        }
        $v = -INF;
        $any = false;
        foreach ($this->moveTo[$me] as $c) {
            if ($c < 0 || $this->g[$c]) {
                continue;
            }
            $any = true;
            $s = $this->minNode($c, $opp, $others, $depth, $alpha, $beta);
            if ($this->abort) {
                return 0;
            }
            if ($s > $v) {
                $v = $s;
            }
            if ($v >= $beta) {
                return $v;
            }
            if ($v > $alpha) {
                $alpha = $v;
            }
        }
        if (!$any) {
            foreach ($this->moveTo[$opp] as $o) {
                if ($o >= 0 && !$this->g[$o]) {
                    return -self::WIN - $depth;
                }
            }

            return 0;
        }

        return $v;
    }

    /** Territory difference: cells I reach strictly first minus cells any enemy reaches first. @param list<int> $others */
    private function voronoi(int $me, int $opp, array $others): int
    {
        $g = $this->g;
        $adj = $this->adj;
        $own = $this->blank;
        $own[$me] = 1;
        $fb = $others;
        $fb[] = $opp;
        foreach ($fb as $e) {
            $own[$e] = 2;
        }
        $fa = [$me];
        $score = 0;
        while ($fa || $fb) {
            $na = [];
            foreach ($fa as $c) {
                if ($own[$c] !== 1) {
                    continue;
                }
                foreach ($adj[$c] as $x) {
                    if (!$g[$x] && $own[$x] === 0) {
                        $own[$x] = 4;
                        $na[] = $x;
                    }
                }
            }
            $nb = [];
            foreach ($fb as $c) {
                foreach ($adj[$c] as $x) {
                    if ($g[$x]) {
                        continue;
                    }
                    $o = $own[$x];
                    if ($o === 0) {
                        $own[$x] = 2;
                        $nb[] = $x;
                    } elseif ($o === 4) {
                        $own[$x] = 3;
                    }
                }
            }
            foreach ($na as $x) {
                if ($own[$x] === 4) {
                    $own[$x] = 1;
                    ++$score;
                }
            }
            $score -= \count($nb);
            $fa = $na;
            $fb = $nb;
        }

        return $score;
    }

    /** @param list<int> $root */
    private function survival(int $me, array $root): int
    {
        $best = $root[0];
        $hug = [];
        foreach ($root as $k) {
            $c = $this->moveTo[$me][$k];
            $free = 0;
            foreach ($this->adj[$c] as $x) {
                if (!$this->g[$x]) {
                    ++$free;
                }
            }
            $hug[$k] = $free;
        }
        $maxArea = 0;
        for ($depth = 0; $depth <= 40; ++$depth) {
            $this->checkTime = $depth > 0;
            $bestScore = -INF;
            $iterBest = null;
            foreach ($root as $k) {
                $c = $this->moveTo[$me][$k];
                $this->g[$c] = true;
                $v = $this->fill($c, $depth);
                $this->g[$c] = false;
                if ($this->abort) {
                    break 2;
                }
                if ($v > $maxArea) {
                    $maxArea = $v;
                }
                $s = $v * 8 - $hug[$k];
                if ($s > $bestScore) {
                    $bestScore = $s;
                    $iterBest = $k;
                }
            }
            $best = $iterBest ?? $best;
            if ($depth >= $maxArea) {
                break;
            }
        }

        return $best;
    }

    private function fill(int $cell, int $depth): int
    {
        if ($depth === 0) {
            return $this->area($cell);
        }
        if ($this->checkTime && hrtime(true) > $this->deadline) {
            $this->abort = true;

            return 0;
        }
        $best = 0;
        foreach ($this->adj[$cell] as $x) {
            if ($this->g[$x]) {
                continue;
            }
            $this->g[$x] = true;
            $v = 1 + $this->fill($x, $depth - 1);
            $this->g[$x] = false;
            if ($this->abort) {
                return 0;
            }
            if ($v > $best) {
                $best = $v;
            }
        }

        return $best;
    }

    /** Parity-aware upper bound on the path length available from this (occupied) cell. */
    private function area(int $cell): int
    {
        $g = $this->g;
        $adj = $this->adj;
        $col = $this->col;
        $seen = [$cell => true];
        $q = [$cell];
        $same = 0;
        $other = 0;
        $c0 = $col[$cell];
        for ($i = 0; isset($q[$i]); ++$i) {
            foreach ($adj[$q[$i]] as $x) {
                if (!$g[$x] && !isset($seen[$x])) {
                    $seen[$x] = true;
                    $q[] = $x;
                    if ($col[$x] === $c0) {
                        ++$same;
                    } else {
                        ++$other;
                    }
                }
            }
        }

        return $other > $same ? 2 * $same + 1 : 2 * $other;
    }
}
