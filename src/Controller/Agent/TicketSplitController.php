<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Service\TicketSplittingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/agent/tickets', name: 'escalated.agent.tickets.')]
class TicketSplitController extends AbstractController
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly TicketSplittingService $splittingService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/{reference}/split', name: 'split', methods: ['POST'])]
    public function split(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $replyId = (int) $request->request->get('reply_id');
        $reply = $this->em->find(Reply::class, $replyId);
        if (null === $reply) {
            throw $this->createNotFoundException('Reply not found.');
        }

        $subject = $request->request->get('subject');
        $overrides = [];
        if (null !== $subject && '' !== $subject) {
            $overrides['subject'] = $subject;
        }

        try {
            $newTicket = $this->splittingService->splitTicket(
                $ticket,
                $reply,
                (int) $this->getUser()->getUserIdentifier(),
                $overrides,
            );
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', sprintf('Ticket split. New ticket: %s', $newTicket->getReference()));

        return $this->redirectToRoute('escalated.agent.tickets.show', [
            'reference' => $newTicket->getReference(),
        ]);
    }
}
