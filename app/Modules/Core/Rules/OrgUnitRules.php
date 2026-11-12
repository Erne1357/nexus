<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas del organigrama que comparten las Actions de OrgUnit y la pantalla (spec §6.1): cada método da el mensaje o
 * nulo, y la pantalla muestra lo mismo deshabilitado.
 *
 * - AZ-S13 (confirmado el 3 de noviembre de 2026): los departamentos académicos siguen bajo la Subdirección Académica;
 *   su nombre, clave y estado se editan en Administración › Departamentos (lo exige también un trigger de la base) y no se mueven aquí.
 * - La Dirección es la raíz única: no se mueve, no se desactiva ni cambia de tipo. Sus claves y la de la Subdirección
 *   Académica (OrgTree::ROOT y OrgTree::ACADEMIC) son las que Nexus busca, así que no cambian; la Subdirección
 *   Académica tampoco cambia de tipo ni se desactiva, porque AZ-S10 y AZ-S13 se apoyan en ella (supuesto por
 *   confirmar).
 * - La clave es única entre las unidades que no son departamentos académicos, también las borradas.
 * - AZ-S16 (supuesto por confirmar): la clave de Servicios Escolares (OrgTree::SCHOOL_SERVICES) no cambia mientras la
 *   unidad tenga un jefe vigente o por empezar, porque su permiso «Por validar» aplica a esa clave; un cambio de tipo
 *   tampoco deja sin lugar a ese jefe (RoleAssignmentRules::kindNeeded()); y la Subdirección Académica no se mueve,
 *   para que ningún jefe la tenga debajo de su unidad.
 * - Un ciclo se revisa leyendo la base al momento (no OrgTree, que se memoriza por petición); el trigger
 *   `org_units_no_cycle` es la última defensa.
 */
final class OrgUnitRules
{
    public function __construct(private readonly RoleAssignmentRules $assignmentRules) {}

    public function lockedReason(OrgUnit $unit): ?string
    {
        return $unit->kind === OrgUnitKind::DepartamentoAcademico
            ? 'Es un departamento académico: su nombre, su clave y su estado se editan en Administración › Departamentos.'
            : null;
    }

    /**
     * Por qué la unidad no se mueve; nulo si puede. La Dirección es la raíz; un departamento académico se queda bajo la
     * Subdirección Académica (AZ-S13), y esta no se mueve (AZ-S16): lo académico aplica a una unidad y a las que
     * cuelgan de ella, así que debajo de otra unidad (Servicios Escolares, por ejemplo) le daría al jefe de esa unidad
     * todos los departamentos académicos.
     */
    public function moveBlocker(OrgUnit $unit): ?string
    {
        return match (true) {
            $unit->kind === OrgUnitKind::Direccion => 'La Dirección es la raíz: no se mueve.',
            $unit->kind === OrgUnitKind::DepartamentoAcademico => 'Un departamento académico no se mueve desde el Organigrama.',
            $this->isAcademic($unit) => 'Nexus usa la Subdirección Académica: no se mueve.',
            default => null,
        };
    }

