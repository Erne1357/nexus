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
 *   su nombre, clave y estado se editan en Académico (lo exige también un trigger de la base) y no se mueven aquí.
 * - La Dirección es la raíz única: no se mueve, no se desactiva ni cambia de tipo. Sus claves y la de la Subdirección
 *   Académica (OrgTree::ROOT y OrgTree::ACADEMIC) son las que Nexus busca, así que no cambian; la Subdirección
 *   Académica tampoco cambia de tipo ni se desactiva, porque AZ-S10 y AZ-S13 se apoyan en ella (supuesto por
 *   confirmar).
 * - La clave es única entre las unidades que no son departamentos académicos, también las borradas.
 * - Un ciclo se revisa leyendo la base al momento (no OrgTree, que se memoriza por petición); el trigger
 *   `org_units_no_cycle` es la última defensa.
 */
final class OrgUnitRules
{
    public function lockedReason(OrgUnit $unit): ?string
    {
        return $unit->kind === OrgUnitKind::DepartamentoAcademico
            ? 'Es un departamento académico: su nombre, su clave y su estado se editan en Académico › Departamentos.'
            : null;
    }

    public function moveBlocker(OrgUnit $unit): ?string
    {
        return match (true) {
            $unit->kind === OrgUnitKind::Direccion => 'La Dirección es la raíz: no se mueve.',
            $unit->kind === OrgUnitKind::DepartamentoAcademico => 'Un departamento académico no se mueve desde el Organigrama.',
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
            return 'Los departamentos académicos se dan de alta en Académico.';
        }

        if ($unit === null) {
            return null;
        }

        if (($fixed = $this->fixedKindReason($unit)) !== null) {
            return $fixed;
        }

        $assignments = RoleAssignment::query()->notEnded()->where('org_unit_id', $unit->getKey())->get();

        foreach ($assignments as $assignment) {
            $needed = $assignment->role->unitKind();

            if ($needed !== null && $needed !== $kind) {
                return "Tiene nombramientos de {$assignment->role->label()}; ese puesto pide una unidad de tipo {$needed->label()}.";
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

    public function fixedCodeProblem(OrgUnit $unit, string $code): ?string
    {
        $fixed = in_array($unit->code, [OrgTree::ROOT, OrgTree::ACADEMIC], true) && $unit->kind !== OrgUnitKind::DepartamentoAcademico;

        return $fixed && $code !== $unit->code ? "Nexus usa la clave {$unit->code}; no se cambia." : null;
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
