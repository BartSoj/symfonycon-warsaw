<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** One match in the arena. Bots are stored as "ClassName@version", so a new version starts a fresh record. */
#[ORM\Entity]
#[ORM\Index(columns: ['trial'])]
class Game
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $a = '';

    #[ORM\Column]
    public string $b = '';

    /** Bot id of the winner, null for a draw. */
    #[ORM\Column(nullable: true)]
    public ?string $winner = null;

    #[ORM\Column]
    public int $seed = 0;

    /** A hoodie trial against The House: not counted on the leaderboard. */
    #[ORM\Column]
    public bool $trial = false;

    #[ORM\Column]
    public \DateTimeImmutable $playedAt;

    #[ORM\Column(type: 'json')]
    public array $replay = [];

    /** @return array{id: ?int, trial: bool, replay: array} what the replay player in assets/arena.js needs */
    public function forPlayer(): array
    {
        return ['id' => $this->id, 'trial' => $this->trial, 'replay' => $this->replay];
    }
}
