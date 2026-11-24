<?php

namespace App\Command;

use App\Repository\TalkRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

/** The only test this app has: every page must render. bin/ship runs it. */
#[AsCommand('app:check', 'Render every GET page once and fail on any error')]
class CheckCommand
{
    public function __construct(private RouterInterface $router, private HttpKernelInterface $kernel, private TalkRepository $talks)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $sample = ['slug' => $this->talks->findOneBy(['type' => 'talk'])?->slug ?? 'none', 'id' => 1];
        $failed = 0;
        foreach ($this->router->getRouteCollection() as $name => $route) {
            if (str_starts_with($name, '_') || ($route->getMethods() && !\in_array('GET', $route->getMethods(), true))) {
                continue;
            }
            $params = array_intersect_key($sample, array_flip($route->compile()->getPathVariables()));
            try {
                $path = $this->router->generate($name, $params);
                $status = $this->kernel->handle(Request::create($path), HttpKernelInterface::MAIN_REQUEST, false)->getStatusCode();
                $error = $status >= 500 ? "HTTP $status" : null;
            } catch (HttpExceptionInterface $e) {
                $error = $e->getStatusCode() >= 500 ? $e->getMessage() : null;
                $status = $e->getStatusCode();
            } catch (\Throwable $e) {
                $error = $e::class.': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine();
                $status = 500;
            }
            $io->writeln(\sprintf('%s %-4s %s', $error ? '<error>FAIL</error>' : '<info>ok</info>', $status, $path ?? $name));
            if ($error) {
                $io->writeln("     $error");
                ++$failed;
            }
        }

        return $failed ? 1 : 0;
    }
}
