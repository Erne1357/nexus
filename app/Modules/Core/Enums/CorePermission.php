<?php

namespace App\Modules\Core\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;

/**
 * Permisos del módulo Core: campus, periodos, nombramientos y organigrama (los de nombramientos y organigrama son solo del admin). La clave es `core.<recurso>.<accion>`.
 */
enum CorePermission: string implements Permission
{
    case CampusView = 'core.campus.view';
    case CampusViewInactive = 'core.campus.view_inactive';
    case CampusCreate = 'core.campus.create';
    case CampusUpdate = 'core.campus.update';
    case CampusUpdateProfile = 'core.campus.update_profile';
    case TermView = 'core.term.view';
    case TermCreate = 'core.term.create';
    case TermUpdate = 'core.term.update';
    case TermMakeCurrent = 'core.term.make_current';
    case TermManageCalendar = 'core.term.manage_calendar';
    case TermOpen = 'core.term.open';
    case AssignmentView = 'core.assignment.view';
    case AssignmentAppoint = 'core.assignment.appoint';
    case AssignmentEnd = 'core.assignment.end';
    case OrgUnitView = 'core.org_unit.view';
    case OrgUnitCreate = 'core.org_unit.create';
    case OrgUnitUpdate = 'core.org_unit.update';
    case OrgUnitMove = 'core.org_unit.move';
    case OrgUnitDeactivate = 'core.org_unit.deactivate';

    public function label(): string
    {
        return match ($this) {
            self::CampusView => 'Ver campus',
            self::CampusViewInactive => 'Ver campus inactivos',
            self::CampusCreate => 'Dar de alta campus',
            self::CampusUpdate => 'Editar datos y estado de un campus',
            self::CampusUpdateProfile => 'Editar el perfil de flujo de un campus',
            self::TermView => 'Ver periodos',
            self::TermCreate => 'Dar de alta periodos',
            self::TermUpdate => 'Editar periodos',
            self::TermMakeCurrent => 'Marcar el periodo actual',
            self::TermManageCalendar => 'Manejar el calendario del periodo',
            self::TermOpen => 'Abrir el periodo en un campus',
            self::AssignmentView => 'Ver personas y sus puestos',
            self::AssignmentAppoint => 'Nombrar puestos',
            self::AssignmentEnd => 'Terminar nombramientos',
            self::OrgUnitView => 'Ver el organigrama',
            self::OrgUnitCreate => 'Dar de alta unidades del organigrama',
            self::OrgUnitUpdate => 'Editar unidades del organigrama',
            self::OrgUnitMove => 'Mover unidades del organigrama',
            self::OrgUnitDeactivate => 'Desactivar y activar unidades del organigrama',
        };
    }

    public function dimensions(): array
    {
        return match ($this) {
            self::CampusView, self::CampusUpdateProfile => [Dimension::Campus],
            default => [],
        };
    }

    public function isPersonal(): bool
    {
        return false;
    }
}
