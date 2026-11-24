<?php

namespace App\Repository;

use App\Entity\Talk;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Talk> */
class TalkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Talk::class);
    }

    /** @return array<string, list<Talk>> talks grouped by day ("Thursday 26 November" => [...]) */
    public function byDay(): array
    {
        $days = [];
        foreach ($this->findBy([], ['startsAt' => 'ASC', 'room' => 'ASC']) as $talk) {
            $days[$talk->startsAt->format('l j F')][] = $talk;
        }

        return $days;
    }
}
