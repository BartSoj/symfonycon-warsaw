<?php

namespace App;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/** English / Klingon switch, kept in the "lang" cookie. Twig: lang() and 'text'|t */
class Lang
{
    private const TLH = [
        'Schedule' => 'nIqHom',
        'Speakers' => 'jatlhwI\'pu\'',
        'Ideas' => 'qechmey',
        'Shipped' => 'ngevlu\'pu\'',
        'times are Warsaw time' => 'Warsaw poH lo\'lu\'',
        '26–27 November' => 'jar wa\'maH wa\' DIS 26–27',
        'Built live by SymfonyCon attendees with AI agents in PhpStorm, at the JetBrains booth.' => 'SymfonyCon ghotpu\' chenmoH, PhpStorm De\'wI\' yab lo\'taHvIS. JetBrains Daq qaS.',
        'features by' => 'Do\'ghI\'mey, chenmoHwI\'',
        'people so far' => 'ghot',
        'Add yours →' => 'DaH yIchel! →',
        'Language' => 'Hol',
        'What should we build next?' => 'qaStaHvIS wa\' ram nuq wImutlh?',
        'Shipped at the booth' => 'Daq ngevlu\'pu\'bogh',
        'Just shipped' => 'DaH ngevlu\'pu\'',
        'Up for grabs' => 'yIjon!',
        'on TV' => 'HaSta jIHDaq',
        'Thursday 26 November' => 'DaSjaj loS, jar wa\'maH wa\' 26',
        'Friday 27 November' => 'DaSjaj vagh, jar wa\'maH wa\' 27',
        'Saturday 28 November' => 'DaSjaj jav, jar wa\'maH wa\' 28',
    ];

    public function __construct(private RequestStack $requests)
    {
    }

    #[AsTwigFunction('lang')]
    public function lang(): string
    {
        return $this->requests->getMainRequest()?->cookies->get('lang') === 'tlh' ? 'tlh' : 'en';
    }

    #[AsTwigFilter('t')]
    public function t(string $text): string
    {
        return $this->lang() === 'tlh' ? (self::TLH[$text] ?? $text) : $text;
    }
}
