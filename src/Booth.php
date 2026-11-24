<?php

namespace App;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Twig\Attribute\AsTwigFunction;

/**
 * What the booth has built: features shipped (one JSON file each in data/shipped/, written by bin/ship)
 * and ideas for the next ones (data/ideas.yaml). Available in Twig as shipped(), ideas() and builders().
 */
class Booth
{
    public function __construct(#[Autowire('%kernel.project_dir%/data')] private string $dir)
    {
    }

    /** @return list<array{title: string, by: string, at: string, idea: ?string}> newest first */
    #[AsTwigFunction('shipped')]
    public function shipped(): array
    {
        $all = array_map(fn ($f) => json_decode(file_get_contents($f), true), glob($this->dir.'/shipped/*.json') ?: []);
        usort($all, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return $all;
    }

    /** @return list<string> unique names of people who asked for a shipped feature */
    #[AsTwigFunction('builders')]
    public function builders(): array
    {
        return array_values(array_unique(array_column($this->shipped(), 'by')));
    }

    /** @return list<array{id: string, title: string, pitch: string, level: string, shipped: bool}> */
    #[AsTwigFunction('ideas')]
    public function ideas(): array
    {
        $done = array_filter(array_column($this->shipped(), 'idea'));

        return array_map(fn ($i) => $i + ['shipped' => \in_array($i['id'], $done, true)], Yaml::parseFile($this->dir.'/ideas.yaml'));
    }
}
