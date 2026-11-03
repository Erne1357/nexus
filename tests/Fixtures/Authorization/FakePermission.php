<?php

namespace Tests\Fixtures\Authorization;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;

/**
 * Permisos de prueba: un caso por combinación de dimensiones y uno personal.
 */
enum FakePermission: string implements Permission
{
    case ViewCampus = 'fake.thing.view_campus';
    case ViewUnit = 'fake.thing.view_unit';
    case ViewCareer = 'fake.thing.view_career';
    case ViewCampusUnit = 'fake.thing.view_campus_unit';
    case ViewCampusCareer = 'fake.thing.view_campus_career';
    case ViewCampusUnitCareer = 'fake.thing.view_campus_unit_career';
    case ViewAny = 'fake.thing.view_any';
    case ViewOwn = 'fake.thing.view_own';

    public function label(): string
    {
        return $this->value;
    }

    public function dimensions(): array
    {
        return match ($this) {
            self::ViewCampus => [Dimension::Campus],
            self::ViewUnit => [Dimension::Unit],
            self::ViewCareer => [Dimension::Career],
            self::ViewCampusUnit => [Dimension::Campus, Dimension::Unit],
            self::ViewCampusCareer => [Dimension::Campus, Dimension::Career],
            self::ViewCampusUnitCareer => [Dimension::Campus, Dimension::Unit, Dimension::Career],
            self::ViewAny, self::ViewOwn => [],
        };
    }

    public function isPersonal(): bool
    {
        return $this === self::ViewOwn;
    }
}
