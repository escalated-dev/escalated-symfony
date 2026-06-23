<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Newsletter\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Newsletter\NewsletterList;
use Escalated\Symfony\Entity\Newsletter\NewsletterListMember;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\NewsletterPermissions;
use Escalated\Symfony\Service\Newsletter\ContactSegmentResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/newsletters/lists', name: 'escalated.admin.newsletters.lists.')]
class NewsletterListController extends NewsletterAdminController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly ContactSegmentResolver $segments,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $lists = $this->em->getRepository(NewsletterList::class)->findBy([], ['id' => 'DESC']);

        return $this->renderer->render('Escalated/Admin/Newsletters/Lists/Index', [
            'lists' => array_map(fn (NewsletterList $list): array => $this->listArray($list), $lists),
        ]);
    }

    #[Route('/new', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        return $this->renderer->render('Escalated/Admin/Newsletters/Lists/Create', []);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $data = $this->validateCreate($this->payload($request));
        $list = (new NewsletterList())
            ->setName($data['name'])
            ->setDescription($data['description'])
            ->setKind($data['kind'])
            ->setFilterJson($data['filter_json'])
            ->setCreatedBy($this->userPrimaryKey($this->em));
        $this->em->persist($list);
        $this->em->flush();

        return $this->redirectToRoute('escalated.admin.newsletters.lists.show', ['list' => $list->getId()]);
    }

    #[Route('/{list}', name: 'show', requirements: ['list' => '\d+'], methods: ['GET'])]
    public function show(int $list): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $entity = $this->findList($list);
        $members = $this->em->getRepository(NewsletterListMember::class)->findBy(['listId' => $list], ['id' => 'DESC'], 100);
        $matchCount = 'dynamic' === $entity->getKind()
            ? $this->segments->countMatches($entity->getFilterJson() ?? ['rules' => []])
            : 0;

        return $this->renderer->render('Escalated/Admin/Newsletters/Lists/Show', [
            'list' => $this->listArray($entity),
            'members' => [
                'data' => array_map(fn (NewsletterListMember $member): array => $this->memberArray($member), $members),
                'per_page' => 100,
                'total' => count($members),
            ],
            'matchCount' => $matchCount,
        ]);
    }

    #[Route('/{list}', name: 'update', requirements: ['list' => '\d+'], methods: ['PUT'])]
    public function update(int $list, Request $request): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $entity = $this->findList($list);
        $data = $this->validateUpdate($this->payload($request));
        if (array_key_exists('name', $data)) {
            $entity->setName($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $entity->setDescription($data['description']);
        }
        if (array_key_exists('filter_json', $data)) {
            $entity->setFilterJson($data['filter_json']);
        }
        $entity->touch();
        $this->em->flush();

        return $this->redirectToRoute('escalated.admin.newsletters.lists.show', ['list' => $entity->getId()]);
    }

    #[Route('/{list}', name: 'destroy', requirements: ['list' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $list): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $this->em->remove($this->findList($list));
        $this->em->flush();

        return $this->redirectToRoute('escalated.admin.newsletters.lists.index');
    }

    #[Route('/{list}/members', name: 'members.add', requirements: ['list' => '\d+'], methods: ['POST'])]
    public function addMember(int $list, Request $request): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $entity = $this->findStaticList($list);
        $contactId = $this->requireInt($this->payload($request), 'contact_id');
        if (!$this->em->find(Contact::class, $contactId)) {
            throw new UnprocessableEntityHttpException('Invalid contact_id.');
        }
        $this->firstOrCreateMember((int) $entity->getId(), $contactId);

        return $this->redirectToRoute('escalated.admin.newsletters.lists.show', ['list' => $entity->getId()]);
    }

    #[Route('/{list}/members/{contactId}', name: 'members.remove', requirements: ['list' => '\d+', 'contactId' => '\d+'], methods: ['DELETE'])]
    public function removeMember(int $list, int $contactId): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $entity = $this->findStaticList($list);
        $member = $this->em->getRepository(NewsletterListMember::class)->findOneBy([
            'listId' => $list,
            'contactId' => $contactId,
        ]);
        if ($member instanceof NewsletterListMember) {
            $this->em->remove($member);
            $this->em->flush();
        }

        return $this->redirectToRoute('escalated.admin.newsletters.lists.show', ['list' => $entity->getId()]);
    }

    #[Route('/{list}/import', name: 'import', requirements: ['list' => '\d+'], methods: ['POST'])]
    public function importCsv(int $list, Request $request): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $entity = $this->findStaticList($list);
        $file = $request->files->get('file');
        if (!$file || !method_exists($file, 'getRealPath')) {
            throw new UnprocessableEntityHttpException('Invalid file.');
        }
        $path = $file->getRealPath();
        if (!\is_string($path) || '' === $path) {
            throw new UnprocessableEntityHttpException('Invalid file.');
        }

        $imported = 0;
        $handle = fopen($path, 'r');
        if (false !== $handle) {
            while (false !== ($row = fgetcsv($handle))) {
                $email = filter_var(trim((string) ($row[0] ?? '')), FILTER_VALIDATE_EMAIL);
                if (!$email) {
                    continue;
                }
                $contact = $this->findOrCreateContact($email);
                $this->firstOrCreateMember((int) $entity->getId(), (int) $contact->getId());
                ++$imported;
            }
            fclose($handle);
        }

        $this->addFlash('status', sprintf('Imported %d contacts', $imported));

        return $this->redirectToRoute('escalated.admin.newsletters.lists.show', ['list' => $entity->getId()]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{name: string, description: ?string, kind: string, filter_json: ?array}
     */
    private function validateCreate(array $data): array
    {
        $kind = (string) ($data['kind'] ?? '');
        if (!in_array($kind, ['static', 'dynamic'], true)) {
            throw new UnprocessableEntityHttpException('Invalid kind.');
        }

        return [
            'name' => $this->requireString($data, 'name', 255),
            'description' => $this->nullableString($data, 'description'),
            'kind' => $kind,
            'filter_json' => isset($data['filter_json']) && \is_array($data['filter_json']) ? $data['filter_json'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function validateUpdate(array $data): array
    {
        $out = [];
        if (array_key_exists('name', $data)) {
            $out['name'] = $this->requireString($data, 'name', 255);
        }
        if (array_key_exists('description', $data)) {
            $out['description'] = $this->nullableString($data, 'description');
        }
        if (array_key_exists('filter_json', $data)) {
            $out['filter_json'] = \is_array($data['filter_json']) ? $data['filter_json'] : null;
        }

        return $out;
    }

    private function findList(int $id): NewsletterList
    {
        $list = $this->em->find(NewsletterList::class, $id);
        if (!$list instanceof NewsletterList) {
            throw $this->createNotFoundException('Newsletter list not found.');
        }

        return $list;
    }

    private function findStaticList(int $id): NewsletterList
    {
        $list = $this->findList($id);
        if ('static' !== $list->getKind()) {
            throw new UnprocessableEntityHttpException('Dynamic lists are filter-driven');
        }

        return $list;
    }

    private function firstOrCreateMember(int $listId, int $contactId): NewsletterListMember
    {
        $member = $this->em->getRepository(NewsletterListMember::class)->findOneBy([
            'listId' => $listId,
            'contactId' => $contactId,
        ]);
        if ($member instanceof NewsletterListMember) {
            return $member;
        }

        $member = (new NewsletterListMember())
            ->setListId($listId)
            ->setContactId($contactId)
            ->setAddedBy($this->userPrimaryKey($this->em));
        $this->em->persist($member);
        $this->em->flush();

        return $member;
    }

    private function findOrCreateContact(string $email): Contact
    {
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => Contact::normalizeEmail($email)]);
        if ($contact instanceof Contact) {
            return $contact;
        }

        $contact = (new Contact())->setEmail($email);
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    /**
     * @return array<string, mixed>
     */
    private function listArray(NewsletterList $list): array
    {
        $id = (int) $list->getId();

        return [
            'id' => $list->getId(),
            'name' => $list->getName(),
            'description' => $list->getDescription(),
            'kind' => $list->getKind(),
            'filter_json' => $list->getFilterJson(),
            'created_by' => $list->getCreatedBy(),
            'member_count' => $this->memberCount($id),
            'opted_out_count' => $this->optedOutCount($id),
            'created_at' => $this->dateString($list->getCreatedAt()),
            'updated_at' => $this->dateString($list->getUpdatedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberArray(NewsletterListMember $member): array
    {
        $contact = $this->em->find(Contact::class, $member->getContactId());

        return [
            'id' => $member->getId(),
            'list_id' => $member->getListId(),
            'contact_id' => $member->getContactId(),
            'added_at' => $this->dateString($member->getAddedAt()),
            'added_by' => $member->getAddedBy(),
            'contact' => $contact instanceof Contact ? [
                'id' => $contact->getId(),
                'name' => $contact->getName(),
                'email' => $contact->getEmail(),
            ] : null,
        ];
    }

    private function memberCount(int $listId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(NewsletterListMember::class, 'm')
            ->where('m.listId = :listId')
            ->setParameter('listId', $listId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function optedOutCount(int $listId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(NewsletterListMember::class, 'm')
            ->join(Contact::class, 'c', 'WITH', 'c.id = m.contactId')
            ->where('m.listId = :listId')
            ->andWhere('c.marketingOptOutAt IS NOT NULL')
            ->setParameter('listId', $listId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
