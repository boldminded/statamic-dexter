<?php

namespace BoldMinded\Dexter;

use Statamic\Facades\Permission as StatamicPermission;

/**
 * Two permissions, deliberately separated.
 *
 * Seeing which indexes exist and whether the provider is reachable is
 * diagnostic and safe to grant widely. Clearing or deleting an index destroys
 * data and can take a large site's search offline until a reindex finishes, so
 * it is a child permission an admin has to grant on purpose.
 */
class Permissions
{
    public const VIEW = 'view dexter';
    public const MANAGE = 'manage dexter indexes';

    public static function boot(): void
    {
        StatamicPermission::extend(function () {
            StatamicPermission::register(self::VIEW, function ($permission) {
                $permission
                    ->label(__('dexter::permissions.view'))
                    ->children([
                        StatamicPermission::make(self::MANAGE)
                            ->label(__('dexter::permissions.manage')),
                    ]);
            })->group('dexter');
        });
    }
}
