<?php

namespace Tests\Fixtures\Authorization;

use App\Modules\Core\Authorization\Permission;

enum MalformedKeyPermission: string implements Permission
{
    case Bad = 'viewthing';

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
