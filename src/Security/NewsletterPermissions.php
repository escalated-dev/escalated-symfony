<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

final class NewsletterPermissions
{
    public const MANAGE = 'newsletters.manage';
    public const SEND = 'newsletters.send';

    /**
     * @return array<string, array{name: string, group: string, description: string}>
     */
    public static function seeds(): array
    {
        return [
            self::MANAGE => [
                'name' => 'Manage newsletters',
                'group' => 'Newsletters',
                'description' => 'Create, edit, delete drafts and lists/templates; send test emails.',
            ],
            self::SEND => [
                'name' => 'Send newsletters',
                'group' => 'Newsletters',
                'description' => 'Schedule or send newsletters now.',
            ],
        ];
    }
}
