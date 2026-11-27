<?php

namespace App\Controller;

use App\Arena\Bots;
use App\Arena\League;
use App\Entity\Game;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class ArenaController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function home(League $league, \App\Arena\LiveFinale $live): Response
    {
        $latest = $league->recent(limit: 1)[0] ?? null;

        return $this->render('home.html.twig', ['rows' => $league->leaderboard(), 'latest' => $latest, 'finaleAt' => $live->get()['at']]);
    }

    #[Route('/bot/{name}', name: 'bot')]
    public function bot(string $name, Bots $bots, League $league): Response
    {
        if (!$bots->has($name)) {
            throw new NotFoundHttpException("No bot called $name");
        }
        $info = $bots->info($name);
        $board = $league->leaderboard();
        $rank = array_search($name, array_column($board, 'key'), true);

        return $this->render('bot.html.twig', [
            'bot' => $board[$rank],
            'rank' => $rank + 1,
            'trial' => $info['house'] ? null : $league->trial($name),
            'recent' => $league->recent($name),
            'source' => $bots->source($name),
        ]);
    }

    #[Route('/bot/{name}/toggle', name: 'bot_toggle', methods: ['POST'])]
    public function toggle(string $name, Bots $bots, League $league): RedirectResponse
    {
        if ($bots->has($name) && !$bots->info($name)['house']) {
            $league->toggle($name);
        }

        return $this->redirectToRoute('bot', ['name' => $name]);
    }

    /** A test match against The House, just for this bot's page. */
    #[Route('/bot/{name}/practice', name: 'bot_practice')]
    public function practice(string $name, Bots $bots, League $league): JsonResponse
    {
        if (!$bots->has($name)) {
            throw new NotFoundHttpException("No bot called $name");
        }

        return $this->json($league->practice($name));
    }

    #[Route('/game/{id}', name: 'game', requirements: ['id' => '\d+'])]
    public function game(Game $game): Response
    {
        return $this->render('game.html.twig', ['game' => $game]);
    }

    /** Plays the next match right now and returns its replay. The TV calls this in a loop. */
    #[Route('/arena/next', name: 'arena_next')]
    public function next(League $league): JsonResponse
    {
        return $this->json($league->next()->forPlayer());
    }
}
