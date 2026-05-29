# Task: support UUID/string host-app user keys (escalated-symfony)

The Symfony bundle assumes the host app's user id is an integer: Doctrine
entity columns are `Types::INTEGER`, controllers `(int)`-cast
`getUserIdentifier()`, and `EnsureAgentVoter` returns `null` for any
non-numeric id (which **blocks all agent access** for UUID-keyed hosts). Make
the bundle work with integer **and** UUID/string host user keys, **defaulting
to the current integer behavior** so existing installs are unaffected.

There are TWO parts: a runtime/code fix (high value, unblocks UUID hosts) and a
schema fix (Doctrine column type).

## Part A — Doctrine custom type for host-user-id columns

Create `src/Doctrine/UserIdType.php`:

```php
<?php

namespace Escalated\Bundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Doctrine type for columns that store a *host-app* user id. The host user
 * primary key may be an integer (default) or a UUID/string. The SQL column type
 * is chosen from ESCALATED_USER_KEY_TYPE (int|bigint|uuid|string), defaulting to
 * integer so existing integer-keyed installs get an identical schema.
 */
final class UserIdType extends Type
{
    public const NAME = 'escalated_user_id';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return match (self::keyType()) {
            'uuid', 'string' => $platform->getStringTypeDeclarationSQL(['length' => 255]),
            'bigint' => $platform->getBigIntTypeDeclarationSQL($column),
            default => $platform->getIntegerTypeDeclarationSQL($column),
        };
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): int|string|null
    {
        if ($value === null) {
            return null;
        }

        // Integer-keyed hosts get ints back (unchanged behavior); uuid/string
        // hosts get the raw string.
        $type = self::keyType();
        if (($type === 'int' || $type === 'bigint') && is_numeric($value)) {
            return (int) $value;
        }

        return (string) $value;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): int|string|null
    {
        return $value;
    }

    private static function keyType(): string
    {
        $raw = strtolower(trim((string) ($_ENV['ESCALATED_USER_KEY_TYPE'] ?? getenv('ESCALATED_USER_KEY_TYPE') ?: 'int')));

        return match ($raw) {
            'bigint' => 'bigint',
            'uuid' => 'uuid',
            'string', 'varchar' => 'string',
            default => 'int',
        };
    }
}
```

Register the type so Doctrine knows it everywhere (including tests + the schema
tool). Do this via the bundle's DI extension implementing
`PrependExtensionInterface` (the extension lives under `src/DependencyInjection`):

```php
// in the Extension's prepend(ContainerBuilder $container)
$container->prependExtensionConfig('doctrine', [
    'dbal' => [
        'types' => [
            \Escalated\Bundle\Doctrine\UserIdType::NAME => \Escalated\Bundle\Doctrine\UserIdType::class,
        ],
    ],
]);
```

(If the extension doesn't yet implement `PrependExtensionInterface`, add it.
Match the real namespace used in this repo — check an existing
`src/DependencyInjection/*Extension.php` for the bundle namespace; it may be
`Escalated\Bundle\...` or similar. Use the repo's actual namespace, not a
guess.)

## Part B — Entity columns

In each entity below, change the host-user-id column attribute from
`#[ORM\Column(type: Types::INTEGER, ...)]` to
`#[ORM\Column(type: \Escalated\Bundle\Doctrine\UserIdType::NAME, ...)]`
(keep `nullable: true` where present), and widen the PHP property + its
getter/setter signatures from `int`/`?int` to `int|string`/`int|string|null`.
ONLY host-user-id columns; leave Escalated's own integer ids alone.

- `src/Entity/Ticket.php` — `requesterId`, `assignedTo`, `snoozedBy`
- `src/Entity/Reply.php` — `authorId`
- `src/Entity/TicketActivity.php` — `causerId`
- `src/Entity/AgentProfile.php` — `userId`
- `src/Entity/Contact.php` — `userId`
- `src/Entity/AgentSkill.php` — `userId`
- `src/Entity/TwoFactor.php` — `userId`
- `src/Entity/SavedView.php` — `userId`

Update setter signatures accordingly (e.g. `setRequesterId(int|string|null $requesterId)`).

## Part C — Remove (int) casts on host user ids (CRITICAL — unblocks UUID hosts)

Replace `(int) $this->getUser()->getUserIdentifier()` /
`(int) $user->getUserIdentifier()` / `(int) $request->...->get('agent_id')`
with the raw value (the identifier string, or the request value) at ALL these
sites (from audit — grep `\(int\) ` across `src/Controller` to confirm and
catch any missed):

- `src/Controller/Admin/PublicTicketsSettingsController.php` (~59, ~94)
- `src/Controller/Admin/TicketController.php` (~82 agent_id, ~86 identifier)
- `src/Controller/Agent/ChatController.php` (~59, ~83, ~104)
- `src/Controller/Agent/DashboardController.php` (~30)
- `src/Controller/Agent/SavedViewController.php` (~32,57,87,134,152,176)
- `src/Controller/Agent/TicketController.php` (~104,130,157,181)
- `src/Controller/Agent/TicketSnoozeController.php` (~47,77)
- `src/Controller/Agent/TicketSplitController.php` (~53)
- `src/Controller/Api/TicketController.php` (~96)
- `src/Controller/Customer/TicketController.php` (~68 requester_id)

`getUserIdentifier()` returns a string; for integer hosts that's the numeric id
as a string, which Doctrine's `escalated_user_id` type will store/compare fine.
Do NOT re-cast to int.

## Part D — EnsureAgentVoter (CRITICAL)

`src/Security/EnsureAgentVoter.php` (~57-67) `resolveUserPrimaryKey()` does
`return is_numeric($id) ? (int) $id : null;` — returning `null` for UUIDs
**denies all agent access**. Change it to return the identifier as-is
(`int|string`), without the `is_numeric` gate, so UUID identifiers resolve. Keep
the method working for integer ids.

## Part E — Repository signatures

Widen `int` → `int|string` on user-id params in:
- `src/Repository/TicketRepository.php` — `findAssignedTo`, `findByRequester`, `countOpenByAgent`
- `src/Repository/SavedViewRepository.php` — `findForUser`, `findOwnedBy`, `findDefaultForUser`

## Part F — Test

Add `tests/Doctrine/UserIdTypeTest.php` (match the repo's PHPUnit style):
- default env → `getSQLDeclaration` yields an INTEGER declaration; `convertToPHPValue('5', ...)` returns int `5`.
- with `$_ENV['ESCALATED_USER_KEY_TYPE']='uuid'` → declaration is a VARCHAR/string; `convertToPHPValue('abc-1', ...)` returns string `'abc-1'`. Save/restore env.

(Use an in-memory/SQLite platform or mock `AbstractPlatform` as the existing
tests do.)

## Part G — Build, test, cs, commit

NOTE: the default branch here is `master`. Run from repo root, make green:

```
composer install   # if needed
vendor/bin/phpunit
vendor/bin/php-cs-fixer fix --dry-run --diff   # fix style on touched files if it flags them
```

Then commit (do NOT push):

```
git add -A
git commit -m "fix(users): support UUID/string host user keys"
```

Do NOT delete UUID_FIX_SPEC.md. Report every file changed and the final
test/cs status; flag any pre-existing failures vs ones you introduced.
