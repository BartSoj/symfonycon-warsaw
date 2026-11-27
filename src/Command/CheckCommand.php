<?php

namespace App\Command;

use App\Arena\Bots;
use App\Arena\League;
use App\Arena\Referee;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

/** The only test this app has: every bot plays a few matches without errors, and every page renders. bin/ship runs it. */
#[AsCommand('app:check', 'Check every bot, then render every GET page once and fail on any error')]
class CheckCommand
{
    public function __construct(private RouterInterface $router, private HttpKernelInterface $kernel, private Bots $bots, private Referee $referee)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $failed = 0;

        foreach ($this->bots->keys() as $key) {
            $info = $this->bots->info($key);
            $problems = array_filter([$info['problem']]);
            $wins = 0;
            $survived = 0;
            foreach ([...League::TRIAL_SEEDS, 1, 2] as $i => $seed) {
                $replay = $this->referee->play($key, $i < 3 ? League::HOUSE : 'RookieBot', $seed);
                $crash = $replay['players'][0]['crash'];
                if ($crash['error'] ?? false) {
                    $problems[] = "seed $seed, tick {$crash['tick']}: {$crash['reason']}";
                }
                $wins += $i < 3 && 0 === $replay['winner'] ? 1 : 0;
                $survived = max($survived, $i < 3 ? League::survived($replay, 0) : 0);
            }
            $verdict = $info['house'] ? '' : " · beats The House $wins/3, survives up to $survived ticks: ".match (true) {
                $wins >= 2 => '🏆',
                default => 'not yet (it needs 2 wins)',
            };
            $io->writeln(\sprintf('%s bot %s "%s" by %s%s', $problems ? '<error>FAIL</error>' : '<info>ok</info>', $key, $info['name'], $info['author'], $verdict));
            foreach (array_unique($problems) as $problem) {
                $io->writeln("     $problem");
            }
            $failed += $problems ? 1 : 0;
        }

        $sample = ['name' => League::HOUSE, 'id' => 1];
        foreach ($this->router->getRouteCollection() as $name => $route) {
            if (str_starts_with($name, '_') || ($route->getMethods() && !\in_array('GET', $route->getMethods(), true))) {
                continue;
            }
            $params = array_intersect_key($sample, array_flip($route->compile()->getPathVariables()));
            try {
                $path = $this->router->generate($name, $params);
                $status = $this->kernel->handle(Request::create($path), HttpKernelInterface::MAIN_REQUEST, false)->getStatusCode();
                $error = $status >= 500 ? "HTTP $status" : null;
            } catch (HttpExceptionInterface $e) {
                $error = $e->getStatusCode() >= 500 ? $e->getMessage() : null;
                $status = $e->getStatusCode();
            } catch (\Throwable $e) {
                $error = $e::class.': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine();
                $status = 500;
            }
            $io->writeln(\sprintf('%s %-4s %s', $error ? '<error>FAIL</error>' : '<info>ok</info>', $status, $path ?? $name));
            if ($error) {
                $io->writeln("     $error");
                ++$failed;
            }
        }

        return $failed ? 1 : 0;
    }
}
