<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Newsletter\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

abstract class NewsletterAdminController extends AbstractController
{
    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
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

    protected function requireString(array $data, string $key, int $max): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || '' === trim($value) || mb_strlen($value) > $max) {
            throw new UnprocessableEntityHttpException(sprintf('Invalid %s.', $key));
        }

        return $value;
    }

    protected function nullableString(array $data, string $key, ?int $max = null): ?string
    {
        $value = $data[$key] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value) || (null !== $max && mb_strlen($value) > $max)) {
            throw new UnprocessableEntityHttpException(sprintf('Invalid %s.', $key));
        }

        return $value;
    }

    protected function requireEmail(array $data, string $key, int $max = 320): string
    {
        $value = $this->requireString($data, $key, $max);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new UnprocessableEntityHttpException(sprintf('Invalid %s.', $key));
        }

        return $value;
    }

    protected function nullableEmail(array $data, string $key, int $max = 320): ?string
    {
        $value = $this->nullableString($data, $key, $max);
        if (null !== $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new UnprocessableEntityHttpException(sprintf('Invalid %s.', $key));
        }

        return $value;
    }

    protected function requireInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_numeric($value)) {
            throw new UnprocessableEntityHttpException(sprintf('Invalid %s.', $key));
        }

        return (int) $value;
    }

    protected function userPrimaryKey(EntityManagerInterface $em): int|string|null
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface || !$em->getMetadataFactory()->hasMetadataFor($user::class)) {
            return null;
        }

        $idValues = $em->getClassMetadata($user::class)->getIdentifierValues($user);
        $id = reset($idValues);

        if (false === $id || null === $id) {
            return null;
        }

        return is_numeric($id) ? (int) $id : (string) $id;
    }

    protected function dateString(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DateTimeInterface::ATOM);
    }
}
