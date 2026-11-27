<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Boris Britva: a maze-solver. Re-plans every move, but commits to a strategy for at least 5 moves.
 *
 *  - TERRITORY: Voronoi partition (simultaneous BFS) – grab more cells than the opponent.
 *  - ATTACK:    A* towards a cell ahead of the opponent to cut it off.
 *  - SURVIVE:   once the arenas are separated, flood-fill + wall-hugging to fill our pocket.
 *
 * Every move, whatever the strategy, is guarded by a flood fill so we never ride into a dead end.
 */
final class BorisBritvaBot implements Bot
{
    private const LOCK = 5;
    private const BUDGET_NS = 2_300_000_000; // stay well under the 3 s match budget

    private string $mode = 'territory';
    private int $modeUntil = 0;
    private int $spentNs = 0;
    /** @var array<int, list<int>> */
    private array $nbr = [];
    private int $w = 0;

    public function name(): string { return 'Boris Britva'; }
    public function author(): string { return 'Ariadne'; }
    public function color(): string { return '#ffb000'; }

    public function move(Arena $arena): Direction
    {
        $start = hrtime(true);
        try {
            return $this->think($arena);
        } finally {
            $this->spentNs += hrtime(true) - $start;
        }
    }

    private function think(Arena $arena): Direction
    {
        $w = $arena->width;
        $h = $arena->height;
        $this->buildNeighbours($w, $h);

        $free = [];
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                if ($arena->isFree($x, $y)) {
                    $free[$y * $w + $x] = true;
                }
            }
        }

        $me = $arena->me;
        $opp = $arena->opponents[0] ?? null;
        $oppIdx = $opp ? $opp->y * $w + $opp->x : -1;

        // Cells the opponent might step into this tick (head-on danger).
        $oppNext = [];
        if ($opp) {
            foreach ($this->nbr[$oppIdx] as $n) {
                if (isset($free[$n])) {
                    $oppNext[$n] = true;
                }
            }
        }

        // Candidate moves.
        $cands = [];
        foreach ([$me->direction, $me->direction->left(), $me->direction->right()] as $d) {
            $x = $me->x + $d->dx();
            $y = $me->y + $d->dy();
            if ($x >= 0 && $y >= 0 && $x < $w && $y < $h && isset($free[$y * $w + $x])) {
                $cands[] = [$d, $y * $w + $x];
            }
        }
        if (!$cands) {
            return $me->direction;
        }
        if (count($cands) === 1) {
            return $cands[0][0];
        }

        $lowTime = $this->spentNs > self::BUDGET_NS;

        // Is the opponent still reachable from us? (Separation is permanent.)
        $myIdx = $me->y * $w + $me->x;
        $connected = $opp && !$lowTime && $this->connected($free, $myIdx, $oppIdx);

        // Strategy (re)selection: only every LOCK moves, except separation forces SURVIVE.
        if (!$connected) {
            $this->mode = 'survive';
        } elseif ($arena->tick >= $this->modeUntil) {
            [$mine, $theirs] = $this->voronoi($free, [$myIdx], [$oppIdx]);
            $this->mode = $mine < $theirs ? 'attack' : 'territory';
            $this->modeUntil = $arena->tick + self::LOCK;
        }

        $target = ($this->mode === 'attack' && $opp) ? $this->interceptTarget($free, $opp->x, $opp->y, $opp->direction, $w, $h) : null;

        // Evaluate every candidate.
        $evals = [];
        $bestSpace = 0;
        foreach ($cands as [$d, $c]) {
            $g = $free;
            unset($g[$c]);
            $safeG = $g;
            foreach ($oppNext as $n => $_) {
                unset($safeG[$n]);
            }
            $space = $this->flood($safeG, $c);
            $bestSpace = max($bestSpace, $space);

            $hug = 0;
            foreach ($this->nbr[$c] as $n) {
                if (!isset($g[$n])) {
                    ++$hug;
                }
            }
            $hug += 4 - count($this->nbr[$c]); // edges count as walls

            $e = ['d' => $d, 'c' => $c, 'space' => $space, 'hug' => $hug, 'headOn' => isset($oppNext[$c]), 'vor' => 0, 'dist' => 0];

            if ($this->mode !== 'survive' && !$lowTime) {
                $oppSeeds = [];
                foreach ($oppNext as $n => $_) {
                    if ($n !== $c) {
                        $oppSeeds[] = $n;
                    }
                }
                foreach ($oppSeeds as $n) {
                    unset($g[$n]);
                }
                [$mine, $theirs] = $this->voronoi($g, [$c], $oppSeeds);
                $e['vor'] = $mine - $theirs;
                if ($target !== null) {
                    $e['dist'] = $this->aStar($free, $c, $target, $w);
                }
            }
            $evals[] = $e;
        }

        // Guard: never pick a clearly smaller pocket, never go head-on if avoidable.
        $ok = array_filter($evals, fn ($e) => !$e['headOn'] && $e['space'] >= max(1, $bestSpace * 0.8));
        if (!$ok) {
            $ok = array_filter($evals, fn ($e) => !$e['headOn'] && $e['space'] > 0);
        }
        if (!$ok) {
            $ok = $evals;
            usort($ok, fn ($a, $b) => $b['space'] <=> $a['space']);

            return $ok[0]['d'];
        }

        $mode = $lowTime ? 'survive' : $this->mode;
        usort($ok, function ($a, $b) use ($mode) {
            return match ($mode) {
                'survive' => [$b['space'], $b['hug']] <=> [$a['space'], $a['hug']],
                'attack' => [$a['dist'] - $a['vor'] * 0.05, -$a['space']] <=> [$b['dist'] - $b['vor'] * 0.05, -$b['space']],
                default => [$b['vor'], $b['space'], $b['hug']] <=> [$a['vor'], $a['space'], $a['hug']],
            };
        });

        return $ok[0]['d'];
    }

    private function buildNeighbours(int $w, int $h): void
    {
        if ($this->nbr && $this->w === $w && count($this->nbr) === $w * $h) {
            return;
        }
        $this->w = $w;
        $this->nbr = [];
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $l = [];
                if ($y > 0) { $l[] = ($y - 1) * $w + $x; }
                if ($y < $h - 1) { $l[] = ($y + 1) * $w + $x; }
                if ($x > 0) { $l[] = $y * $w + $x - 1; }
                if ($x < $w - 1) { $l[] = $y * $w + $x + 1; }
                $this->nbr[$y * $w + $x] = $l;
            }
        }
    }

    /** Flood fill: how many free cells can we reach from $start (start itself excluded). */
    private function flood(array $free, int $start): int
    {
        $seen = [$start => true];
        $queue = [$start];
        $count = 0;
        for ($i = 0; $i < count($queue); ++$i) {
            foreach ($this->nbr[$queue[$i]] as $n) {
                if (isset($free[$n]) && !isset($seen[$n])) {
                    $seen[$n] = true;
                    $queue[] = $n;
                    ++$count;
                }
            }
        }

        return $count;
    }

    /** Can our head reach a cell next to the opponent's head? */
    private function connected(array $free, int $from, int $to): bool
    {
        $goal = array_flip($this->nbr[$to]);
        $seen = [$from => true];
        $queue = [$from];
        for ($i = 0; $i < count($queue); ++$i) {
            foreach ($this->nbr[$queue[$i]] as $n) {
                if (isset($goal[$n])) {
                    return true;
                }
                if (isset($free[$n]) && !isset($seen[$n])) {
                    $seen[$n] = true;
                    $queue[] = $n;
                }
            }
        }

        return false;
    }

    /**
     * Voronoi partition by simultaneous BFS: cells we reach strictly first are ours.
     *
     * @return array{int, int}
     */
    private function voronoi(array $free, array $mySeeds, array $oppSeeds): array
    {
        $owner = [];
        $front = [];
        foreach ($mySeeds as $s) { $owner[$s] = 1; $front[] = $s; }
        foreach ($oppSeeds as $s) { $owner[$s] = isset($owner[$s]) ? 0 : 2; $front[] = $s; }
        $mine = 0;
        $theirs = 0;
        while ($front) {
            $next = [];
            $claim = [];
            foreach ($front as $c) {
                $o = $owner[$c];
                if ($o === 0) {
                    continue;
                }
                foreach ($this->nbr[$c] as $n) {
                    if (isset($free[$n]) && !isset($owner[$n])) {
                        if (!isset($claim[$n])) {
                            $claim[$n] = $o;
                            $next[] = $n;
                        } elseif ($claim[$n] !== $o) {
                            $claim[$n] = 0;
                        }
                    }
                }
            }
            foreach ($claim as $n => $o) {
                $owner[$n] = $o;
                if ($o === 1) { ++$mine; } elseif ($o === 2) { ++$theirs; }
            }
            $front = $next;
        }

        return [$mine, $theirs];
    }

    /** The furthest free cell up to 4 steps ahead of the opponent: where we want to cut it off. */
    private function interceptTarget(array $free, int $x, int $y, Direction $d, int $w, int $h): ?int
    {
        $target = null;
        for ($k = 1; $k <= 4; ++$k) {
            $x += $d->dx();
            $y += $d->dy();
            if ($x < 0 || $y < 0 || $x >= $w || $y >= $h || !isset($free[$y * $w + $x])) {
                break;
            }
            $target = $y * $w + $x;
        }

        return $target;
    }

    /** A* with Manhattan heuristic; returns path length (or a big number if unreachable). */
    private function aStar(array $free, int $from, int $to, int $w): int
    {
        if ($from === $to) {
            return 0;
        }
        $tx = $to % $w;
        $ty = intdiv($to, $w);
        $gScore = [$from => 0];
        $open = new \SplPriorityQueue();
        $open->insert($from, -(abs($from % $w - $tx) + abs(intdiv($from, $w) - $ty)));
        $closed = [];
        $expanded = 0;
        while (!$open->isEmpty() && $expanded < 600) {
            $c = $open->extract();
            if (isset($closed[$c])) {
                continue;
            }
            $closed[$c] = true;
            ++$expanded;
            $g = $gScore[$c] + 1;
            foreach ($this->nbr[$c] as $n) {
                if ($n === $to) {
                    return $g;
                }
                if (isset($free[$n]) && !isset($closed[$n]) && (!isset($gScore[$n]) || $g < $gScore[$n])) {
                    $gScore[$n] = $g;
                    $open->insert($n, -($g + abs($n % $w - $tx) + abs(intdiv($n, $w) - $ty)));
                }
            }
        }

        return 10_000;
    }
}