    public function parentProblem(OrgUnit $parent): ?string
    {
        return match (true) {
            $parent->trashed() || ! $parent->is_active => "{$parent->name} está inactiva.",
            $parent->kind === OrgUnitKind::DepartamentoAcademico => 'Un departamento académico no tiene unidades hijas en el Organigrama.',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public function deactivationBlockers(OrgUnit $unit): array
    {
        if ($unit->kind === OrgUnitKind::DepartamentoAcademico) {
            return [$this->lockedReason($unit)];
        }

        $blockers = [];

        if ($unit->kind === OrgUnitKind::Direccion) {
            $blockers[] = 'La Dirección es la raíz: no se desactiva.';
        }

        if ($this->isAcademic($unit)) {
            $blockers[] = 'Nexus usa la Subdirección Académica: no se desactiva.';
        }

        $children = OrgUnit::query()->where('parent_id', $unit->getKey())->where('is_active', true)->count();

        if ($children > 0) {
            $blockers[] = $children === 1
                ? 'Tiene 1 unidad hija activa; muévela o desactívala primero.'
                : "Tiene {$children} unidades hijas activas; muévelas o desactívalas primero.";
        }

        $assignments = RoleAssignment::query()->notEnded()->where('org_unit_id', $unit->getKey())->count();

        if ($assignments > 0) {
            $blockers[] = $assignments === 1
                ? 'Tiene 1 nombramiento vigente o por empezar; termínalo en Personas y puestos.'
                : "Tiene {$assignments} nombramientos vigentes o por empezar; termínalos en Personas y puestos.";
        }

        return $blockers;
    }

    public function activationBlocker(OrgUnit $unit): ?string
    {
        $parent = $unit->parent_id === null ? null : OrgUnit::withTrashed()->find($unit->parent_id);

        return $parent !== null && ($parent->trashed() || ! $parent->is_active) ? "Activa primero {$parent->name}." : null;
    }

    /**
     * Si la unidad (nueva, o la que se edita) no puede ser de ese tipo.
     */
    public function kindProblem(OrgUnitKind $kind, ?OrgUnit $unit = null): ?string
    {
        if ($kind === OrgUnitKind::Direccion) {
            return 'Solo hay una Dirección.';
        }

        if ($kind === OrgUnitKind::DepartamentoAcademico) {
            return 'Los departamentos académicos se dan de alta en Administración › Departamentos.';
        }

        if ($unit === null) {
            return null;
        }

        if (($fixed = $this->fixedKindReason($unit)) !== null) {
            return $fixed;
        }

        $assignments = RoleAssignment::query()->notEnded()->where('org_unit_id', $unit->getKey())->get();

        foreach ($assignments as $assignment) {
            $needed = $this->assignmentRules->kindNeeded($assignment->role, $unit);

            if ($needed !== null && $needed !== $kind) {
                $title = $assignment->role->titleOn([['kind' => $unit->kind, 'code' => $unit->code, 'name' => $unit->name]]);

                return "Tiene nombramientos de {$title}; ese puesto pide una unidad de tipo {$needed->label()}.";
            }
        }

        return null;
    }

    /**
     * Por qué la unidad no cambia de tipo, sea cual sea el nuevo: la Dirección es la raíz y Nexus usa Subdirección
     * Académica como subdirección (AZ-S10, AZ-S13); nulo si puede cambiar.
     */
    public function fixedKindReason(OrgUnit $unit): ?string
    {
        return match (true) {
            $unit->kind === OrgUnitKind::Direccion => 'La Dirección no cambia de tipo.',
            $this->isAcademic($unit) => 'Nexus usa Subdirección Académica como subdirección; no cambia de tipo.',
            default => null,
        };
    }

    /**
     * @throws ValidationException sobre `code`
     */
    public function ensureCodeIsFree(string $code, ?OrgUnit $ignore = null): void
    {
        $taken = OrgUnit::withTrashed()
            ->where('code', $code)
            ->where('kind', '<>', OrgUnitKind::DepartamentoAcademico)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($taken) {
            throw $this->codeTaken($code);
        }
    }

    /**
     * El error de clave repetida, también para cuando el índice único de la base gana la carrera a ensureCodeIsFree().
     */
    public function codeTaken(string $code): ValidationException
    {
        return ValidationException::withMessages(['code' => "Ya hay una unidad con la clave {$code}."]);
    }

    /**
     * Por qué la clave de la unidad no cambia a `$code`; nulo si puede. La de la Dirección y la de Subdirección
     * Académica, nunca (AZ-S13); la de Servicios Escolares, mientras tenga un jefe vigente o por empezar (AZ-S16).
     */
    public function fixedCodeProblem(OrgUnit $unit, string $code): ?string
    {
        if ($code === $unit->code || $unit->kind === OrgUnitKind::DepartamentoAcademico) {
            return null;
        }

        if (in_array($unit->code, [OrgTree::ROOT, OrgTree::ACADEMIC], true)) {
            return "Nexus usa la clave {$unit->code}; no se cambia.";
        }

        $headed = $unit->code === OrgTree::SCHOOL_SERVICES && RoleAssignment::query()->notEnded()
            ->where('org_unit_id', $unit->getKey())
            ->get()
            ->contains(fn (RoleAssignment $assignment): bool => $this->assignmentRules->allowsUnit($assignment->role, $unit) && $assignment->role->isExclusive());

        return $headed
            ? "Nexus usa la clave {$unit->code} para el jefe de Servicios Escolares; termina su nombramiento antes de cambiarla."
            : null;
    }

    /**
     * Si es la unidad estructural Subdirección Académica (OrgTree::ACADEMIC), y no un departamento que repite su clave.
     */
    private function isAcademic(OrgUnit $unit): bool
    {
        return $unit->code === OrgTree::ACADEMIC && $unit->kind !== OrgUnitKind::DepartamentoAcademico;
    }

    /**
     * Si `$parent` es la unidad o una de sus descendientes: los ancestros de `$parent` (él incluido), leídos al
     * momento, traen a `$unit`.
     */
    public function wouldCycle(OrgUnit $unit, OrgUnit $parent): bool
    {
        $found = DB::selectOne(<<<'SQL'
            with recursive up (id, parent_id) as (
                select id, parent_id from org_units where id = ?
                union
                select o.id, o.parent_id from org_units o join up on o.id = up.parent_id
            )
            select 1 as found from up where id = ?
            SQL, [$parent->getKey(), $unit->getKey()]);

        return $found !== null;
    }

    public function cycleError(OrgUnit $unit): ValidationException
    {
        return ValidationException::withMessages(['parent' => "{$unit->name} no puede depender de sí misma ni de una de sus hijas."]);
    }
}
