<?php

namespace App\Command;

use App\Arena\Battle;
use App\Arena\Bots;
use App\Arena\League;
use App\Entity\Finale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Computes a finale and saves it; /finale plays the newest one. The staff form on /finale runs this in the background. */
#[AsCommand('app:finale', 'Put every bot on one arena at once and save the replay for /finale')]
class FinaleCommand
{
    public function __construct(private Bots $bots, private League $league, private Battle $battle, private EntityManagerInterface $em, #[Autowire('%kernel.project_dir%/var/finale.running')] private string $lock)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('all: every visitor bot; arena: only bots in the arena; or a comma-separated list of class names')] string $bots = 'all',
        #[Option('Also add The House')] bool $house = false,
        #[Option('Size like 80x50 (automatic if empty)')] string $size = '',
        #[Option('Free cells per bot, for the automatic size')] int $cells = 360,
        #[Option('Number of random wall segments')] int $walls = 0,
        #[Option('ring, center or random')] string $start = 'ring',
        #[Option('Trails of crashed bots disappear')] bool $vanish = false,
        #[Option('Thinking time per move, in ms')] int $moveMs = 100,
        #[Option('Random if 0')] int $seed = 0,
        #[Option('Shown above the arena')] string $title = 'The Grand Finale',
    ): int {
        touch($this->lock);
        try {
            $keys = match ($bots) {
                'all' => array_values(array_filter($this->bots->keys(), fn ($k) => !$this->bots->info($k)['house'])),
                'arena' => array_column(array_filter($this->league->leaderboard(), fn ($r) => $r['enabled'] && !$r['house']), 'key'),
                default => array_values(array_filter(array_map('trim', explode(',', $bots)), $this->bots->has(...))),
            };
            if ($house) {
                $keys[] = League::HOUSE;
            }
            if (\count($keys) < 2) {
                $io->error('A finale needs at least 2 bots.');

                return 1;
            }
            [$w, $h] = array_map('intval', explode('x', $size.'x'));
            $seed = $seed ?: random_int(1, 2 ** 31 - 1);
            $t = microtime(true);
            $replay = $this->battle->play($keys, $seed, ['w' => $w ?: null, 'h' => $h ?: null, 'cells' => $cells, 'walls' => $walls, 'start' => $start, 'vanish' => $vanish, 'moveMs' => $moveMs]);

            $finale = new Finale();
            $finale->title = $title;
            $finale->seed = $seed;
            $finale->createdAt = new \DateTimeImmutable();
            $finale->seconds = round(microtime(true) - $t, 1);
            $finale->replay = $replay;
            $this->em->persist($finale);
            $this->em->flush();

            $players = $replay['players'];
            usort($players, fn ($a, $b) => $a['place'] <=> $b['place']);
            $io->writeln(\sprintf('Finale #%d: %d bots on %dx%d, %d ticks, computed in %.1f s', $finale->id, \count($keys), $replay['w'], $replay['h'], $replay['ticks'], $finale->seconds));
            foreach ($players as $pl) {
                $io->writeln(\sprintf('  %2d. %-22s %-14s %6d ms  %s', $pl['place'], $pl['name'], $pl['author'], $pl['ms'], $pl['crash'] ? "tick {$pl['crash']['tick']}: {$pl['crash']['reason']}" : 'survived'));
            }

            return 0;
        } finally {
            @unlink($this->lock);
        }
    }
}
