<?php

namespace App\Arena;

use App\Entity\Game;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The leaderboard (Elo), matchmaking for the TV, and the trial: 3 matches against The House.
 * Win 2 of them for a hoodie; win one or survive SURVIVE ticks in one for a T-shirt.
 */
final class League
{
    public const HOUSE = 'HouseBot';
    public const TRIAL_SEEDS = [16, 20, 7];
    public const SURVIVE = 100;

    public function __construct(private Bots $bots, private Referee $referee, private EntityManagerInterface $em)
    {
    }

    /** @return list<array> current bots, best first, with elo, played, wins, losses, draws, hoodie and tshirt (null = trial not played yet) */
    public function leaderboard(): array
    {
        $rows = [];
        foreach ($this->bots->keys() as $key) {
            $info = $this->bots->info($key);
            $rows[$info['id']] = $info + ['elo' => 1000.0, 'played' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0, 'hoodie' => null, 'tshirt' => null];
        }
        $elo = [];
        $games = $this->em->createQuery('SELECT g.a, g.b, g.winner FROM App\Entity\Game g WHERE g.trial = false ORDER BY g.id')->getArrayResult();
        foreach ($games as ['a' => $a, 'b' => $b, 'winner' => $winner]) {
            $ra = $elo[$a] ??= 1000.0;
            $rb = $elo[$b] ??= 1000.0;
            $score = null === $winner ? .5 : ($winner === $a ? 1.0 : 0.0);
            $delta = 24 * ($score - 1 / (1 + 10 ** (($rb - $ra) / 400)));
            $elo[$a] += $delta;
            $elo[$b] -= $delta;
            foreach ([$a => $score, $b => 1 - $score] as $id => $s) {
                if (isset($rows[$id])) {
                    ++$rows[$id]['played'];
                    ++$rows[$id][.5 === $s ? 'draws' : (1.0 === $s ? 'wins' : 'losses')];
                }
            }
        }
        foreach ($this->trialResults() as $id => [$wins, $survived]) {
            if (isset($rows[$id])) {
                $rows[$id]['hoodie'] = $wins >= 2;
                $rows[$id]['tshirt'] = $wins >= 1 || $survived >= self::SURVIVE;
            }
        }
        foreach ($rows as $id => &$row) {
            $row['elo'] = (int) round($elo[$id] ?? 1000);
        }
        unset($row);
        usort($rows, fn ($x, $y) => [$y['elo'], $y['wins']] <=> [$x['elo'], $x['wins']]);

        return $rows;
    }

    /** Runs the next match for the TV: a pending hoodie trial first, else the bot with the fewest games. */
    public function next(): Game
    {
        foreach ($this->bots->keys() as $key) {
            if (!$this->bots->info($key)['house'] && null !== $seed = $this->missingTrialSeed($key)) {
                return $this->play($key, self::HOUSE, $seed, true);
            }
        }

        $played = array_column($this->leaderboard(), 'played', 'key');
        $keys = array_keys($played);
        shuffle($keys);
        usort($keys, fn ($x, $y) => $played[$x] <=> $played[$y]);
        $a = $keys[0];
        $others = array_values(array_diff($keys, [$a]));
        $b = $others[array_rand($others)];
        if (random_int(0, 1)) {
            [$a, $b] = [$b, $a];
        }

        return $this->play($a, $b, random_int(1, 2 ** 31 - 1));
    }

    /** @return array{games: list<Game>, wins: int, survived: int, passed: bool, tshirt: bool} plays whatever is missing first */
    public function trial(string $key): array
    {
        while (null !== $seed = $this->missingTrialSeed($key)) {
            $this->play($key, self::HOUSE, $seed, true);
        }
        $id = $this->bots->info($key)['id'];
        $games = [];
        foreach ($this->em->getRepository(Game::class)->findBy(['a' => $id, 'trial' => true], ['id' => 'ASC']) as $game) {
            $games[$game->seed] ??= $game;
        }
        $wins = \count(array_filter($games, fn (Game $g) => $g->winner === $id));
        $survived = max([0, ...array_map(fn (Game $g) => $g->survived, $games)]);

        return ['games' => array_values($games), 'wins' => $wins, 'survived' => $survived, 'passed' => $wins >= 2, 'tshirt' => $wins >= 1 || $survived >= self::SURVIVE];
    }

    /** @return list<Game> */
    public function recent(?string $key = null, int $limit = 10): array
    {
        $qb = $this->em->getRepository(Game::class)->createQueryBuilder('g')->orderBy('g.id', 'DESC')->setMaxResults($limit);
        if ($key) {
            $qb->where('g.a = :id OR g.b = :id')->setParameter('id', $this->bots->info($key)['id']);
        } else {
            $qb->where('g.trial = false');
        }

        return $qb->getQuery()->getResult();
    }

    public function play(string $a, string $b, int $seed, bool $trial = false): Game
    {
        $game = new Game();
        $game->a = $this->bots->info($a)['id'];
        $game->b = $this->bots->info($b)['id'];
        $game->seed = $seed;
        $game->trial = $trial;
        $game->replay = $this->referee->play($a, $b, $seed);
        $game->winner = [0 => $game->a, 1 => $game->b][$game->replay['winner']] ?? null;
        $game->survived = self::survived($game->replay, 0);
        $game->playedAt = new \DateTimeImmutable();
        $this->em->persist($game);
        $this->em->flush();

        return $game;
    }

    private function missingTrialSeed(string $key): ?int
    {
        if (self::HOUSE === $key) {
            return null;
        }
        $done = array_column($this->em->createQuery('SELECT g.seed FROM App\Entity\Game g WHERE g.trial = true AND g.a = :id')->setParameter('id', $this->bots->info($key)['id'])->getArrayResult(), 'seed');

        return array_values(array_diff(self::TRIAL_SEEDS, $done))[0] ?? null;
    }

    /** How many ticks a player stayed alive in a replay. */
    public static function survived(array $replay, int $player): int
    {
        $crash = $replay['players'][$player]['crash'];

        return $crash ? $crash['tick'] - 1 : $replay['ticks'];
    }

    /** @return array<string, array{int, int}> bot id => [trial wins, most ticks survived], only for bots that played all 3 */
    private function trialResults(): array
    {
        $per = [];
        foreach ($this->em->createQuery('SELECT g.a, g.seed, g.winner, g.survived FROM App\Entity\Game g WHERE g.trial = true')->getArrayResult() as $g) {
            $per[$g['a']][$g['seed']] = [$g['winner'] === $g['a'], $g['survived']];
        }
        $per = array_filter($per, fn ($s) => \count($s) >= \count(self::TRIAL_SEEDS));

        return array_map(fn ($s) => [array_sum(array_column($s, 0)), max(array_column($s, 1))], $per);
    }
}
