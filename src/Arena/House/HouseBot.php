<?php

namespace App\Arena\House;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/** The hoodie gatekeeper: goes where the longest straight line of free cells is. Beat it in 2 of 3 matches. */
final class HouseBot implements Bot
{
    public function name(): string
    {
        return 'The House';
    }

    public function author(): string
    {
        return 'JetBrains';
    }

    public function color(): string
    {
        return '#f0b429';
    }

    public function move(Arena $arena): Direction
    {
        $me = $arena->me;
        $best = $me->direction;
        $bestRun = -1;
        foreach ([$me->direction, $me->direction->left(), $me->direction->right()] as $d) {
            $run = 0;
            while ($arena->isFree($me->x + $d->dx() * ($run + 1), $me->y + $d->dy() * ($run + 1))) {
                ++$run;
            }
            if ($run > $bestRun) {
                [$best, $bestRun] = [$d, $run];
            }
        }

        return $best;
    }
}
