<?php

namespace App\Arena;

/**
 * The 5pm finale: every bot on one arena at once, last one riding wins. Separate from Referee (1-on-1) on
 * purpose, so tuning the finale never changes the trials. Bots see every other living cycle in $arena->opponents.
 */
final class Battle
{
    /** Map presets for the finale. Any DEFAULTS key can be set; staff pick one on /finale or with --map. */
    public const MAPS = [
        'pens' => ['label' => 'The pens', 'map' => 'pens', 'cells' => 90],
        'open' => ['label' => 'Open field'],
        'pillars' => ['label' => 'Pillars', 'map' => 'pillars', 'cells' => 400],
        'rooms' => ['label' => 'Four rooms', 'map' => 'rooms', 'cells' => 400],
        'rings' => ['label' => 'Rings', 'map' => 'rings', 'cells' => 440],
        'cross' => ['label' => 'The cross', 'map' => 'cross', 'cells' => 380],
        'debris' => ['label' => 'Debris', 'walls' => -1, 'cells' => 420],
        'tight' => ['label' => 'Tight squeeze', 'cells' => 170, 'start' => 'center'],
        'huge' => ['label' => 'Huge', 'map' => 'pillars', 'cells' => 900],
        'ghost' => ['label' => 'Ghost trails', 'cells' => 260, 'vanish' => true, 'start' => 'random'],
    ];

