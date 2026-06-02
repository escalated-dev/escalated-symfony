<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Newsletter\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Newsletter\Newsletter;
use Escalated\Symfony\Entity\Newsletter\NewsletterDelivery;
use Escalated\Symfony\Entity\Newsletter\NewsletterList;
use Escalated\Symfony\Entity\Newsletter\NewsletterTemplate;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\NewsletterPermissions;
use Escalated\Symfony\Service\Newsletter\NewsletterPlanner;
use Escalated\Symfony\Service\Newsletter\NewsletterRenderer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

#[Route('/admin/newsletters', name: 'escalated.admin.newsletters.')]
class NewsletterController extends NewsletterAdminController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly NewsletterPlanner $planner,
        private readonly NewsletterRenderer $newsletterRenderer,
        private readonly ?string $defaultFrom = null,
        private readonly ?string $defaultReplyTo = null,
        private readonly string $defaultTheme = 'default',
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $tab = (string) $request->query->get('tab', 'drafts');
        $statuses = match ($tab) {
            'scheduled' => ['scheduled', 'sending', 'paused'],
            'sent' => ['sent', 'failed'],
            default => ['draft'],
        };
        $rows = $this->em->getRepository(Newsletter::class)->findBy(
            ['status' => $statuses],
            ['id' => 'DESC'],
            50,
        );

        return $this->renderer->render('Escalated/Admin/Newsletters/Index', [
            'newsletters' => $this->paginate(array_map(fn (Newsletter $n): array => $this->newsletterArray($n, true), $rows), 50),
            'tab' => $tab,
        ]);
    }

    #[Route('/new', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        return $this->renderer->render('Escalated/Admin/Newsletters/Compose', $this->composeProps());
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $data = $this->validateForm($this->payload($request));
        $this->guardSendStatus($data['status']);
        if (in_array($data['status'], ['scheduled', 'sending'], true) && !$this->mailConfigured()) {
            throw new UnprocessableEntityHttpException('Outbound mail is not configured.');
        }

        $newsletter = $this->applyNewsletterData(new Newsletter(), $data)
            ->setCreatedBy($this->userPrimaryKey($this->em));
        $this->em->persist($newsletter);
        $this->em->flush();

        if ('sending' === $data['status']) {
            $this->planner->plan($newsletter);
        }

        return $this->redirectToRoute('escalated.admin.newsletters.show', ['newsletter' => $newsletter->getId()]);
    }

    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $data = $this->validatePreview($this->payload($request));
        $newsletter = (new Newsletter())
            ->setSubject($data['subject'] ?? '')
            ->setFromEmail($data['from_email'] ?? 'preview@example.test')
            ->setTheme($data['theme'] ?? 'default')
            ->setBodyMarkdown($data['body_markdown'] ?? null)
            ->setTargetListId((int) ($data['target_list_id'] ?? 0));
        $contact = (new Contact())->setEmail('preview@example.test')->setName('Preview User');
        $delivery = (new NewsletterDelivery())
            ->setNewsletterId(0)
            ->setContactId(0)
            ->setEmailAtSend('preview@example.test')
            ->setTrackingToken('preview');

        return new JsonResponse(['html' => $this->newsletterRenderer->render($delivery, $newsletter, $contact)]);
    }

    #[Route('/test', name: 'testSend', methods: ['POST'])]
    public function testSend(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);
        $this->denyAccessUnlessGranted(NewsletterPermissions::SEND);

        $data = $this->validateForm($this->payload($request));
        $user = $this->getUser();
        $email = $user instanceof UserInterface ? $user->getUserIdentifier() : 'tester@example.test';
        $name = \is_object($user) && method_exists($user, 'getName') ? (string) $user->getName() : 'Tester';
        $newsletter = $this->applyNewsletterData(new Newsletter(), $data);
        $contact = (new Contact())->setEmail($email)->setName($name);
        $delivery = (new NewsletterDelivery())
            ->setNewsletterId(0)
            ->setContactId(0)
            ->setEmailAtSend($email)
            ->setTrackingToken(bin2hex(random_bytes(20)))
            ->setIsTest(true);

        $this->newsletterRenderer->render($delivery, $newsletter, $contact);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/{newsletter}', name: 'show', requirements: ['newsletter' => '\d+'], priority: -10, methods: ['GET'])]
    public function show(int $newsletter, Request $request): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $entity = $this->findNewsletter($newsletter);
        $statusFilter = $request->query->get('status');
        $criteria = ['newsletterId' => $newsletter, 'isTest' => false];
        if (is_string($statusFilter) && '' !== $statusFilter) {
            $criteria['status'] = $statusFilter;
        }
        $deliveries = $this->em->getRepository(NewsletterDelivery::class)->findBy($criteria, ['id' => 'DESC'], 100);

        return $this->renderer->render('Escalated/Admin/Newsletters/Show', [
            'newsletter' => $this->newsletterArray($entity, true),
            'deliveries' => $this->paginate(array_map(fn (NewsletterDelivery $d): array => $this->deliveryArray($d), $deliveries), 100),
            'topClicks' => [],
            'tab' => (string) $request->query->get('tab', 'overview'),
        ]);
    }

    #[Route('/{newsletter}/edit', name: 'edit', requirements: ['newsletter' => '\d+'], priority: -10, methods: ['GET'])]
    public function edit(int $newsletter): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $entity = $this->findNewsletter($newsletter);
        if (!in_array($entity->getStatus(), ['draft', 'scheduled'], true)) {
            throw new UnprocessableEntityHttpException('Only drafts and scheduled newsletters can be edited');
        }

        return $this->renderer->render('Escalated/Admin/Newsletters/Edit', array_merge(
            $this->composeProps(),
            ['newsletter' => $this->newsletterArray($entity, false)],
        ));
    }

    #[Route('/{newsletter}', name: 'update', requirements: ['newsletter' => '\d+'], priority: -10, methods: ['PUT'])]
    public function update(int $newsletter, Request $request): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $entity = $this->findNewsletter($newsletter);
        $data = $this->validateForm($this->payload($request));
        $this->guardSendStatus($data['status']);
        $this->applyNewsletterData($entity, $data)->touch();
        $this->em->flush();

        if ('sending' === $data['status']) {
            $this->planner->plan($entity);
        }

        return $this->redirectToRoute('escalated.admin.newsletters.show', ['newsletter' => $entity->getId()]);
    }

    #[Route('/{newsletter}', name: 'destroy', requirements: ['newsletter' => '\d+'], priority: -10, methods: ['DELETE'])]
    public function destroy(int $newsletter): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $entity = $this->findNewsletter($newsletter);
        if ('draft' !== $entity->getStatus()) {
            throw new UnprocessableEntityHttpException('Only drafts can be deleted');
        }
        $this->em->remove($entity);
        $this->em->flush();

        return $this->redirectToRoute('escalated.admin.newsletters.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function composeProps(): array
    {
        $lists = $this->em->getRepository(NewsletterList::class)->findBy([], ['id' => 'DESC']);
        $templates = $this->em->getRepository(NewsletterTemplate::class)->findBy([], ['id' => 'DESC']);

        return [
            'lists' => array_map(fn (NewsletterList $list): array => [
                'id' => $list->getId(),
                'name' => $list->getName(),
                'member_count' => $this->memberCount((int) $list->getId()),
            ], $lists),
            'templates' => array_map(fn (NewsletterTemplate $template): array => [
                'id' => $template->getId(),
                'name' => $template->getName(),
            ], $templates),
            'themes' => $this->themes(),
            'mailConfigured' => $this->mailConfigured(),
            'canSend' => true,
            'defaultFromEmail' => $this->defaultFrom,
            'defaultReplyTo' => $this->defaultReplyTo,
            'defaultTheme' => $this->defaultTheme,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{subject: string, from_email: string, from_name: ?string, reply_to: ?string, target_list_id: int, template_id: ?int, theme: ?string, body_markdown: ?string, status: string, scheduled_at: ?\DateTimeInterface}
     */
    private function validateForm(array $data): array
    {
        $targetListId = $this->requireInt($data, 'target_list_id');
        if (!$this->em->find(NewsletterList::class, $targetListId)) {
            throw new UnprocessableEntityHttpException('Invalid target_list_id.');
        }
        $templateId = null;
        if (isset($data['template_id']) && '' !== $data['template_id'] && null !== $data['template_id']) {
            $templateId = $this->requireInt($data, 'template_id');
            if (!$this->em->find(NewsletterTemplate::class, $templateId)) {
                throw new UnprocessableEntityHttpException('Invalid template_id.');
            }
        }
        $status = (string) ($data['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'scheduled', 'sending'], true)) {
            throw new UnprocessableEntityHttpException('Invalid status.');
        }

        return [
            'subject' => $this->requireString($data, 'subject', 998),
            'from_email' => $this->requireEmail($data, 'from_email'),
            'from_name' => $this->nullableString($data, 'from_name', 255),
            'reply_to' => $this->nullableEmail($data, 'reply_to'),
            'target_list_id' => $targetListId,
            'template_id' => $templateId,
            'theme' => $this->nullableString($data, 'theme', 64),
            'body_markdown' => $this->nullableString($data, 'body_markdown'),
            'status' => $status,
            'scheduled_at' => $this->parseScheduledAt($data['scheduled_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function validatePreview(array $data): array
    {
        if (isset($data['from_email']) && '' !== $data['from_email']) {
            $this->nullableEmail($data, 'from_email');
        }

        return [
            'subject' => $this->nullableString($data, 'subject', 998),
            'body_markdown' => $this->nullableString($data, 'body_markdown'),
            'theme' => $this->nullableString($data, 'theme'),
            'target_list_id' => isset($data['target_list_id']) ? $this->requireInt($data, 'target_list_id') : null,
            'from_email' => $data['from_email'] ?? null,
        ];
    }

    /**
     * @param array{subject: string, from_email: string, from_name: ?string, reply_to: ?string, target_list_id: int, template_id: ?int, theme: ?string, body_markdown: ?string, status: string, scheduled_at: ?\DateTimeInterface} $data
     */
    private function applyNewsletterData(Newsletter $newsletter, array $data): Newsletter
    {
        return $newsletter
            ->setSubject($data['subject'])
            ->setFromEmail($data['from_email'])
            ->setFromName($data['from_name'])
            ->setReplyTo($data['reply_to'])
            ->setTargetListId($data['target_list_id'])
            ->setTemplateId($data['template_id'])
            ->setTheme($data['theme'])
            ->setBodyMarkdown($data['body_markdown'])
            ->setStatus($data['status'])
            ->setScheduledAt($data['scheduled_at']);
    }

    private function parseScheduledAt(mixed $value): ?\DateTimeInterface
    {
        if (null === $value || '' === $value) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            throw new UnprocessableEntityHttpException('Invalid scheduled_at.');
        }
        if ($date <= new \DateTimeImmutable()) {
            throw new UnprocessableEntityHttpException('Invalid scheduled_at.');
        }

        return $date;
    }

    private function guardSendStatus(string $status): void
    {
        if (in_array($status, ['scheduled', 'sending'], true)) {
            $this->denyAccessUnlessGranted(NewsletterPermissions::SEND);
        }
    }

    private function findNewsletter(int $id): Newsletter
    {
        $newsletter = $this->em->find(Newsletter::class, $id);
        if (!$newsletter instanceof Newsletter) {
            throw $this->createNotFoundException('Newsletter not found.');
        }

        return $newsletter;
    }

    /**
     * @return array<string, mixed>
     */
    private function newsletterArray(Newsletter $newsletter, bool $includeList): array
    {
        $row = [
            'id' => $newsletter->getId(),
            'subject' => $newsletter->getSubject(),
            'from_email' => $newsletter->getFromEmail(),
            'from_name' => $newsletter->getFromName(),
            'reply_to' => $newsletter->getReplyTo(),
            'target_list_id' => $newsletter->getTargetListId(),
            'template_id' => $newsletter->getTemplateId(),
            'theme' => $newsletter->getTheme(),
            'body_markdown' => $newsletter->getBodyMarkdown(),
            'status' => $newsletter->getStatus(),
            'scheduled_at' => $this->dateString($newsletter->getScheduledAt()),
            'sent_at' => $this->dateString($newsletter->getSentAt()),
            'summary_total' => $newsletter->getSummaryTotal(),
            'summary_sent' => $newsletter->getSummarySent(),
            'summary_opened' => $newsletter->getSummaryOpened(),
            'summary_clicked' => $newsletter->getSummaryClicked(),
            'summary_bounced' => $newsletter->getSummaryBounced(),
            'summary_complained' => $newsletter->getSummaryComplained(),
        ];

        if ($includeList) {
            $list = $this->em->find(NewsletterList::class, $newsletter->getTargetListId());
            $row['target_list'] = $list instanceof NewsletterList ? [
                'id' => $list->getId(),
                'name' => $list->getName(),
                'kind' => $list->getKind(),
            ] : null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryArray(NewsletterDelivery $delivery): array
    {
        $contact = $this->em->find(Contact::class, $delivery->getContactId());

        return [
            'id' => $delivery->getId(),
            'newsletter_id' => $delivery->getNewsletterId(),
            'contact_id' => $delivery->getContactId(),
            'email_at_send' => $delivery->getEmailAtSend(),
            'status' => $delivery->getStatus(),
            'tracking_token' => $delivery->getTrackingToken(),
            'sent_at' => $this->dateString($delivery->getSentAt()),
            'opened_at' => $this->dateString($delivery->getOpenedAt()),
            'last_clicked_at' => $this->dateString($delivery->getLastClickedAt()),
            'clicks_count' => $delivery->getClicksCount(),
            'bounce_reason' => $delivery->getBounceReason(),
            'failure_reason' => $delivery->getFailureReason(),
            'attempt_count' => $delivery->getAttemptCount(),
            'contact' => $contact instanceof Contact ? [
                'id' => $contact->getId(),
                'name' => $contact->getName(),
                'email' => $contact->getEmail(),
            ] : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $data
     *
     * @return array<string, mixed>
     */
    private function paginate(array $data, int $perPage): array
    {
        return [
            'data' => $data,
            'per_page' => $perPage,
            'total' => count($data),
        ];
    }

    private function memberCount(int $listId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(\Escalated\Symfony\Entity\Newsletter\NewsletterListMember::class, 'm')
            ->where('m.listId = :listId')
            ->setParameter('listId', $listId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, string>
     */
    private function themes(): array
    {
        $themes = [];
        foreach (glob(__DIR__.'/../../../../templates/newsletter_themes/*.html.twig') ?: [] as $path) {
            $themes[] = basename($path, '.html.twig');
        }

        return array_values(array_unique($themes)) ?: ['default', 'branded'];
    }

    private function mailConfigured(): bool
    {
        $dsn = $_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? null;

        return \is_string($dsn) && '' !== $dsn && 'null://null' !== $dsn;
    }
}
