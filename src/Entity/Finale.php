<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** One run of the 5pm finale: every bot on one arena. Made by `bin/console app:finale` or the staff form on /finale. */
#[ORM\Entity]
class Finale
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $title = '';

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public int $seed = 0;

    /** Seconds the server needed to compute it. */
    #[ORM\Column]
    public float $seconds = 0;

    #[ORM\Column(type: 'json')]
    public array $replay = [];
}
