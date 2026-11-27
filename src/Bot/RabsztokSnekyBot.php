<?php

namespace App\Bot;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/**
 * Sneky Snek: rush to a wall, turn around right next to its own trail and rush
 * to the other wall, building a dividing line that walls off the opponent.
 * Then hug its own body back and forth to paint its side with no gaps.
 */
final class RabsztokSnekyBot implements Bot
{
    private int $phase = 0;          // 0 = not started, 1 = first rush, 2 = second rush, 3 = fill
    private ?Direction $rush = null; // direction of the first rush
    private ?Direction $side = null; // direction towards the opponent
    private bool $turning = false;

    public function name(): string { return 'Sneky Snek'; }
    public function author(): string { return 'Rabsztok'; }
    public function color(): string { return '#7cfc00'; }

    public function move(Arena $arena): Direction
    {
        $me = $arena->me;
        $opp = $arena->opponents[0] ?? null;

        if ($this->phase === 0) {
            $this->plan($arena);
        }

        // Cells the opponent could step into next tick (head-on danger).
        $danger = [];
        if ($opp !== null) {
            foreach (Direction::cases() as $d) {
                $danger[($opp->x + $d->dx()) . ',' . ($opp->y + $d->dy())] = true;
            }
        }

        // Evaluate every safe direction.
        $options = [];
        $best = 0;
        foreach ($arena->safeDirections() as $d) {
            $nx = $me->x + $d->dx();
            $ny = $me->y + $d->dy();
            $space = $this->flood($arena, $nx, $ny, $danger);
            $risky = isset($danger["$nx,$ny"]);
            $options[$d->name] = ['dir' => $d, 'space' => $space, 'risky' => $risky, 'hug' => $this->hug($arena, $nx, $ny)];
            $best = max($best, $space);
        }

        if ($options === []) {
            return $me->direction;
        }

        // Follow the plan while it is safe enough.
        $planned = $this->planned($arena);
        if ($planned !== null && isset($options[$planned->name])) {
            $o = $options[$planned->name];
            if (!$o['risky'] && $o['space'] >= 0.8 * $best) {
                $this->advance($planned);
                return $planned;
            }
        }
        if ($this->phase < 3 && $planned === null) {
            $this->phase = 3;
        }

        // Fill mode: biggest space, avoid head-on, hug walls and own body.
        usort($options, fn ($a, $b) => [$a['risky'] ? 0 : 1, $a['space'], $a['hug']] <=> [$b['risky'] ? 0 : 1, $b['space'], $b['hug']]);
        $choice = end($options)['dir'];
        if ($this->phase < 3) {
            $this->phase = 3; // plan broken, just paint
        }

        return $choice;
    }

    private function plan(Arena $arena): void
    {
        $me = $arena->me;
        $opp = $arena->opponents[0] ?? null;
        $ox = $opp?->x ?? $arena->width - 1 - $me->x;
        $oy = $opp?->y ?? $arena->height - 1 - $me->y;

        if (abs($ox - $me->x) >= abs($oy - $me->y)) {
            // Opponent is left/right of us: build a vertical line.
            $this->rush = $me->y < $arena->height / 2 ? Direction::Up : Direction::Down;
            $this->side = $ox > $me->x ? Direction::Right : Direction::Left;
        } else {
            $this->rush = $me->x < $arena->width / 2 ? Direction::Left : Direction::Right;
            $this->side = $oy > $me->y ? Direction::Down : Direction::Up;
        }
        if ($this->rush === $me->direction->opposite()) {
            $this->rush = $this->rush->opposite();
        }
        $this->phase = 1;
    }

    private function planned(Arena $arena): ?Direction
    {
        if ($this->phase === 1) {
            if ($arena->isSafe($this->rush)) {
                return $this->rush;
            }
            return $this->side; // turn around next to the wall
        }
        if ($this->phase === 2) {
            $back = $this->rush->opposite();
            return $arena->isSafe($back) ? $back : null;
        }

        return null;
    }

    private function advance(Direction $taken): void
    {
        if ($this->phase === 1 && $taken === $this->side) {
            $this->phase = 2;
        }
    }

    /** Number of blocked neighbours: higher means tighter painting. */
    private function hug(Arena $arena, int $x, int $y): int
    {
        $n = 0;
        foreach (Direction::cases() as $d) {
            if (!$arena->isFree($x + $d->dx(), $y + $d->dy())) {
                $n++;
            }
        }
        return $n;
    }

    /** @param array<string,bool> $blocked */
    private function flood(Arena $arena, int $sx, int $sy, array $blocked): int
    {
        $seen = ["$sx,$sy" => true];
        $queue = [[$sx, $sy]];
        $count = 0;
        while ($queue !== []) {
            [$x, $y] = array_pop($queue);
            $count++;
            foreach (Direction::cases() as $d) {
                $nx = $x + $d->dx();
                $ny = $y + $d->dy();
                $k = "$nx,$ny";
                if (isset($seen[$k]) || isset($blocked[$k]) || !$arena->isFree($nx, $ny)) {
                    continue;
                }
                $seen[$k] = true;
                $queue[] = [$nx, $ny];
            }
        }
        return $count;
    }
}
