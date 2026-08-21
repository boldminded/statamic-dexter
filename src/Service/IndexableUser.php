<?php

namespace BoldMinded\Dexter\Service;

use Statamic\Contracts\Auth\User;
use Statamic\Fields\Blueprint;

class IndexableUser extends AbstractIndexable
{
    public function __construct(private User $user)
    {
    }

    public function getScope(): string
    {
        return 'users';
    }

    public function getTypes(): array
    {
        return ['users'];
    }

    public function getEntity(): User
    {
        return $this->user;
    }

    public function getId(): int|string
    {
        return $this->user->id();
    }

    public function getUniqueId(): string
    {
        return 'user_' . $this->user->id();
    }

    protected function rawData(): array
    {
        return $this->user->data()->all();
    }

    protected function blueprint(): ?Blueprint
    {
        return $this->user->blueprint();
    }

    /**
     * Deliberately excludes password hashes, tokens, and preferences. Only
     * fields a site would reasonably expose in search results are indexed.
     */
    protected function systemValues(): array
    {
        return array_filter([
            'id' => $this->user->id(),
            'reference' => $this->user->reference(),
            'name' => $this->user->name(),
            'email' => $this->user->email(),
            'groups' => $this->user->groups()->map->handle()->values()->all(),
            'roles' => $this->user->roles()->map->handle()->values()->all(),
        ], fn ($value) => $value !== null && $value !== []);
    }

    public function getDefaultFields(): array
    {
        return ['id', 'reference', 'name', 'email'];
    }

    /**
     * Never index credential material, even if a blueprint exposes it.
     */
    public function getValues(): array
    {
        $values = parent::getValues();

        unset(
            $values['password'],
            $values['password_hash'],
            $values['remember_token'],
            $values['api_token'],
            $values['preferences'],
        );

        return $values;
    }
}
