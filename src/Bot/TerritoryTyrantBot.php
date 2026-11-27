<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Voronoi territory grabber: always steer towards the bigger area, cut the
 * opponent off so they have to coil into themselves. Once separated, fill
 * our own space as tightly as possible.
 */
final class TerritoryTyrantBot implements Bot
{
    private float $spent = 0.0;

    public function name(): string { return 'Territory Tyrant'; }
    public function author(): string { return 'Booth Hero'; }
    public function color(): string { return '#ff6a00'; }

    public function move(Arena $arena): Direction
    {
        $start = microtime(true);
        try {
            return $this->decide($arena);
        } finally {
            $this->spent += microtime(true) - $start;
        }
    }

    private function decide(Arena $arena): Direction
    {
        $w = $arena->width;
        $h = $arena->height;
        $free = [];
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                if ($arena->isFree($x, $y)) {
                    $free[$y * $w + $x] = true;
                }
            }
        }

        $me = $arena->me;
        $mine = $this->moves($me->x, $me->y, $w, $h, $free);
        if ($mine === []) {
            return $me->direction;
        }
        if (\count($mine) === 1) {
            return $mine[0][0];
        }

        $opp = $arena->opponents[0] ?? null;
        $oppMoves = $opp ? $this->moves($opp->x, $opp->y, $w, $h, $free) : [];

        // Are we still sharing space with the opponent?
        $connected = false;
        if ($opp && $oppMoves !== []) {
            $reach = $this->flood($me->y * $w + $me->x, $w, $h, $free, true);
            foreach ($oppMoves as [, $c]) {
                if (isset($reach[$c])) {
                    $connected = true;
                    break;
                }
            }
        }

        $cheap = $this->spent > 2.2;
        $best = null;
        $bestScore = -INF;

        foreach ($mine as [$dir, $cell]) {
            if ($connected && !$cheap) {
                $worst = INF;
                foreach ($oppMoves as [, $oc]) {
                    if ($oc === $cell) {
                        $s = -500; // head-on = draw, avoid unless desperate
                    } else {
                        $s = $this->voronoi($cell, $oc, $w, $h, $free);
                    }
                    $worst = min($worst, $s);
                }
                $score = $worst;
                // Prefer staying straight a touch to avoid wasting space.
                if ($dir === $me->direction) {
                    $score += 0.3;
                }
            } else {
                $f = $free;
                unset($f[$cell]);
                $size = \count($this->flood($cell, $w, $h, $f, false));
                // Wall hugging: fewer free neighbours = tighter fill.
                $n = \count($this->moves($cell % $w, intdiv($cell, $w), $w, $h, $f));
                $score = $size * 10 - $n;
                foreach ($oppMoves as [, $oc]) {
                    if ($oc === $cell) {
                        $score -= 5000;
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $dir;
            }
        }

        return $best ?? $me->direction;
    }

    /** @return list<array{Direction, int}> */
    private function moves(int $x, int $y, int $w, int $h, array $free): array
    {
        $out = [];
        foreach (Direction::cases() as $d) {
            $nx = $x + $d->dx();
            $ny = $y + $d->dy();
            if ($nx >= 0 && $ny >= 0 && $nx < $w && $ny < $h && isset($free[$ny * $w + $nx])) {
                $out[] = [$d, $ny * $w + $nx];
            }
        }

        return $out;
    }

    /** Cells reachable from $start (start itself excluded unless free). */
    private function flood(int $start, int $w, int $h, array $free, bool $fromHead): array
    {
        $seen = [];
        $queue = [$start];
        $i = 0;
        $total = $w * $h;
        while ($i < \count($queue)) {
            $c = $queue[$i++];
            $x = $c % $w;
            foreach ([$c - $w, $c + $w, $x > 0 ? $c - 1 : -1, $x < $w - 1 ? $c + 1 : -1] as $n) {
                if ($n >= 0 && $n < $total && isset($free[$n]) && !isset($seen[$n])) {
                    $seen[$n] = true;
                    $queue[] = $n;
                }
            }
        }

        return $seen;
    }

    /** My Voronoi cells minus theirs, after both heads move. */
    private function voronoi(int $a, int $b, int $w, int $h, array $free): int
    {
        unset($free[$a], $free[$b]);
        $total = $w * $h;
        $owner = [$a => 1, $b => 2];
        $dist = [$a => 0, $b => 0];
        $queue = [$a, $b];
        $i = 0;
        $score = 0;
        while ($i < \count($queue)) {
            $c = $queue[$i++];
            $o = $owner[$c];
            if ($o === 3) {
                continue;
            }
            $d = $dist[$c] + 1;
            $x = $c % $w;
            foreach ([$c - $w, $c + $w, $x > 0 ? $c - 1 : -1, $x < $w - 1 ? $c + 1 : -1] as $n) {
                if ($n < 0 || $n >= $total || !isset($free[$n])) {
                    continue;
                }
                if (!isset($owner[$n])) {
                    $owner[$n] = $o;
                    $dist[$n] = $d;
                    $queue[] = $n;
                    $score += $o === 1 ? 1 : -1;
                } elseif ($dist[$n] === $d && $owner[$n] !== $o && $owner[$n] !== 3) {
                    // Contested: equally close to both.
                    $score += $owner[$n] === 1 ? -1 : 1;
                    $owner[$n] = 3;
                }
            }
        }

        return $score;
    }
}
