<?php

namespace App\Controller;

use App\Entity\Talk;
use App\Repository\TalkRepository;
use chillerlan\QRCode\QRCode;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PageController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function home(TalkRepository $talks): Response
    {
        return $this->render('home.html.twig', ['days' => $talks->byDay()]);
    }

    #[Route('/talk/{slug}', name: 'talk')]
    public function talk(#[MapEntity(mapping: ['slug' => 'slug'])] Talk $talk): Response
    {
        return $this->render('talk.html.twig', ['talk' => $talk]);
    }

    #[Route('/speakers', name: 'speakers')]
    public function speakers(TalkRepository $talks): Response
    {
        $bySpeaker = [];
        foreach ($talks->findAll() as $talk) {
            foreach ($talk->speakers as $speaker) {
                $bySpeaker[$speaker][] = $talk;
            }
        }
        ksort($bySpeaker, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->render('speakers.html.twig', ['speakers' => $bySpeaker]);
    }

    #[Route('/ideas', name: 'ideas')]
    public function ideas(): Response
    {
        return $this->render('ideas.html.twig');
    }

    #[Route('/shipped', name: 'shipped')]
    public function shipped(): Response
    {
        return $this->render('shipped.html.twig');
    }

    /** For the booth TV: QR code to the app, what's been shipped, ideas to pick. Refreshes itself. */
    #[Route('/tv', name: 'tv')]
    public function tv(): Response
    {
        $url = $this->generateUrl('home', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('tv.html.twig', ['url' => $url, 'qr' => (new QRCode())->render($url)]);
    }
}
