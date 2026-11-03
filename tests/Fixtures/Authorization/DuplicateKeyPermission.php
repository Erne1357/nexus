<?php

namespace Tests\Fixtures\Authorization;

use App\Modules\Core\Authorization\Permission;

enum DuplicateKeyPermission: string implements Permission
{
    case Clash = 'fake.thing.view_campus';

    public function label(): string
    {
        return $this->value;
    }

    public function dimensions(): array
    {
        return [];
    }

    public function isPersonal(): bool
    {
        return false;
    }
}
