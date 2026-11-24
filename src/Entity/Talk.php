<?php

namespace App\Entity;

use App\Repository\TalkRepository;
use Doctrine\ORM\Mapping as ORM;

/** One slot of the programme: a talk, the keynote, a break, the party. Seeded from data/schedule.json. */
#[ORM\Entity(repositoryClass: TalkRepository::class)]
class Talk
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(unique: true)]
    public string $slug = '';

    #[ORM\Column]
    public string $title = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    public array $speakers = [];

    #[ORM\Column(nullable: true)]
    public ?string $room = null;

    #[ORM\Column]
    public \DateTimeImmutable $startsAt;

    #[ORM\Column]
    public \DateTimeImmutable $endsAt;

    /** talk, keynote, break, lunch, social or other */
    #[ORM\Column]
    public string $type = 'talk';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $abstract = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    public array $tags = [];

    public function isSession(): bool
    {
        return \in_array($this->type, ['talk', 'keynote'], true);
    }
}
