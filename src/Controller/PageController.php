<?php

namespace App\Controller;

use App\Arena\League;
use chillerlan\QRCode\QRCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PageController extends AbstractController
{
    /** The goodies at the JetBrains booth, shown in 3D on /merch and on the TV. */
    public const MERCH = [
        ['model' => '/model.glb', 'orbit' => 'auto 70deg 80%', 'name' => 'Alt+Enter fidget toy', 'pitch' => 'The most pressed shortcut in PhpStorm, now for your fingers. Click it all day.'],
        ['model' => '/3d/phpstorm-pin.glb', 'orbit' => 'auto 75deg 110%', 'name' => 'PhpStorm pin', 'pitch' => 'For your lanyard, bag or jacket.'],
        ['model' => '/3d/jetbrains-pin.glb', 'orbit' => 'auto 75deg 110%', 'name' => 'JetBrains pin', 'pitch' => 'The black square, in metal.'],
        ['model' => '/3d/jetbrains-lanyard.glb', 'orbit' => 'auto 60deg 70%', 'name' => 'JetBrains lanyard', 'pitch' => 'Hang your badge on it, and the pins too.'],
    ];

    #[Route('/merch', name: 'merch')]
    public function merch(): Response
    {
        return $this->render('merch.html.twig', ['items' => self::MERCH]);
    }

    /** For the booth TV: live matches, the leaderboard, QR code, merch. Plays forever. */
    #[Route('/tv', name: 'tv')]
    public function tv(League $league): Response
    {
        $url = $this->generateUrl('home', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('tv.html.twig', ['url' => $url, 'qr' => (new QRCode())->render($url), 'merch' => self::MERCH, 'rows' => \array_slice($league->leaderboard(), 0, 10)]);
    }

    /** The TV's leaderboard, refreshed after every match. */
    #[Route('/tv/board', name: 'tv_board')]
    public function tvBoard(League $league): Response
    {
        return $this->render('_leaderboard.html.twig', ['rows' => \array_slice($league->leaderboard(), 0, 10), 'compact' => true]);
    }
}
