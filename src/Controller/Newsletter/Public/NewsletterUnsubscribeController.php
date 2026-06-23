<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Newsletter\Public;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Newsletter\NewsletterDelivery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/escalated/n/u', name: 'escalated.newsletters.public.unsubscribe.')]
class NewsletterUnsubscribeController extends AbstractController
{
    /** @var array<string, array{count: int, reset: int}> */
    private static array $rateLimit = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly bool $enabled = false,
    ) {
    }

    #[Route('/{token}', name: 'show', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function show(string $token): Response
    {
        $this->abortUnlessEnabled();
        $delivery = $this->findDelivery($token);

        return $this->renderUnsubscribe($token, $delivery?->getEmailAtSend(), false);
    }

    #[Route('/{token}', name: 'store', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['POST'])]
    public function store(string $token, Request $request): Response
    {
        $this->abortUnlessEnabled();
        if (!$this->hitRateLimit($request->getClientIp() ?: 'unknown')) {
            return new Response('Too Many Requests', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $delivery = $this->findDelivery($token);
        if ($delivery instanceof NewsletterDelivery) {
            $contact = $this->em->find(Contact::class, $delivery->getContactId());
            if ($contact instanceof Contact) {
                $contact->setMarketingOptOutAt(new \DateTimeImmutable());
                $this->em->flush();
            }
        }

        return $this->renderUnsubscribe($token, $delivery?->getEmailAtSend(), true);
    }

    private function findDelivery(string $token): ?NewsletterDelivery
    {
        return $this->em->getRepository(NewsletterDelivery::class)->findOneBy(['trackingToken' => $token]);
    }

    private function renderUnsubscribe(string $token, ?string $email, bool $confirmed): Response
    {
        $escapedEmail = htmlspecialchars($email ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escapedToken = htmlspecialchars($token, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = $confirmed ? 'You have been unsubscribed.' : 'Confirm unsubscribe';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Unsubscribe</title></head><body>'
            .'<p>'.$message.'</p>'
            .($escapedEmail ? '<p>'.$escapedEmail.'</p>' : '')
            .'<form method="post" action=""><input type="hidden" name="token" value="'.$escapedToken.'"><button type="submit">Unsubscribe</button></form>'
            .'</body></html>';

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html']);
    }

    private function hitRateLimit(string $key): bool
    {
        $now = time();
        $bucket = self::$rateLimit[$key] ?? ['count' => 0, 'reset' => $now + 60];
        if ($bucket['reset'] <= $now) {
            $bucket = ['count' => 0, 'reset' => $now + 60];
        }
        if ($bucket['count'] >= 60) {
            self::$rateLimit[$key] = $bucket;

            return false;
        }
        ++$bucket['count'];
        self::$rateLimit[$key] = $bucket;

        return true;
    }

    private function abortUnlessEnabled(): void
    {
        if (!$this->enabled) {
            throw $this->createNotFoundException();
        }
    }
}
