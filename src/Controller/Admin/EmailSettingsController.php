<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Escalated/Admin/Settings/EmailSettings, as escalated-laravel's
 * Admin\EmailSettingsController: the support addresses and the default reply
 * address, kept in the settings store under `email_addresses` (a JSON list)
 * and `default_reply_address`. Like Laravel, they are stored only; outbound
 * mail keeps its configured sender.
 */
#[Route('/admin/settings/email', name: 'escalated.admin.settings.email')]
class EmailSettingsController extends UiController
{
    public const DKIM_STATUSES = ['verified', 'pending', 'failed', 'unknown'];

    public function __construct(
        private readonly UiRendererInterface $renderer,
        private readonly SettingsService $settings,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Settings/EmailSettings', [
            'addresses' => $this->addresses(),
            'defaultReplyAddress' => $this->settings->get('default_reply_address', '') ?? '',
        ]);
    }

    #[Route('', name: '.update', methods: ['POST', 'PUT'])]
    public function update(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $payload = $this->payload($request);
        $errors = [];

        $addresses = $payload['addresses'] ?? null;
        if (!\is_array($addresses) || !array_is_list($addresses)) {
            $errors['addresses'] = null === $addresses ? 'The addresses field is required.' : 'The addresses field must be an array.';
            $addresses = [];
        }

        $normalized = [];
        foreach ($addresses as $i => $address) {
            if (!\is_array($address)) {
                $errors["addresses.$i.email"] = sprintf('The addresses.%d.email field is required.', $i);
                continue;
            }

            $email = $address['email'] ?? null;
            if (!\is_string($email) || '' === trim($email)) {
                $errors["addresses.$i.email"] = sprintf('The addresses.%d.email field is required.', $i);
            } elseif (false === filter_var(trim($email), \FILTER_VALIDATE_EMAIL)) {
                $errors["addresses.$i.email"] = sprintf('The addresses.%d.email field must be a valid email address.', $i);
            } elseif (mb_strlen($email) > 255) {
                $errors["addresses.$i.email"] = sprintf('The addresses.%d.email field must not be greater than 255 characters.', $i);
            }

            $name = $address['display_name'] ?? null;
            if (null !== $name && !\is_string($name)) {
                $errors["addresses.$i.display_name"] = sprintf('The addresses.%d.display_name field must be a string.', $i);
            } elseif (\is_string($name) && mb_strlen($name) > 255) {
                $errors["addresses.$i.display_name"] = sprintf('The addresses.%d.display_name field must not be greater than 255 characters.', $i);
            }

            $department = $address['department_id'] ?? null;
            if ('' === $department) {
                $department = null;
            }
            if (null !== $department && (\is_bool($department) || false === filter_var($department, \FILTER_VALIDATE_INT))) {
                $errors["addresses.$i.department_id"] = sprintf('The addresses.%d.department_id field must be an integer.', $i);
            }

            $dkim = $address['dkim_status'] ?? null;
            if ('' === $dkim) {
                $dkim = null;
            }
            if (null !== $dkim && (!\is_string($dkim) || !\in_array($dkim, self::DKIM_STATUSES, true))) {
                $errors["addresses.$i.dkim_status"] = sprintf('The selected addresses.%d.dkim_status is invalid.', $i);
            }

            $normalized[] = [
                'email' => \is_string($email) ? trim($email) : '',
                'display_name' => \is_string($name) && '' !== $name ? $name : null,
                'department_id' => null === $department ? null : (int) $department,
                'dkim_status' => \is_string($dkim) ? $dkim : 'unknown',
            ];
        }

        $reply = $payload['default_reply_address'] ?? null;
        if ('' === $reply) {
            $reply = null;
        }
        if (null !== $reply) {
            if (!\is_string($reply) || false === filter_var(trim($reply), \FILTER_VALIDATE_EMAIL)) {
                $errors['default_reply_address'] = 'The default reply address field must be a valid email address.';
            } elseif (mb_strlen($reply) > 255) {
                $errors['default_reply_address'] = 'The default reply address field must not be greater than 255 characters.';
            }
        }

        if ([] !== $errors) {
            return $this->invalid($request, $errors, 'escalated.admin.settings.email');
        }

        $this->settings->set('email_addresses', (string) json_encode($normalized, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        if (\is_string($reply)) {
            $this->settings->set('default_reply_address', trim($reply));
        }

        $this->addFlash('success', 'Email settings updated.');

        return $this->redirectToRoute('escalated.admin.settings.email', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return list<array{email: string, display_name: ?string, department_id: ?int, dkim_status: string}>
     */
    private function addresses(): array
    {
        $decoded = json_decode($this->settings->get('email_addresses', '[]') ?? '[]', true);
        if (!\is_array($decoded)) {
            return [];
        }

        $addresses = [];
        foreach ($decoded as $row) {
            if (!\is_array($row) || !\is_string($row['email'] ?? null)) {
                continue;
            }
            $addresses[] = [
                'email' => $row['email'],
                'display_name' => \is_string($row['display_name'] ?? null) ? $row['display_name'] : null,
                'department_id' => is_numeric($row['department_id'] ?? null) ? (int) $row['department_id'] : null,
                'dkim_status' => \is_string($row['dkim_status'] ?? null) ? $row['dkim_status'] : 'unknown',
            ];
        }

        return $addresses;
    }
}
