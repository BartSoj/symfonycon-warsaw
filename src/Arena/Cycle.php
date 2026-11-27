<?php

namespace App\Arena;

/** A light cycle: where its head is and where it's heading. */
final class Cycle
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly Direction $direction,
    ) {
    }
}
