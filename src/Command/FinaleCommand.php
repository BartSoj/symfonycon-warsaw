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

/**
 * Computes finales and saves them; /finale plays them. The staff form on /finale runs this in the background.
 * --map picks a preset from Battle::MAPS (--all-maps: one battle per preset); --map=custom uses the other options.
 * --rounds computes several battles on the same map, each with new seats.
 */
#[AsCommand('app:finale', 'Put every bot on one arena at once and save the replay for /finale')]
class FinaleCommand
{
    public function __construct(private Bots $bots, private League $league, private Battle $battle, private EntityManagerInterface $em, #[Autowire('%kernel.project_dir%/var/finale.running')] private string $lock)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('A preset from Battle::MAPS, or custom')] string $map = 'pens',
        #[Option('Battles to compute on that map')] int $rounds = 1,
        #[Option('One battle on every preset map')] bool $allMaps = false,
        #[Option('all: every visitor bot; everyone: visitor and arena bots; arena: only bots in the arena; or a comma-separated list of class names')] string $bots = 'all',
        #[Option('Compute this many battles and keep only the 3 most interesting (Battle::interest)')] int $pick = 0,
        #[Option('Also add The House')] bool $house = false,
        #[Option('custom: size like 80x50 (automatic if empty)')] string $size = '',
        #[Option('custom: free cells per bot, for the automatic size')] int $cells = 90,
        #[Option('custom: wall layout (pens, open, pillars, rooms, rings, cross)')] string $layout = 'pens',
        #[Option('custom pens: how deep each pen is')] int $pen = 5,
        #[Option('custom pens: how wide the door is')] int $door = 2,
        #[Option('custom pens: back, side or door')] string $facing = 'back',
        #[Option('custom: number of random wall segments')] int $walls = 0,
        #[Option('custom: ring, center or random')] string $start = 'ring',
        #[Option('Trails of crashed bots disappear')] bool $vanish = false,
        #[Option('Random if 0')] int $seed = 0,
        #[Option('Shown above the arena')] string $title = 'The Grand Finale',
    ): int {
        touch($this->lock);
        try {
            $keys = match ($bots) {
                'all' => array_values(array_filter($this->bots->keys(), fn ($k) => !$this->bots->info($k)['house'])),
                'everyone' => $this->bots->keys(),
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
            $custom = ['map' => $layout, 'w' => $w ?: null, 'h' => $h ?: null, 'cells' => $cells, 'walls' => $walls, 'start' => $start, 'pen' => $pen, 'door' => $door, 'facing' => $facing];

            $battles = [];
            foreach ($allMaps ? array_keys(Battle::MAPS) : array_fill(0, $pick ?: max(1, min(10, $rounds)), $map) as $round => $preset) {
                $options = (Battle::MAPS[$preset] ?? $custom) + ['vanish' => $vanish];
                if ($vanish) {
                    $options['vanish'] = true;
                }
                $s = $seed ? $seed + $round : random_int(1, 2 ** 31 - 1);
                $t = microtime(true);
                $replay = $this->battle->play($keys, $s, $options);
                $battles[] = [$s, round(microtime(true) - $t, 1), $replay, Battle::interest($replay)];
                if ($pick) {
                    $io->writeln(\sprintf('  battle %d of %d: interest %d, %d ticks', $round + 1, $pick, end($battles)[3], $replay['ticks']));
                }
            }
            if ($pick) {
                usort($battles, fn ($a, $b) => $b[3] <=> $a[3]);
                $battles = \array_slice($battles, 0, 3);
            }

            foreach ($battles as [$s, $seconds, $replay, $interest]) {
                $finale = new Finale();
                $finale->title = $title;
                $finale->seed = $s;
                $finale->createdAt = new \DateTimeImmutable();
                $finale->seconds = $seconds;
                $finale->replay = $replay;
                $this->em->persist($finale);
                $this->em->flush();

                $players = $replay['players'];
                usort($players, fn ($a, $b) => $a['place'] <=> $b['place']);
                $io->writeln(\sprintf('Finale #%d "%s": %d bots on %dx%d, %d walls, %d ticks, interest %d, computed in %.1f s', $finale->id, $replay['options']['label'], \count($keys), $replay['w'], $replay['h'], \count($replay['walls']), $replay['ticks'], $interest, $finale->seconds));
                foreach ($io->isVerbose() || (!$allMaps && $rounds <= 1) ? $players : [] as $pl) {
                    $io->writeln(\sprintf('  %2d. %-22s %-14s %6d ms  %s', $pl['place'], $pl['name'], $pl['author'], $pl['ms'], $pl['crash'] ? "tick {$pl['crash']['tick']}: {$pl['crash']['reason']}" : 'survived'));
                }
            }

            return 0;
        } finally {
            @unlink($this->lock);
        }
    }
}
