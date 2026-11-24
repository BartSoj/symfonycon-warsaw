<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** Adds a feature to the /shipped page. bin/ship calls it; you don't need to. */
#[AsCommand('app:record-shipped', 'Record a shipped feature in data/shipped/')]
class RecordShippedCommand
{
    public function __construct(#[Autowire('%kernel.project_dir%/data/shipped')] private string $dir)
    {
    }

    public function __invoke(#[Argument] string $title, #[Argument] string $by, #[Option] string $idea = ''): int
    {
        $at = new \DateTimeImmutable();
        $file = \sprintf('%s/%s-%s.json', $this->dir, $at->format('Ymd-His'), new AsciiSlugger()->slug($title)->lower()->truncate(40));
        file_put_contents($file, json_encode(['title' => $title, 'by' => $by, 'at' => $at->format(\DATE_ATOM), 'idea' => $idea ?: null], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE)."\n");

        return 0;
    }
}
