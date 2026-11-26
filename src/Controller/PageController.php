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
    public function talk(#[MapEntity(mapping: ['slug' => 'slug'])] Talk $talk, \App\Attendee $attendee, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        $file = $this->getParameter('kernel.project_dir').'/var/ratings.json';
        $fh = fopen($file, 'c+');
        flock($fh, LOCK_EX);
        $all = json_decode(stream_get_contents($fh) ?: '{}', true) ?: [];

        if ($request->isMethod('POST')) {
            $stars = (int) $request->request->get('stars');
            if ($stars >= 1 && $stars <= 5) {
                $all[$talk->slug][$attendee->id()] = $stars;
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, json_encode($all, JSON_PRETTY_PRINT));
            }
            flock($fh, LOCK_UN);
            fclose($fh);

            if ($request->headers->get('X-Requested-With') === 'fetch') {
                $votes = $all[$talk->slug] ?? [];

                return $this->json([
                    'mine' => $votes[$attendee->id()] ?? 0,
                    'avg' => $votes ? round(array_sum($votes) / count($votes), 1) : null,
                    'count' => count($votes),
                ]);
            }

            return $this->redirectToRoute('talk', ['slug' => $talk->slug]);
        }
        flock($fh, LOCK_UN);
        fclose($fh);

        $votes = $all[$talk->slug] ?? [];

        return $this->render('talk.html.twig', [
            'talk' => $talk,
            'myStars' => $votes[$attendee->id()] ?? 0,
            'avgStars' => $votes ? round(array_sum($votes) / count($votes), 1) : null,
            'voteCount' => count($votes),
        ]);
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

    #[Route('/board', name: 'board')]
    public function board(TalkRepository $talks): Response
    {
        $list = array_map(fn (Talk $t) => [
            'title' => $t->title, 'speakers' => implode(', ', $t->speakers), 'room' => $t->room,
            'start' => $t->startsAt->format(DATE_ATOM), 'end' => $t->endsAt->format(DATE_ATOM),
        ], $talks->findBy([], ['startsAt' => 'ASC', 'room' => 'ASC']));

        return $this->render('board.html.twig', ['talks' => $list]);
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

    #[Route('/merch', name: 'merch')]
    public function merch(): Response
    {
        return $this->render('merch.html.twig', ['items' => self::MERCH]);
    }

    /** The goodies at the JetBrains booth, shown in 3D on /merch and on the TV. */
    public const MERCH = [
        ['model' => '/model.glb', 'orbit' => 'auto 70deg 80%', 'name' => 'Alt+Enter fidget toy', 'pitch' => 'The most pressed shortcut in PhpStorm, now for your fingers. Click it all day.'],
        ['model' => '/3d/phpstorm-pin.glb', 'orbit' => 'auto 75deg 110%', 'name' => 'PhpStorm pin', 'pitch' => 'For your lanyard, bag or jacket.'],
        ['model' => '/3d/jetbrains-pin.glb', 'orbit' => 'auto 75deg 110%', 'name' => 'JetBrains pin', 'pitch' => 'The black square, in metal.'],
        ['model' => '/3d/jetbrains-lanyard.glb', 'orbit' => 'auto 60deg 70%', 'name' => 'JetBrains lanyard', 'pitch' => 'Hang your badge on it, and the pins too.'],
    ];

    /** For the booth TV: QR code to the app, what's been shipped, ideas to pick. Refreshes itself. */
    #[Route('/tv', name: 'tv')]
    public function tv(): Response
    {
        $url = $this->generateUrl('home', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('tv.html.twig', ['url' => $url, 'qr' => (new QRCode())->render($url), 'merch' => self::MERCH]);
    }

    #[Route('/lang/{code}', name: 'lang', defaults: ['code' => 'en'])]
    public function lang(string $code, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        $response = $this->redirect($request->headers->get('referer') ?: $this->generateUrl('home'));
        $response->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create('lang', $code === 'tlh' ? 'tlh' : 'en', strtotime('+1 year')));

        return $response;
    }
}
