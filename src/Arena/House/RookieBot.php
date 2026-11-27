<?php

namespace App\Arena\House;

use App\Arena\Arena;
use App\Arena\Bot;
use App\Arena\Direction;

/** Goes straight and only turns when it's about to crash. Easy to beat. */
final class RookieBot implements Bot
{
    public function name(): string
    {
        return 'Rookie';
    }

    public function author(): string
    {
        return 'JetBrains';
    }

    public function color(): string
    {
        return '#8b95a7';
    }

    public function move(Arena $arena): Direction
    {
        $d = $arena->me->direction;
        foreach ([$d, $d->left(), $d->right()] as $option) {
            if ($arena->isSafe($option)) {
                return $option;
            }
        }

        return $d;
    }
}
