<?php

namespace Tests\Fixtures\Authorization;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

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
    case ViewModality = 'fake.thing.view_modality';
    case ViewModalityUnit = 'fake.thing.view_modality_unit';
    case ViewModalityCareer = 'fake.thing.view_modality_career';
    case ViewModalityCampusCareer = 'fake.thing.view_modality_campus_career';
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
            self::ViewModality => [Dimension::Modality],
            self::ViewModalityUnit => [Dimension::Modality, Dimension::Unit],
            self::ViewModalityCareer => [Dimension::Modality, Dimension::Career],
            self::ViewModalityCampusCareer => [Dimension::Modality, Dimension::Career, Dimension::Campus],
            self::ViewAny, self::ViewOwn => [],
        };
    }

    public function isPersonal(): bool
    {
        return $this === self::ViewOwn;
    }

    public function appliesTo(): ?UnitApplicability
    {
        return null;
    }
}
