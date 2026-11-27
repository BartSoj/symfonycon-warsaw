<?php

namespace App\Arena;

/**
 * The 5pm finale: every bot on one arena at once, last one riding wins. Separate from Referee (1-on-1) on
 * purpose, so tuning the finale never changes the trials. Bots see every other living cycle in $arena->opponents.
 */
final class Battle
{
    public const DEFAULTS = [
        'cells' => 360,      // free cells per bot when the size is automatic
        'w' => null, 'h' => null,
        'walls' => 0,        // number of random wall segments
        'start' => 'ring',   // ring: spread on a ring riding clockwise; center: all facing the middle; random
        'vanish' => false,   // trails of crashed bots disappear
        'moveMs' => 100,     // per move
        'matchMs' => 30000,  // per bot, whole battle
    ];

    public function __construct(private Bots $bots)
    {
    }

    /** @param list<string> $keys */
    public function play(array $keys, int $seed, array $options = []): array
    {
        set_time_limit(0);
        $o = array_merge(self::DEFAULTS, array_filter($options, fn ($v) => null !== $v));
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
        $keys = $rng->shuffleArray(array_values($keys)); // random seats
        $n = \count($keys);
        $w = (int) ($o['w'] ?: max(36, ceil(sqrt($n * $o['cells'] * 1.6))));
        $h = (int) ($o['h'] ?: max(22, round($w / 1.6)));

        $starts = [];
        $taken = [];
        for ($i = 0; $i < $n; ++$i) {
            $a = 2 * M_PI * $i / $n - M_PI / 2;
            $x = (int) round(($w - 1) / 2 + cos($a) * ($w - 1) * .38);
            $y = (int) round(($h - 1) / 2 + sin($a) * ($h - 1) * .38);
            while (isset($taken[$y * $w + $x])) {
                ++$x;
            }
            $taken[$y * $w + $x] = true;
            [$dx, $dy] = match ($o['start']) {
                'center' => [($w - 1) / 2 - $x, ($h - 1) / 2 - $y],
                'random' => [[1, -1, 0, 0][$k = $rng->getInt(0, 3)], [0, 0, 1, -1][$k]],
                default => [-sin($a), cos($a)], // clockwise on screen (y points down)
            };
            $d = abs($dx) >= abs($dy) ? ($dx > 0 ? Direction::Right : Direction::Left) : ($dy > 0 ? Direction::Down : Direction::Up);
            $starts[] = [$x, $y, $d];
        }

        $owner = [];
        for ($i = 0; $i < $o['walls']; ++$i) {
            $vertical = 1 === $rng->getInt(0, 1);
            $x = $rng->getInt(0, $w - 1);
            $y = $rng->getInt(0, $h - 1);
            for ($k = 0, $len = $rng->getInt(2, 7); $k < $len; ++$k) {
                $px = $x + ($vertical ? 0 : $k);
                $py = $y + ($vertical ? $k : 0);
                if ($px >= $w || $py >= $h) {
                    continue;
                }
                foreach ($starts as [$sx, $sy, $sd]) {
                    if (abs($px - $sx) + abs($py - $sy) <= 4 || ($sd->dx() ? $py === $sy && ($px - $sx) * $sd->dx() > 0 && abs($px - $sx) <= 8 : $px === $sx && ($py - $sy) * $sd->dy() > 0 && abs($py - $sy) <= 8)) {
                        continue 2;
                    }
                }
                $owner[$py * $w + $px] = 'wall';
            }
        }
        $walls = array_map(fn ($i) => [$i % $w, intdiv($i, $w)], array_keys($owner));

        $p = [];
        foreach ($keys as $i => $key) {
            [$x, $y, $d] = $starts[$i];
            $p[$i] = $this->bots->info($key) + ['x' => $x, 'y' => $y, 'sx' => $x, 'sy' => $y, 'd' => $d, 'moves' => '', 'crash' => null, 'ms' => 0.0, 'cells' => [$y * $w + $x], 'bot' => clone $this->bots->get($key)];
            $owner[$y * $w + $x] = $i;
        }

        $tick = 0;
        $alive = array_keys($p);
        while (\count($alive) > 1 && $tick < $w * $h) {
            ++$tick;
            $next = [];
            foreach ($alive as $i) {
                $others = [];
                foreach ($alive as $j) {
                    if ($j !== $i) {
                        $others[] = new Cycle($p[$j]['x'], $p[$j]['y'], $p[$j]['d']);
                    }
                }
                $arena = new Arena($w, $h, $tick, new Cycle($p[$i]['x'], $p[$i]['y'], $p[$i]['d']), $others, $owner);
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
                if (!$error && $ms > $o['moveMs']) {
                    $error = \sprintf('🐢 took %d ms for one move', $ms);
                } elseif (!$error && $p[$i]['ms'] > $o['matchMs']) {
                    $error = '🐢 ran out of thinking time';
                }
                $next[$i] = [$p[$i]['x'] + $d->dx(), $p[$i]['y'] + $d->dy(), $d, $error];
            }

            $targets = [];
            foreach ($next as [$x, $y]) {
                $targets[$y * $w + $x] = ($targets[$y * $w + $x] ?? 0) + 1;
            }
            $crashed = [];
            foreach ($next as $i => [$x, $y, $d, $error]) {
                $inside = $x >= 0 && $y >= 0 && $x < $w && $y < $h;
                $hit = $inside ? ($owner[$y * $w + $x] ?? null) : 'edge';
                $by = \is_int($hit) && $hit !== $i ? $hit : null;
                $reason = $error ?? match (true) {
                    'edge' === $hit => 'drove off the edge',
                    'wall' === $hit => 'hit a wall',
                    $hit === $i => 'hit its own trail',
                    null !== $by => 'hit '.$p[$by]['name'].'’s trail',
                    $targets[$y * $w + $x] > 1 => 'crashed head-on',
                    default => null,
                };
                $p[$i]['d'] = $d;
                if (null !== $reason) {
                    $p[$i]['crash'] = ['tick' => $tick, 'reason' => $reason, 'by' => $by, 'error' => null !== $error];
                    $crashed[] = $i;
                }
            }
            foreach ($next as $i => [$x, $y, $d]) {
                if (!\in_array($i, $crashed, true)) {
                    $p[$i]['x'] = $x;
                    $p[$i]['y'] = $y;
                    $p[$i]['moves'] .= $d->value;
                    $owner[$y * $w + $x] = $i;
                    $p[$i]['cells'][] = $y * $w + $x;
                }
            }
            if ($o['vanish']) {
                foreach ($crashed as $i) {
                    foreach ($p[$i]['cells'] as $cell) {
                        unset($owner[$cell]);
                    }
                }
            }
            $alive = array_values(array_diff($alive, $crashed));
        }

        // Places: survivors first, then by how long they lasted. Same tick = same place.
        $lasted = array_map(fn ($x) => $x['crash'] ? $x['crash']['tick'] : PHP_INT_MAX, $p);
        $players = [];
        foreach ($p as $i => $x) {
            $players[] = [
                'key' => $x['key'], 'name' => $x['name'], 'author' => $x['author'], 'color' => $x['color'],
                'x' => $x['sx'], 'y' => $x['sy'], 'moves' => $x['moves'], 'crash' => $x['crash'],
                'place' => 1 + \count(array_filter($lasted, fn ($l) => $l > $lasted[$i])), 'ms' => (int) $x['ms'],
            ];
        }

        return ['w' => $w, 'h' => $h, 'walls' => $walls, 'players' => $players, 'winner' => 1 === \count($alive) ? $alive[0] : null, 'ticks' => $tick, 'vanish' => (bool) $o['vanish'], 'options' => $o];
    }
}
