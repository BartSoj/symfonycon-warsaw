<?php

namespace App\Command;

use App\Arena\LiveFinale;
use App\Entity\Finale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:finale:live', 'Pick the finale that /finale/live counts down to and plays')]
class FinaleLiveCommand
{
    public function __construct(private LiveFinale $live, private EntityManagerInterface $em)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Finale id (see /finale → Staff)')] int $id,
        #[Option('Start time, like 16:45 (Warsaw time)')] string $at = LiveFinale::DEFAULT_AT,
    ): int {
        if (!$this->em->find(Finale::class, $id)) {
            $io->error("No finale #$id");

            return 1;
        }
        $this->live->set($id, $at);
        $io->success(\sprintf('/finale/live plays finale #%d at %s', $id, $this->live->get()['at']->format('Y-m-d H:i T')));

        return 0;
    }
}
