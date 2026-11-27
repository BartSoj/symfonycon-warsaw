<?php

namespace App\Arena;

/**
 * Plays one 1-on-1 match and returns the replay. The arena is 180° symmetric, so both sides are fair.
 * The seed decides where the walls are.
 */
final class Referee
{
    public const WIDTH = 32;
    public const HEIGHT = 20;
    public const MOVE_MS = 50;      // per move
    public const MATCH_MS = 1500;   // per bot, for the whole match

    public function __construct(private Bots $bots)
    {
    }

    /** @return array{w: int, h: int, walls: list<array{int, int}>, players: list<array>, winner: ?int, ticks: int} */
    public function play(string $keyA, string $keyB, int $seed): array
    {
        set_time_limit(60);
        $w = self::WIDTH;
        $h = self::HEIGHT;
        $starts = [[6, 9, Direction::Right], [$w - 7, $h - 10, Direction::Left]];

        // Walls, mirrored through the centre, never right in front of a start.
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
        $owner = [];
        for ($i = 0, $n = $rng->getInt(0, 8); $i < $n; ++$i) {
            $vertical = 1 === $rng->getInt(0, 1);
            $x = $rng->getInt(0, $w - 1);
            $y = $rng->getInt(0, $h - 1);
            for ($k = 0, $len = $rng->getInt(1, 5); $k < $len; ++$k) {
                $cx = $x + ($vertical ? 0 : $k);
                $cy = $y + ($vertical ? $k : 0);
                foreach ([[$cx, $cy], [$w - 1 - $cx, $h - 1 - $cy]] as [$px, $py]) {
                    if ($px < 0 || $py < 0 || $px >= $w || $py >= $h) {
                        continue;
                    }
                    foreach ($starts as [$sx, $sy, $sd]) {
                        $ahead = $py === $sy && ($px - $sx) * $sd->dx() >= 0 && abs($px - $sx) <= 8;
                        if ($ahead || abs($px - $sx) + abs($py - $sy) <= 4) {
                            continue 2;
                        }
                    }
                    $owner[$py * $w + $px] = 'wall';
                }
            }
        }
        $walls = array_map(fn ($i) => [$i % $w, intdiv($i, $w)], array_keys($owner));

        $p = [];
        foreach ([$keyA, $keyB] as $i => $key) {
            [$x, $y, $d] = $starts[$i];
            $p[$i] = $this->bots->info($key) + ['x' => $x, 'y' => $y, 'moves' => '', 'crash' => null, 'bot' => clone $this->bots->get($key), 'd' => $d, 'ms' => 0.0];
            $owner[$y * $w + $x] = $i;
        }

        $tick = 0;
        while (null === $p[0]['crash'] && null === $p[1]['crash'] && $tick < $w * $h) {
            ++$tick;
            $next = [];
            foreach ([0, 1] as $i) {
                $o = $p[1 - $i];
                $arena = new Arena($w, $h, $tick, new Cycle($p[$i]['x'], $p[$i]['y'], $p[$i]['d']), [new Cycle($o['x'], $o['y'], $o['d'])], $owner);
                $error = null;
                $t = hrtime(true);
                try {
                    $d = $p[$i]['bot']->move($arena);
                } catch (\Throwable $e) {
                    $d = $p[$i]['d'];
                    $error = '💥 '.(new \ReflectionClass($e))->getShortName().': '.mb_substr($e->getMessage(), 0, 200);
                }
                $ms = (hrtime(true) - $t) / 1e6;
                $p[$i]['ms'] += $ms;
                if (!$error && $ms > self::MOVE_MS) {
                    $error = \sprintf('🐢 took %d ms for one move (the limit is %d ms)', $ms, self::MOVE_MS);
                } elseif (!$error && $p[$i]['ms'] > self::MATCH_MS) {
                    $error = \sprintf('🐢 used more than %d ms in total this match', self::MATCH_MS);
                }
                $next[$i] = [$p[$i]['x'] + $d->dx(), $p[$i]['y'] + $d->dy(), $d, $error];
            }
            foreach ([0, 1] as $i) {
                [$x, $y, $d, $error] = $next[$i];
                $hit = $x >= 0 && $y >= 0 && $x < $w && $y < $h ? ($owner[$y * $w + $x] ?? null) : 'edge';
                $reason = $error ?? match (true) {
                    'edge' === $hit => 'drove off the edge',
                    'wall' === $hit => 'hit a wall',
                    $hit === $i => 'hit its own trail',
                    null !== $hit => 'hit '.$p[1 - $i]['name'].'’s trail',
                    [$x, $y] === [$next[1 - $i][0], $next[1 - $i][1]] => 'crashed head-on',
                    default => null,
                };
                $p[$i]['d'] = $d;
                if (null !== $reason) {
                    $p[$i]['crash'] = ['tick' => $tick, 'reason' => $reason, 'error' => null !== $error];
                }
            }
            foreach ([0, 1] as $i) {
                if (null === $p[$i]['crash']) {
                    [$p[$i]['x'], $p[$i]['y']] = $next[$i];
                    $p[$i]['moves'] .= $next[$i][2]->value;
                    $owner[$p[$i]['y'] * $w + $p[$i]['x']] = $i;
                }
            }
        }

        $alive = array_keys(array_filter($p, fn ($x) => null === $x['crash']));
        $players = [];
        foreach ([0, 1] as $i) {
            $players[] = [
                'key' => $p[$i]['key'], 'name' => $p[$i]['name'], 'author' => $p[$i]['author'], 'color' => $p[$i]['color'],
                'x' => $starts[$i][0], 'y' => $starts[$i][1], 'moves' => $p[$i]['moves'], 'crash' => $p[$i]['crash'],
            ];
        }

        return ['w' => $w, 'h' => $h, 'walls' => $walls, 'players' => $players, 'winner' => 1 === \count($alive) ? $alive[0] : null, 'ticks' => $tick];
    }
}
