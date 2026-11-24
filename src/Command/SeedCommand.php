<?php

namespace App\Command;

use App\Entity\Talk;
use App\Repository\TalkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:seed', 'Create or update the talks from data/schedule.json (safe to run again)')]
class SeedCommand
{
    public function __construct(
        private EntityManagerInterface $em,
        private TalkRepository $talks,
        #[Autowire('%kernel.project_dir%/data/schedule.json')] private string $file,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        foreach (json_decode(file_get_contents($this->file), true) as $row) {
            $talk = $this->talks->findOneBy(['slug' => $row['slug']]) ?? new Talk();
            $talk->slug = $row['slug'];
            $talk->title = $row['title'];
            $talk->speakers = $row['speakers'];
            $talk->room = $row['room'];
            $talk->startsAt = new \DateTimeImmutable($row['starts_at'])->setTimezone(new \DateTimeZone('Europe/Warsaw'));
            $talk->endsAt = new \DateTimeImmutable($row['ends_at'])->setTimezone(new \DateTimeZone('Europe/Warsaw'));
            $talk->type = $row['type'];
            $talk->abstract = $row['abstract'];
            $talk->tags = $row['tags'];
            $this->em->persist($talk);
        }
        $this->em->flush();
        $io->success(\count($this->talks->findAll()).' talks in the database.');

        return 0;
    }
}
