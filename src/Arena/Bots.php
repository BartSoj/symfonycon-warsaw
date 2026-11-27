<?php

namespace App\Arena;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Every class implementing Bot, found by Symfony through the app.bot tag. Keyed by short class name. */
final class Bots
{
    /** @var array<string, Bot> */
    private array $bots = [];
    private array $info = [];

    /** @param iterable<Bot> $bots */
    public function __construct(#[AutowireIterator('app.bot')] iterable $bots)
    {
        foreach ($bots as $bot) {
            $this->bots[(new \ReflectionClass($bot))->getShortName()] = $bot;
        }
        ksort($this->bots);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->bots);
    }

    public function has(string $key): bool
    {
        return isset($this->bots[$key]);
    }

    public function get(string $key): Bot
    {
        return $this->bots[$key] ?? throw new \InvalidArgumentException("No bot called $key");
    }

    /** @return array{key: string, id: string, name: string, author: string, color: string, house: bool, file: string, problem: ?string} */
    public function info(string $key): array
    {
        return $this->info[$key] ??= (function () use ($key) {
            $bot = $this->get($key);
            $file = (new \ReflectionClass($bot))->getFileName();
            $problem = null;
            try {
                $name = trim($bot->name());
                $author = trim($bot->author());
                $color = $bot->color();
            } catch (\Throwable $e) {
                $problem = $e->getMessage();
            }
            if (!preg_match('/^#[0-9a-f]{6}$/i', $color ?? '')) {
                $problem ??= "color() must look like '#ff3366'";
                $color = \sprintf('#%06x', crc32($key) & 0xFFFFFF);
            }
            if ('' === ($name ?? '') || mb_strlen($name) > 30) {
                $problem ??= 'name() must be 1 to 30 characters';
                $name = mb_substr($name ?? '', 0, 30) ?: $key;
            }

            return [
                'key' => $key,
                // A new version of the file is a new contender: its record starts over.
                'id' => $key.'@'.substr(md5_file($file), 0, 8),
                'name' => $name,
                'author' => mb_substr($author ?? '', 0, 30) ?: 'someone',
                'color' => $color,
                'house' => str_starts_with($bot::class, 'App\\Arena\\House\\'),
                'file' => $file,
                'problem' => $problem,
            ];
        })();
    }

    public function source(string $key): string
    {
        return file_get_contents($this->info($key)['file']);
    }
}
