<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventSubscriber;

use Escalated\Symfony\Security\TicketAccess;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class TicketAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TicketAccess $access, private readonly TokenStorageInterface $tokens)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['start', 5], 'kernel.finish_request' => ['finish', -1024]];
    }

    public function start(RequestEvent $event): void
    {
        $event->getRequest()->attributes->set('_escalated_previous_actor', $this->access->actor());
        $this->access->setActor($this->tokens->getToken()?->getUser());
    }

    public function finish(FinishRequestEvent $event): void
    {
        $this->access->setActor($event->getRequest()->attributes->get('_escalated_previous_actor'));
    }
}
