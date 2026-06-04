<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Newsletter\Admin;

use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\NewsletterPermissions;
use Escalated\Symfony\Service\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/newsletters/settings', name: 'escalated.admin.newsletters.settings')]
class NewsletterSettingsController extends NewsletterAdminController
{
    public const KEYS = [
        'default_from' => 'string',
        'default_reply_to' => 'string',
        'default_theme' => 'string',
        'rate_limit_per_minute' => 'number',
        'batch_size' => 'number',
        'tracking_enabled' => 'boolean',
    ];

    /**
     * @param array<string, mixed> $defaults
     */
    public function __construct(
        private readonly UiRendererInterface $renderer,
        private readonly SettingsService $settings,
        private readonly array $defaults = [],
    ) {
    }

    #[Route('', name: '.show', methods: ['GET'])]
    public function show(): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $settings = [];
        foreach (self::KEYS as $key => $_type) {
            $settings[$key] = $this->settings->get('newsletter.'.$key, $this->default($key));
        }

        return $this->renderer->render('Escalated/Admin/Newsletters/Settings', [
            'settings' => $settings,
            'themes' => ['default', 'branded'],
        ]);
    }

    #[Route('', name: '.update', methods: ['PUT'])]
    public function update(Request $request): Response
    {
        $this->denyAccessUnlessGranted(NewsletterPermissions::MANAGE);

        $data = $this->validateForm($this->payload($request));
        foreach (self::KEYS as $key => $_type) {
            $value = $data[$key] ?? null;
            $this->settings->set(
                'newsletter.'.$key,
                \is_bool($value) ? (string) (int) $value : (string) ($value ?? ''),
            );
        }

        return $this->redirectToRoute('escalated.admin.newsletters.settings.show');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function validateForm(array $data): array
    {
        $rateLimit = $this->requireInt($data, 'rate_limit_per_minute');
        $batchSize = $this->requireInt($data, 'batch_size');
        if ($rateLimit < 1 || $rateLimit > 10000 || $batchSize < 1 || $batchSize > 1000) {
            throw new UnprocessableEntityHttpException('Invalid newsletter settings.');
        }

        return [
            'default_from' => $this->nullableEmail($data, 'default_from'),
            'default_reply_to' => $this->nullableEmail($data, 'default_reply_to'),
            'default_theme' => $this->requireString($data, 'default_theme', 64),
            'rate_limit_per_minute' => $rateLimit,
            'batch_size' => $batchSize,
            'tracking_enabled' => filter_var($data['tracking_enabled'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
        ];
    }

    private function default(string $key): ?string
    {
        $value = $this->defaults[$key] ?? null;

        return null === $value ? null : (string) $value;
    }
}
