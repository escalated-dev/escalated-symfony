<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Newsletter\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Newsletter\NewsletterTemplate;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\NewsletterPermissions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/newsletters/templates', name: 'escalated.admin.newsletters.templates.')]
class NewsletterTemplateController extends NewsletterAdminController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $templates = $this->em->getRepository(NewsletterTemplate::class)->findBy([], ['id' => 'DESC']);

        return $this->renderer->render('Escalated/Admin/Newsletters/Templates/Index', [
            'templates' => array_map(fn (NewsletterTemplate $template): array => $this->templateArray($template), $templates),
        ]);
    }

    #[Route('/new', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        return $this->renderer->render('Escalated/Admin/Newsletters/Templates/Create', [
            'themes' => $this->themes(),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $data = $this->validateForm($this->payload($request));
        $template = (new NewsletterTemplate())
            ->setName($data['name'])
            ->setTheme($data['theme'])
            ->setSubjectTemplate($data['subject_template'])
            ->setBodyMarkdown($data['body_markdown'])
            ->setMergeFieldsSchema($data['merge_fields_schema'])
            ->setCreatedBy($this->userPrimaryKey($this->em));
        $this->em->persist($template);
        $this->em->flush();

        return $this->redirectToRoute('escalated.admin.newsletters.templates.index');
    }

    #[Route('/{template}', name: 'show', requirements: ['template' => '\d+'], methods: ['GET'])]
    public function show(int $template): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        return $this->renderer->render('Escalated/Admin/Newsletters/Templates/Show', [
            'template' => $this->templateArray($this->findTemplate($template)),
            'themes' => $this->themes(),
            'isNew' => false,
        ]);
    }

    #[Route('/{template}', name: 'update', requirements: ['template' => '\d+'], methods: ['PUT'])]
    public function update(int $template, Request $request): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $entity = $this->findTemplate($template);
        $data = $this->validateForm($this->payload($request));
        $entity
            ->setName($data['name'])
            ->setTheme($data['theme'])
            ->setSubjectTemplate($data['subject_template'])
            ->setBodyMarkdown($data['body_markdown'])
            ->setMergeFieldsSchema($data['merge_fields_schema'])
            ->touch();
        $this->em->flush();

        return $this->redirectToRoute('escalated.admin.newsletters.templates.show', ['template' => $entity->getId()]);
    }

    #[Route('/{template}', name: 'destroy', requirements: ['template' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $template): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $this->em->remove($this->findTemplate($template));
        $this->em->flush();

        return $this->redirectToRoute('escalated.admin.newsletters.templates.index');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{name: string, theme: string, subject_template: ?string, body_markdown: string, merge_fields_schema: ?array}
     */
    private function validateForm(array $data): array
    {
        return [
            'name' => $this->requireString($data, 'name', 255),
            'theme' => $this->requireString($data, 'theme', 64),
            'subject_template' => $this->nullableString($data, 'subject_template', 998),
            'body_markdown' => $this->requireString($data, 'body_markdown', PHP_INT_MAX),
            'merge_fields_schema' => isset($data['merge_fields_schema']) && \is_array($data['merge_fields_schema'])
                ? $data['merge_fields_schema']
                : null,
        ];
    }

    private function findTemplate(int $id): NewsletterTemplate
    {
        $template = $this->em->find(NewsletterTemplate::class, $id);
        if (!$template instanceof NewsletterTemplate) {
            throw $this->createNotFoundException('Newsletter template not found.');
        }

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    private function templateArray(NewsletterTemplate $template): array
    {
        return [
            'id' => $template->getId(),
            'name' => $template->getName(),
            'theme' => $template->getTheme(),
            'subject_template' => $template->getSubjectTemplate(),
            'body_markdown' => $template->getBodyMarkdown(),
            'merge_fields_schema' => $template->getMergeFieldsSchema(),
            'created_by' => $template->getCreatedBy(),
            'created_at' => $this->dateString($template->getCreatedAt()),
            'updated_at' => $this->dateString($template->getUpdatedAt()),
        ];
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
}
