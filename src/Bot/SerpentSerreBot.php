<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Serpent Serré: hugs walls and trails with tight turns so it fills the board without leaving holes.
 * - While it can still reach the opponent: claim territory (cells it reaches before the opponent).
 * - Once cut off: maximise the space it can really use, then pick the move that touches the most walls.
 * A time-bounded look-ahead (iterative deepening) stops it from entering a fork whose two pockets
 * a plain flood fill would count together, which is how it used to trap itself.
 */
final class SerpentSerreBot implements Bot
{
    public function name(): string { return 'Serpent Serré'; }
    public function author(): string { return 'Visiteur'; }
    public function color(): string { return '#9b30ff'; }

    private const DIRS = [Direction::Up, Direction::Down, Direction::Left, Direction::Right];

    /** @var array<int, bool> padded grid: index = (y + 1) * W + (x + 1) */
    private array $free = [];
    /** @var array<int, int> */
    private array $seen = [];
    private int $stamp = 0;
    private int $W = 0;
    /** @var list<int> */
    private array $offsets = [];
    private int $deadline = 0;
    private bool $aborted = false;
    private int $nodes = 0;
    private float $usedMs = 0.0;

    public function move(Arena $arena): Direction
    {
        $start = hrtime(true);
        $me = $arena->me;
        $safe = $arena->safeDirections();
        if ($safe === []) {
            return $me->direction;
        }

        $w = $arena->width;
        $h = $arena->height;
        $this->W = $W = $w + 2;
        $this->offsets = [-$W, $W, -1, 1];
        $this->free = array_fill(0, $W * ($h + 2), false);
        $this->seen = array_fill(0, $W * ($h + 2), 0);
        $this->stamp = 0;
        $freeCount = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($arena->isFree($x, $y)) {
                    $this->free[($y + 1) * $W + $x + 1] = true;
                    $freeCount++;
                }
            }
        }

        // Time budget: 3 s per match in total, so spread what is left over the moves still to play.
        $remainingMs = 2500.0 - $this->usedMs;
        $movesLeft = max(30, intdiv($freeCount, 2));
        $perMoveMs = max(0.5, min(20.0, $remainingMs / $movesLeft));
        $this->deadline = $start + (int) ($perMoveMs * 1e6);

        $op = $arena->opponents[0] ?? null;
        $opIdx = $op !== null ? ($op->y + 1) * $W + $op->x + 1 : -1;
        $opNext = [];
        if ($op !== null) {
            foreach ($this->offsets as $o) {
                if ($this->free[$opIdx + $o]) {
                    $opNext[$opIdx + $o] = true;
                }
            }
        }

        $cands = [];
        foreach ($safe as $d) {
            $idx = ($me->y + $d->dy() + 1) * $W + $me->x + $d->dx() + 1;
            $this->free[$idx] = false;
            [$mine, $theirs, $met] = $op !== null ? $this->voronoi($idx, $opIdx) : [0, 0, false];
            $tight = 0;
            foreach ($this->offsets as $o) {
                if (!$this->free[$idx + $o]) {
                    $tight++;
                }
            }
            $this->free[$idx] = true;
            $cands[] = ['d' => $d, 'idx' => $idx, 'mine' => $mine, 'theirs' => $theirs, 'met' => $met, 'tight' => $tight, 'look' => $this->look($idx, 0)];
        }

        // Iterative deepening: keep the deepest look-ahead that finished in time.
        for ($depth = 1; $depth <= 40; $depth++) {
            $this->aborted = false;
            $results = [];
            foreach ($cands as $i => $c) {
                $results[$i] = $this->look($c['idx'], $depth);
                if ($this->aborted) {
                    break;
                }
            }
            if ($this->aborted) {
                break;
            }
            foreach ($results as $i => $r) {
                $cands[$i]['look'] = $r;
            }
            if (hrtime(true) > $this->deadline) {
                break;
            }
        }

        $bestLook = max(array_column($cands, 'look'));
        $best = $cands[0]['d'];
        $bestScore = -PHP_INT_MAX;
        foreach ($cands as $c) {
            if ($c['met']) {
                $score = ($c['mine'] - $c['theirs']) * 100 + $c['look'] * 10;
                // Never trade real space for territory: that is how it walled itself in.
                if ($c['look'] < $bestLook * 0.7) {
                    $score -= 10_000_000;
                }
            } else {
                $score = $c['look'] * 1000;
            }
            $score += $c['tight'] * 3;
            if ($c['d'] === $me->direction) {
                $score += 1;
            }
            if (isset($opNext[$c['idx']])) {
                $score -= 300_000;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $c['d'];
            }
        }

        $this->usedMs += (hrtime(true) - $start) / 1e6;

        return $best;
    }

    /** Best number of cells still usable after stepping on $idx, searching $depth steps ahead. */
    private function look(int $idx, int $depth): int
    {
        $this->free[$idx] = false;
        if ($depth === 0) {
            $r = $this->flood($idx);
            if ((++$this->nodes & 7) === 0 && hrtime(true) > $this->deadline) {
                $this->aborted = true;
            }
        } else {
            $r = 0;
            foreach ($this->offsets as $o) {
                $n = $idx + $o;
                if ($this->free[$n]) {
                    $v = 1 + $this->look($n, $depth - 1);
                    if ($v > $r) {
                        $r = $v;
                    }
                    if ($this->aborted) {
                        break;
                    }
                }
            }
        }
        $this->free[$idx] = true;

        return $r;
    }

    /**
     * Usable space after stepping on $idx (which is already blocked).
     * Closing-a-surface detection: each free neighbour is flooded separately. If the step splits
     * the free space into several zones, only the biggest one counts, because the snake can
     * only go into one of them. The others are lost.
     */
    private function flood(int $idx): int
    {
        $s = ++$this->stamp;
        $this->seen[$idx] = $s;
        $free = $this->free;
        $best = 0;
        foreach ($this->offsets as $start) {
            $first = $idx + $start;
            if (!$free[$first] || $this->seen[$first] === $s) {
                continue; // wall, or same zone as a neighbour already flooded
            }
            $this->seen[$first] = $s;
            $stack = [$first];
            $count = 1;
            while ($stack !== []) {
                $c = array_pop($stack);
                foreach ($this->offsets as $o) {
                    $n = $c + $o;
                    if ($free[$n] && $this->seen[$n] !== $s) {
                        $this->seen[$n] = $s;
                        $count++;
                        $stack[] = $n;
                    }
                }
            }
            if ($count > $best) {
                $best = $count;
            }
        }

        return $best;
    }

    /** @return array{int, int, bool} cells I reach first, cells the opponent reaches first, whether our regions touch */
    private function voronoi(int $meIdx, int $opIdx): array
    {
        $owner = [$opIdx => 2, $meIdx => 1];
        $dist = [$opIdx => 0, $meIdx => 1];
        $met = false;
        foreach ($this->offsets as $o) {
            if ($meIdx + $o === $opIdx) {
                $met = true;
            }
        }
        $front = [$opIdx];
        $later = [$meIdx];
        $depth = 0;
        $mine = 0;
        $theirs = 0;
        while ($front !== [] || $later !== []) {
            $new = [];
            foreach ($front as $c) {
                $who = $owner[$c];
                if ($who === 0) {
                    continue;
                }
                foreach ($this->offsets as $o) {
                    $n = $c + $o;
                    if (!$this->free[$n]) {
                        continue;
                    }
                    if (!isset($dist[$n])) {
                        $dist[$n] = $depth + 1;
                        $owner[$n] = $who;
                        $new[] = $n;
                    } elseif ($owner[$n] !== $who) {
                        $met = true;
                        if ($dist[$n] === $depth + 1) {
                            $owner[$n] = 0;
                        }
                    }
                }
            }
            $front = array_merge($new, $later);
            $later = [];
            $depth++;
        }
        unset($owner[$opIdx], $owner[$meIdx]);
        foreach ($owner as $who) {
            if ($who === 1) {
                $mine++;
            } elseif ($who === 2) {
                $theirs++;
            }
        }

        return [$mine, $theirs, $met];
    }
}