    public const DEFAULTS = [
        'label' => 'Custom',
        'map' => 'open',     // wall layout: pens, open, pillars, rooms, rings, cross
        'cells' => 360,      // free cells per bot when the size is automatic (pens: of the open middle)
        'pen' => 5,          // pens: how deep each bot's pen is
        'door' => 2,         // pens: how wide the way out is
        'facing' => 'back',  // pens: where bots face at the start: back (they turn around to get out), side or door
        'w' => null, 'h' => null,
        'walls' => 0,        // random wall segments on top of the layout; -1 = a lot
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
        if ('pens' === $o['map']) {
            [$w, $h, $fixed, $starts] = $this->pens($n, $o);
        } else {
            $w = (int) ($o['w'] ?: max(36, ceil(sqrt($n * $o['cells'] * 1.6))));
            $h = (int) ($o['h'] ?: max(22, round($w / 1.6)));
            [$fixed, $starts] = [[], $this->ring($n, $w, $h, $o['start'], $rng)];
        }

        // Walls: the map's layout plus random segments, but never right next to or in front of a start.
        $cells = 'pens' === $o['map'] ? [] : $this->layout($o['map'], $w, $h);
        for ($i = 0, $segments = $o['walls'] < 0 ? (int) round($w * $h / 70) : $o['walls']; $i < $segments; ++$i) {
            $vertical = 1 === $rng->getInt(0, 1);
            $x = $rng->getInt(0, $w - 1);
            $y = $rng->getInt(0, $h - 1);
            for ($k = 0, $len = $rng->getInt(2, 7); $k < $len; ++$k) {
                $cells[] = [$x + ($vertical ? 0 : $k), $y + ($vertical ? $k : 0)];
            }
        }
        $owner = [];
        foreach ($fixed as [$px, $py]) {
            $owner[$py * $w + $px] = 'wall';
        }
        foreach ($cells as [$px, $py]) {
            if ($px < 0 || $py < 0 || $px >= $w || $py >= $h) {
                continue;
            }
            foreach ($starts as [$sx, $sy, $sd]) {
                if (abs($px - $sx) + abs($py - $sy) <= 4 || ($sd->dx() ? $py === $sy && ($px - $sx) * $sd->dx() > 0 && abs($px - $sx) <= 8 : $px === $sx && ($py - $sy) * $sd->dy() > 0 && abs($py - $sy) <= 8)) {
                    continue 2;
                }
            }
            $owner[$py * $w + $px] = 'wall';
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

    /** @return list<array{int, int, Direction}> starts spread on an ellipse */
    private function ring(int $n, int $w, int $h, string $start, \Random\Randomizer $rng): array
    {
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
            [$dx, $dy] = match ($start) {
                'center' => [($w - 1) / 2 - $x, ($h - 1) / 2 - $y],
                'random' => [[1, -1, 0, 0][$k = $rng->getInt(0, 3)], [0, 0, 1, -1][$k]],
                default => [-sin($a), cos($a)], // clockwise on screen (y points down)
            };
            $d = abs($dx) >= abs($dy) ? ($dx > 0 ? Direction::Right : Direction::Left) : ($dy > 0 ? Direction::Down : Direction::Up);
            $starts[] = [$x, $y, $d];
        }

        return $starts;
    }

    /**
     * Every bot starts in its own pen along the edge of the board, with one door to an open field in the middle.
     * Bots first have to get out, then they all meet in the middle.
     *
     * @return array{int, int, list<array{int, int}>, list<array{int, int, Direction}>} width, height, walls, starts
     */
    private function pens(int $n, array $o): array
    {
        $d = max(3, (int) $o['pen']);
        $door = max(1, (int) $o['door']);
        $aw = (int) ($o['w'] ? $o['w'] - 2 * ($d + 1) : max(30, ceil(sqrt($n * $o['cells'] * 1.6))));
        $ah = (int) ($o['h'] ? $o['h'] - 2 * ($d + 1) : max(18, round($aw / 1.6)));
        while (2 * ($aw + $ah) < $n * ($d + 1)) { // every pen at least as wide as it is deep
            $aw += 2;
            $ah = max($ah, (int) round($aw / 1.6));
        }
        $w = $aw + 2 * ($d + 1);
        $h = $ah + 2 * ($d + 1);

        // The four sides, clockwise from the top: length, (along, depth from the edge) => cell, inwards, clockwise.
        $sides = [
            [$aw, fn ($u, $v) => [$d + 1 + $u, $v], Direction::Down, Direction::Right],
            [$ah, fn ($u, $v) => [$w - 1 - $v, $d + 1 + $u], Direction::Left, Direction::Down],
            [$aw, fn ($u, $v) => [$w - 2 - $d - $u, $h - 1 - $v], Direction::Up, Direction::Left],
            [$ah, fn ($u, $v) => [$v, $h - 2 - $d - $u], Direction::Right, Direction::Up],
        ];
        $perimeter = 2 * ($aw + $ah);
        $count = [intdiv($n * $aw, $perimeter), intdiv($n * $ah, $perimeter)];
        $count = [$count[0], $count[1], $count[0], $count[1]];
        for ($i = 0; array_sum($count) < $n; ++$i) {
            ++$count[[0, 2, 1, 3][$i]];
        }

        $walls = [];
        for ($x = 0; $x <= $d; ++$x) { // solid corners
            for ($y = 0; $y <= $d; ++$y) {
                array_push($walls, [$x, $y], [$w - 1 - $x, $y], [$x, $h - 1 - $y], [$w - 1 - $x, $h - 1 - $y]);
            }
        }
        $starts = [];
        foreach ($sides as $s => [$len, $at, $in, $clockwise]) {
            $k = $count[$s];
            $doors = [];
            $u0 = 0;
            for ($j = 0; $j < $k; ++$j) {
                $pw = intdiv($len - $k + 1, $k) + ($j < ($len - $k + 1) % $k ? 1 : 0);
                $mid = $u0 + intdiv($pw - 1, 2);
                for ($u = $mid - intdiv($door - 1, 2), $end = $u + $door; $u < $end; ++$u) {
                    $doors[$u] = true;
                }
                $starts[] = [...$at($mid, intdiv($d - 1, 2)), match ($o['facing']) {
                    'door' => $in,
                    'back' => $in->opposite(),
                    default => $clockwise,
                }];
                for ($v = 0; $v < $d && $j < $k - 1; ++$v) {
                    $walls[] = $at($u0 + $pw, $v);
                }
                $u0 += $pw + 1;
            }
            for ($u = 0; $u < $len; ++$u) {
                for ($v = $k ? $d : 0; $v <= $d; ++$v) { // a side without pens is solid
                    if (!isset($doors[$u]) || $v < $d) {
                        $walls[] = $at($u, $v);
                    }
                }
            }
        }

        return [$w, $h, $walls, $starts];
    }

    /** @return list<array{int, int}> wall cells of a layout */
    private function layout(string $map, int $w, int $h): array
    {
        $cells = [];
        $line = function (int $x1, int $y1, int $x2, int $y2, array $gaps = [], int $gap = 3) use (&$cells) {
            for ($x = $x1, $y = $y1; ; $x += $x2 <=> $x1, $y += $y2 <=> $y1) {
                $along = $x1 === $x2 ? $y : $x;
                if (!array_filter($gaps, fn ($g) => abs($along - $g) <= $gap)) {
                    $cells[] = [$x, $y];
                }
                if ($x === $x2 && $y === $y2) {
                    break;
                }
            }
        };
        $cx = intdiv($w, 2);
        $cy = intdiv($h, 2);
        switch ($map) {
            case 'pillars':
                $step = max(7, intdiv($w, 9));
                for ($x = intdiv($step, 2); $x < $w - 2; $x += $step) {
                    for ($y = intdiv($step, 2); $y < $h - 2; $y += $step) {
                        array_push($cells, [$x, $y], [$x + 1, $y], [$x, $y + 1], [$x + 1, $y + 1]);
                    }
                }
                break;
            case 'rooms':
                $line($cx, 0, $cx, $h - 1, [intdiv($h, 4), intdiv(3 * $h, 4)]);
                $line(0, $cy, $w - 1, $cy, [intdiv($w, 4), intdiv(3 * $w, 4)]);
                break;
            case 'rings':
                // Outer ring with doors in the middle of each side, inner ring with doors at the quarters.
                foreach ([[.2, false], [.35, true]] as [$f, $quarters]) {
                    $x1 = (int) round($w * $f);
                    $y1 = (int) round($h * $f);
                    $x2 = $w - 1 - $x1;
                    $y2 = $h - 1 - $y1;
                    $gx = $quarters ? [$x1 + intdiv($x2 - $x1, 4), $x2 - intdiv($x2 - $x1, 4)] : [$cx];
                    $gy = $quarters ? [$y1 + intdiv($y2 - $y1, 4), $y2 - intdiv($y2 - $y1, 4)] : [$cy];
                    $line($x1, $y1, $x2, $y1, $gx);
                    $line($x1, $y2, $x2, $y2, $gx);
                    $line($x1, $y1, $x1, $y2, $gy);
                    $line($x2, $y1, $x2, $y2, $gy);
                }
                break;
            case 'cross':
                $line($cx - intdiv($w, 5), $cy, $cx + intdiv($w, 5), $cy);
                $line($cx, $cy - intdiv($h, 4), $cx, $cy + intdiv($h, 4));
                foreach ([[1, 1], [1, -1], [-1, 1], [-1, -1]] as [$sx, $sy]) {
                    $x = $cx + $sx * intdiv($w, 3);
                    $y = $cy + $sy * intdiv($h, 3);
                    $line($x, $y, $x - $sx * intdiv($w, 10), $y);
                    $line($x, $y, $x, $y - $sy * intdiv($h, 8));
                }
                break;
        }

        return $cells;
    }
}
