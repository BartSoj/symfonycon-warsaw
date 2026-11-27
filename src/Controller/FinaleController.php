<?php

namespace App\Controller;

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

        return $this->render('finale.html.twig', [
            'finale' => $finale,
            'running' => $this->running(),
            'history' => $em->createQuery('SELECT f.id, f.title, f.createdAt, f.seconds FROM App\Entity\Finale f ORDER BY f.id DESC')->setMaxResults(15)->getArrayResult(),
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
            $args = [
                '--bots='.($r->get('bots') === 'arena' ? 'arena' : 'all'),
                '--start='.(\in_array($r->get('start'), ['ring', 'center', 'random'], true) ? $r->get('start') : 'ring'),
                '--walls='.max(0, min(60, $r->getInt('walls'))),
                '--cells='.max(80, min(2000, $r->getInt('cells', 360))),
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
