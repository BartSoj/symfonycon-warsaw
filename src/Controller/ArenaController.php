<?php

namespace App\Controller;

use App\Arena\Bots;
use App\Arena\League;
use App\Entity\Game;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class ArenaController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function home(League $league): Response
    {
        $latest = $league->recent(limit: 1)[0] ?? null;

        return $this->render('home.html.twig', ['rows' => $league->leaderboard(), 'latest' => $latest]);
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
