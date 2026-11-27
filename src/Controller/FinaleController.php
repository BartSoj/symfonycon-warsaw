<?php

namespace App\Controller;

use App\Arena\Battle;
use App\Entity\Finale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The 5pm finale: every bot on one arena. Unlisted for now; link to /finale when it's time. */
class FinaleController extends AbstractController
{
    #[Route('/finale', name: 'finale')]
    #[Route('/finale/{id}', name: 'finale_show', requirements: ['id' => '\d+'])]
    public function show(EntityManagerInterface $em, ?int $id = null): Response
    {
        $repo = $em->getRepository(Finale::class);
        $finale = $id ? $repo->find($id) : $repo->findOneBy([], ['id' => 'DESC']);
        if ($id && !$finale) {
            throw $this->createNotFoundException();
        }

        return $this->page($em, $finale ? [$finale] : []);
    }

    /** Plays several precomputed finales back to back: /finale/show?ids[]=3&ids[]=7 */
    #[Route('/finale/show', name: 'finale_playlist')]
    public function playlist(EntityManagerInterface $em, Request $request): Response
    {
        $ids = array_map('intval', $request->query->all('ids'));
        $found = $ids ? $em->getRepository(Finale::class)->findBy(['id' => $ids]) : [];
        usort($found, fn ($a, $b) => array_search($a->id, $ids, true) <=> array_search($b->id, $ids, true));

        return $this->page($em, $found);
    }

    /** @param list<Finale> $finales */
    private function page(EntityManagerInterface $em, array $finales): Response
    {
        $history = [];
        foreach ($em->getRepository(Finale::class)->findBy([], ['id' => 'DESC'], 40) as $f) {
            $history[] = ['id' => $f->id, 'title' => $f->title, 'at' => $f->createdAt, 'seconds' => $f->seconds, 'label' => $f->replay['options']['label'] ?? 'Custom',
                'size' => $f->replay['w'].'×'.$f->replay['h'], 'bots' => \count($f->replay['players']), 'ticks' => $f->replay['ticks'], 'walls' => \count($f->replay['walls'])];
        }

        return $this->render('finale.html.twig', [
            'finales' => array_map(fn (Finale $f) => ['id' => $f->id, 'title' => $f->title, 'replay' => $f->replay], $finales),
            'running' => $this->running(),
            'maps' => Battle::MAPS,
            'history' => $history,
        ]);
    }

    #[Route('/finale/latest.json', name: 'finale_latest')]
    public function latest(EntityManagerInterface $em): JsonResponse
    {
        $id = $em->createQuery('SELECT MAX(f.id) FROM App\Entity\Finale f')->getSingleScalarResult();

        return $this->json(['id' => $id ? (int) $id : null, 'running' => $this->running()]);
    }

    /** Computes a new finale in the background (it takes a few seconds), so the server keeps serving the TV. */
    #[Route('/finale/run', name: 'finale_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        if (!$this->running()) {
            $r = $request->request;
            $map = isset(Battle::MAPS[$r->get('map')]) ? $r->get('map') : 'custom';
            $args = [
                '--map='.$map,
                '--rounds='.max(1, min(10, $r->getInt('rounds', 1))),
                '--layout='.(\in_array($r->get('layout'), ['pens', 'open', 'pillars', 'rooms', 'rings', 'cross'], true) ? $r->get('layout') : 'pens'),
                '--facing='.(\in_array($r->get('facing'), ['back', 'side', 'door'], true) ? $r->get('facing') : 'back'),
                '--pen='.max(3, min(12, $r->getInt('pen', 5))),
                '--door='.max(1, min(6, $r->getInt('door', 2))),
                '--bots='.($r->get('bots') === 'arena' ? 'arena' : 'all'),
                '--start='.(\in_array($r->get('start'), ['ring', 'center', 'random'], true) ? $r->get('start') : 'ring'),
                '--walls='.max(0, min(60, $r->getInt('walls'))),
                '--cells='.max(30, min(2000, $r->getInt('cells', 90))),
                '--title='.mb_substr(trim($r->get('title', '')) ?: 'The Grand Finale', 0, 60),
            ];
            if ($r->get('house')) {
                $args[] = '--house';
            }
            if ($r->get('vanish')) {
                $args[] = '--vanish';
            }
            $dir = $this->getParameter('kernel.project_dir');
            touch($dir.'/var/finale.running');
            // Close inherited descriptors (like this request's socket), or the browser waits for the whole run.
            exec(\sprintf('(cd %s && for fd in $(seq 3 64); do eval "exec $fd>&-"; done; exec %s bin/console app:finale %s) < /dev/null > %s 2>&1 &', escapeshellarg($dir), escapeshellarg(PHP_BINARY), implode(' ', array_map('escapeshellarg', $args)), escapeshellarg($dir.'/var/log/finale.log')));
        }

        return $this->redirectToRoute('finale', ['wait' => 1]);
    }

    private function running(): bool
    {
        $lock = $this->getParameter('kernel.project_dir').'/var/finale.running';

        return is_file($lock) && filemtime($lock) > time() - 300;
    }
}
