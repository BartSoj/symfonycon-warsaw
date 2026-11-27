<?php

namespace App\Arena;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A light-cycle bot. Put one class in src/Bot/ that implements this interface and it joins the arena.
 * Each match gets a fresh clone of your bot, so properties are a safe place to remember things.
 * No constructor arguments, no files, no network, no database. Each move must take under 50 ms.
 */
#[AutoconfigureTag('app.bot')]
interface Bot
{
    /** Shown on the leaderboard and the TV, 30 characters max. */
    public function name(): string;

    /** First name or handle of the person who built it. */
    public function author(): string;

    /** Colour of the trail, like '#ff3366'. */
    public function color(): string;

    /** Called once per tick. Return where to go next. */
    public function move(Arena $arena): Direction;
}
