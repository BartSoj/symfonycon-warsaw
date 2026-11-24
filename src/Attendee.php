<?php

namespace App;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * The person holding the phone. No login: every browser gets an anonymous id in a cookie.
 * Inject this class and call id() to store anything per attendee (stars, ratings, votes...).
 */
class Attendee
{
    private const COOKIE = 'attendee';
    private ?string $id = null;
    private bool $isNew = false;

    public function id(): string
    {
        if (null === $this->id) {
            $this->id = bin2hex(random_bytes(12));
            $this->isNew = true;
        }

        return $this->id;
    }

    #[AsEventListener(priority: 100)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $id = $event->getRequest()->cookies->get(self::COOKIE)) {
            $this->id = $id;
        }
    }

    #[AsEventListener]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $this->isNew) {
            $event->getResponse()->headers->setCookie(Cookie::create(self::COOKIE, $this->id, '+1 year'));
        }
    }
}
