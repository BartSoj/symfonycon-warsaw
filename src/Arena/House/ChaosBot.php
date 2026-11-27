<?php

namespace App\Arena\House;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/** Picks a random safe direction now and then. Unpredictable, rarely clever. */
final class ChaosBot implements Bot
{
    public function name(): string
    {
        return 'Chaos Monkey';
    }

    public function author(): string
    {
        return 'JetBrains';
    }

    public function color(): string
    {
        return '#e5484d';
    }

    public function move(Arena $arena): Direction
    {
        $safe = $arena->safeDirections();
        if ($arena->isSafe($arena->me->direction) && mt_rand(0, 3) > 0) {
            return $arena->me->direction;
        }

        return $safe ? $safe[array_rand($safe)] : $arena->me->direction;
    }
}
