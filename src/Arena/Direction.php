<?php

namespace App\Arena;

/** Where a light cycle goes next. The grid's y axis points down: Up is y - 1. */
enum Direction: string
{
    case Up = 'U';
    case Down = 'D';
    case Left = 'L';
    case Right = 'R';

    public function dx(): int
    {
        return match ($this) { self::Left => -1, self::Right => 1, default => 0 };
    }

    public function dy(): int
    {
        return match ($this) { self::Up => -1, self::Down => 1, default => 0 };
    }

    public function opposite(): self
    {
        return match ($this) { self::Up => self::Down, self::Down => self::Up, self::Left => self::Right, self::Right => self::Left };
    }

    /** A 90° turn to the left, as seen by the rider. */
    public function left(): self
    {
        return match ($this) { self::Up => self::Left, self::Left => self::Down, self::Down => self::Right, self::Right => self::Up };
    }

    /** A 90° turn to the right, as seen by the rider. */
    public function right(): self
    {
        return $this->left()->opposite();
    }
}
