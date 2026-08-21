<?php

namespace BoldMinded\Dexter\Jobs;

use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\Dexter\Service\IndexableUser;
use Statamic\Facades\User;

class IndexUserJob extends AbstractIndexJob
{
    protected function resolve(): ?IndexableInterface
    {
        $user = User::find($this->payload['id'] ?? null);

        return $user ? new IndexableUser($user) : null;
    }
}
