<?php

namespace App\Arena;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Which finale plays on /finale/live, and when. Set with app:finale:live; stored in var/finale-live.json. */
final class LiveFinale
{
    public const DEFAULT_AT = '16:45';
    public const TIMEZONE = 'Europe/Warsaw';

    public function __construct(#[Autowire('%kernel.project_dir%/var/finale-live.json')] private string $file)
    {
    }

    /** @return array{id: ?int, at: \DateTimeImmutable} */
    public function get(): array
    {
        $data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : [];

        return ['id' => $data['id'] ?? null, 'at' => self::time($data['at'] ?? self::DEFAULT_AT)];
    }

    public function set(?int $id, string $at): void
    {
        file_put_contents($this->file, json_encode(['id' => $id, 'at' => self::time($at)->format(\DATE_ATOM)]));
    }

    /** "16:45" means today in Warsaw; a full date works too. */
    public static function time(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone(self::TIMEZONE));
    }
}
