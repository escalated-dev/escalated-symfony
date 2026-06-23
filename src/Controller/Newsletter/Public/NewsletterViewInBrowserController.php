<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Newsletter\Public;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Newsletter\Newsletter;
use Escalated\Symfony\Entity\Newsletter\NewsletterDelivery;
use Escalated\Symfony\Entity\Newsletter\NewsletterTemplate;
use Escalated\Symfony\Service\Newsletter\NewsletterRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/escalated/n', name: 'escalated.newsletters.public.')]
class NewsletterViewInBrowserController extends AbstractController
{
    private const UNAVAILABLE_HTML = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Email unavailable</title></head><body><p>This email is no longer available.</p></body></html>';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsletterRenderer $renderer,
        private readonly bool $enabled = false,
    ) {
    }

    #[Route('/v/{token}', name: 'view', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function show(string $token): Response
    {
        $this->abortUnlessEnabled();
        $delivery = $this->em->getRepository(NewsletterDelivery::class)->findOneBy(['trackingToken' => $token]);
        if (!$delivery instanceof NewsletterDelivery) {
            return new Response(self::UNAVAILABLE_HTML, Response::HTTP_OK, ['Content-Type' => 'text/html']);
        }

        $newsletter = $this->em->find(Newsletter::class, $delivery->getNewsletterId());
        $contact = $this->em->find(Contact::class, $delivery->getContactId());
        if (!$newsletter instanceof Newsletter || !$contact instanceof Contact) {
            return new Response(self::UNAVAILABLE_HTML, Response::HTTP_OK, ['Content-Type' => 'text/html']);
        }
        $template = $newsletter->getTemplateId()
            ? $this->em->find(NewsletterTemplate::class, $newsletter->getTemplateId())
            : null;
        $templateData = $template instanceof NewsletterTemplate
            ? ['body_markdown' => $template->getBodyMarkdown(), 'theme' => $template->getTheme()]
            : null;

        return new Response(
            $this->renderer->render($delivery, $newsletter, $contact, $templateData),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html'],
        );
    }

    private function abortUnlessEnabled(): void
    {
        if (!$this->enabled) {
            throw $this->createNotFoundException();
        }
    }
}
