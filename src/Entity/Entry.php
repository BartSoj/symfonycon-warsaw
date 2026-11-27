<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A bot that entered the shared arena. Bots without an Entry only play The House (trial and test matches). */
#[ORM\Entity]
class Entry
{
    /** Short class name of the bot, like "AnnaViperBot". Kept across versions. */
    #[ORM\Id, ORM\Column]
    public string $bot = '';

    #[ORM\Column]
    public \DateTimeImmutable $enteredAt;
}
