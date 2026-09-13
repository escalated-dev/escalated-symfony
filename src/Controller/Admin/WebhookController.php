<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Webhook;
use Escalated\Symfony\Entity\WebhookDelivery;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\WebhookDispatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD over outbound Webhook subscriptions + a per-webhook delivery log
 * and a manual delivery retry.
 *
 * Mirrors AutomationController's shape (Inertia render for GET, redirect+flash
 * for writes, the payload()/serialize() helpers). Delivery + signing logic
 * lives in WebhookDispatcher.
 */
#[Route('/admin/webhooks', name: 'escalated.admin.webhooks.')]
class WebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly WebhookDispatcher $dispatcher,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $webhooks = $this->em->getRepository(Webhook::class)
            ->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);

        return $this->renderer->render('Escalated/Admin/Webhooks/Index', [
            'webhooks' => array_map([$this, 'serialize'], $webhooks),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Webhooks/Form', [
            'availableEvents' => $this->availableEvents(),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $payload = $this->payload($request);
        if (null !== $error = $this->validate($payload)) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('escalated.admin.webhooks.index');
        }

        $webhook = new Webhook();
        $this->applyPayload($webhook, $payload);

        $this->em->persist($webhook);
        $this->em->flush();

        $this->addFlash('success', 'Webhook created.');

        return $this->redirectToRoute('escalated.admin.webhooks.index');
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $webhook = $this->em->getRepository(Webhook::class)->find($id);
        if (null === $webhook) {
            throw $this->createNotFoundException('Webhook not found.');
        }

        return $this->renderer->render('Escalated/Admin/Webhooks/Form', [
            'webhook' => $this->serialize($webhook),
            'availableEvents' => $this->availableEvents(),
        ]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH', 'PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $webhook = $this->em->getRepository(Webhook::class)->find($id);
        if (null === $webhook) {
            throw $this->createNotFoundException('Webhook not found.');
        }

        $payload = $this->payload($request);
        if (null !== $error = $this->validate($payload)) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('escalated.admin.webhooks.index');
        }

        $this->applyPayload($webhook, $payload);
        $this->em->flush();

        $this->addFlash('success', 'Webhook updated.');

        return $this->redirectToRoute('escalated.admin.webhooks.index');
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $webhook = $this->em->getRepository(Webhook::class)->find($id);
        if (null === $webhook) {
            throw $this->createNotFoundException('Webhook not found.');
        }

        $this->em->remove($webhook);
        $this->em->flush();

        $this->addFlash('success', 'Webhook deleted.');

        return $this->redirectToRoute('escalated.admin.webhooks.index');
    }

    #[Route('/{id}/deliveries', name: 'deliveries', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function deliveries(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $webhook = $this->em->getRepository(Webhook::class)->find($id);
        if (null === $webhook) {
            throw $this->createNotFoundException('Webhook not found.');
        }

        $deliveries = $this->em->getRepository(WebhookDelivery::class)
            ->findBy(['webhook' => $webhook], ['createdAt' => 'DESC', 'id' => 'DESC'], 100);

        return $this->renderer->render('Escalated/Admin/Webhooks/DeliveryLog', [
            'webhook' => $this->serialize($webhook),
            'deliveries' => array_map([$this, 'serializeDelivery'], $deliveries),
        ]);
    }

    #[Route('/deliveries/{delivery}/retry', name: 'retry', methods: ['POST'], requirements: ['delivery' => '\d+'])]
    public function retry(int $delivery): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $entity = $this->em->getRepository(WebhookDelivery::class)->find($delivery);
        if (null === $entity) {
            throw $this->createNotFoundException('Webhook delivery not found.');
        }

        $this->dispatcher->retryDelivery($entity);

        $this->addFlash('success', 'Webhook delivery retried.');

        return $this->redirectToRoute('escalated.admin.webhooks.deliveries', [
            'id' => $entity->getWebhook()->getId(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        if ('' !== ($json = (string) $request->getContent())
            && str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')
        ) {
            $decoded = json_decode($json, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string|null an error message, or null when the payload is valid
     */
    private function validate(array $payload): ?string
    {
        $url = isset($payload['url']) ? trim((string) $payload['url']) : '';
        if ('' === $url || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            return 'A valid webhook URL is required.';
        }

        $events = $payload['events'] ?? null;
        if (!\is_array($events) || [] === array_filter($events, static fn ($e) => '' !== (string) $e)) {
            return 'At least one event must be selected.';
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function applyPayload(Webhook $webhook, array $payload): void
    {
        if (isset($payload['url'])) {
            $webhook->setUrl(trim((string) $payload['url']));
        }
        if (isset($payload['events']) && \is_array($payload['events'])) {
            $webhook->setEvents(array_values(array_map('strval', $payload['events'])));
        }
        if (\array_key_exists('secret', $payload)) {
            $secret = null === $payload['secret'] ? null : (string) $payload['secret'];
            $webhook->setSecret('' === $secret ? null : $secret);
        }
        if (\array_key_exists('active', $payload)) {
            $webhook->setActive((bool) $payload['active']);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(Webhook $webhook): array
    {
        return [
            'id' => $webhook->getId(),
            'url' => $webhook->getUrl(),
            'events' => $webhook->getEvents(),
            'has_secret' => null !== $webhook->getSecret() && '' !== $webhook->getSecret(),
            'active' => $webhook->isActive(),
            'deliveries_count' => $webhook->getDeliveries()->count(),
            'created_at' => $webhook->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $webhook->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDelivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->getId(),
            'event' => $delivery->getEvent(),
            'response_code' => $delivery->getResponseCode(),
            'response_body' => $delivery->getResponseBody(),
            'attempts' => $delivery->getAttempts(),
            'success' => $delivery->isSuccess(),
            'delivered_at' => $delivery->getDeliveredAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $delivery->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<int, string> */
    private function availableEvents(): array
    {
        return [
            'ticket.created',
            'ticket.updated',
            'ticket.status_changed',
            'ticket.resolved',
            'ticket.closed',
            'ticket.reopened',
            'ticket.assigned',
            'ticket.unassigned',
            'ticket.escalated',
            'ticket.priority_changed',
            'ticket.department_changed',
            'reply.created',
            'note.created',
            'sla.breached',
            'ticket.tag_added',
            'ticket.tag_removed',
        ];
    }
}
