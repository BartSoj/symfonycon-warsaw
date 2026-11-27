<?php

namespace App\Arena;

/**
 * What a bot sees on every tick: a fresh copy of the grid, your cycle and your opponents'.
 * Coordinates start at (0, 0) in the top-left corner. Edges, walls and every trail are deadly.
 */
final class Arena
{
    /**
     * @param list<Cycle>       $opponents
     * @param array<int, mixed> $blocked   keys are $y * $width + $x
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        /** Starts at 1 and goes up by one on every move. */
        public readonly int $tick,
        public readonly Cycle $me,
        public readonly array $opponents,
        private readonly array $blocked,
    ) {
    }

    /** Is this cell inside the arena and not a wall or a trail? */
    public function isFree(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < $this->width && $y < $this->height && !isset($this->blocked[$y * $this->width + $x]);
    }

    /** Would moving this way survive the next tick? (Ignores where the opponent goes.) */
    public function isSafe(Direction $direction): bool
    {
        return $this->isFree($this->me->x + $direction->dx(), $this->me->y + $direction->dy());
    }

    /** @return list<Direction> every direction that doesn't crash right away */
    public function safeDirections(): array
    {
        return array_values(array_filter(Direction::cases(), $this->isSafe(...)));
    }
}
