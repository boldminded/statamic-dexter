<?php

namespace BoldMinded\Dexter\Service\Indexer;

use Statamic\Contracts\Query\Builder;
use Statamic\Facades\User;

/**
 * Users. The Statamic equivalent of EE's MemberRole and Craft's UserGroup.
 *
 * Statamic has a single user store rather than one source per group, so the
 * handle is unused and this type always resolves. Users carry no locale.
 */
class UserType implements ContentType
{
    public const PREFIX = 'users';

    public static function getPrefix(): string
    {
        return self::PREFIX;
    }

    public function exists(string $handle): bool
    {
        return true;
    }

    public function getName(string $handle): string
    {
        return 'Users';
    }

    public function query(string $handle, ?string $site = null): ?Builder
    {
        return User::query();
    }

    public function isLocalized(): bool
    {
        return false;
    }
}
