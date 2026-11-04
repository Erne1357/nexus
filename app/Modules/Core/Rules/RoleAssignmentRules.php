<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\AssignmentLabels;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de un nombramiento que comparten AppointRole y las pantallas de Personas y puestos: qué columnas de alcance
 * lleva cada puesto (Role::scopeColumns()) y que lo elegido siga sirviendo. Una columna que el puesto no lleva debe
 * venir nula.
 *
 * - AZ-S10 (confirmado el 3 de noviembre de 2026): el puesto Subdirección Académica solo va sobre la unidad
 *   Subdirección Académica.
 * - AZ-S11: Administración, Subdirección Académica y Coordinador de carrera se nombran en «Personas y puestos»; el
 *   docente lo da la ficha del docente y las jefaturas se nombran allí hasta 3.P8 (`managedElsewhere()`).
 * - AZ-S12 (confirmado el 3 de noviembre de 2026): el admin puede terminar su propio puesto de Administración si
 *   queda otro vigente; el último nunca se termina (`ensureAdminRemains()`).
 * - AZ-S7: el titular de una unidad es quien ocupa su puesto exclusivo (`titularRoleOf()`).
 *
 * El campus y la unidad se releen de la base, borrados incluidos, para que un modal abierto con opciones viejas
 * reciba un error en español y no un 500.
 */
final class RoleAssignmentRules
{
    public function __construct(
        private readonly ScopeLabels $labels,
        private readonly AssignmentLabels $assignmentLabels,
    ) {}

    /**
     * @throws ValidationException con las llaves `campus`, `unit` y `career`, todos los errores juntos
     */
    public function ensureScopeFits(Role $role, ?Campus $campus, ?OrgUnit $unit, ?string $careerId): void
    {
        $columns = $role->scopeColumns();
        $errors = [];

        $campus = $campus === null ? null : Campus::withTrashed()->find($campus->getKey());
        $unit = $unit === null ? null : OrgUnit::withTrashed()->find($unit->getKey());
        $campusUsable = false;

        if (in_array('campus_id', $columns, true)) {
            if ($campus === null) {
                $errors['campus'] = 'Elige el campus.';
            } elseif ($campus->trashed() || ! $campus->is_active) {
                $errors['campus'] = "El campus {$campus->code} está inactivo.";
            } else {
                $campusUsable = true;
            }
        } elseif ($campus !== null) {
            $errors['campus'] = 'Este puesto no lleva campus.';
        }

        if (in_array('org_unit_id', $columns, true)) {
            if ($unit === null) {
                $errors['unit'] = $role->unitKind() === OrgUnitKind::Subdireccion ? 'Elige la subdirección.' : 'Elige el departamento académico.';
            } elseif ($unit->kind !== $role->unitKind()) {
                $errors['unit'] = $role->unitKind() === OrgUnitKind::Subdireccion
                    ? "{$unit->name} no es una subdirección."
                    : "{$unit->name} no es un departamento académico.";
            } elseif (! $this->allowsUnit($role, $unit)) {
                $errors['unit'] = 'El puesto de Subdirección Académica va sobre la unidad Subdirección Académica.';
            } elseif ($unit->trashed() || ! $unit->is_active) {
                $errors['unit'] = "{$unit->name} está inactiva.";
            }
        } elseif ($unit !== null) {
            $errors['unit'] = 'Este puesto no lleva unidad.';
        }

        if (in_array('career_id', $columns, true)) {
            if ($careerId === null) {
                $errors['career'] = 'Elige la carrera.';
            } elseif ($campusUsable && ! array_key_exists($careerId, $this->labels->careerOptions($campus->getKey()))) {
                $errors['career'] = "Esa carrera no se ofrece en {$campus->code}.";
            }
        } elseif ($careerId !== null) {
            $errors['career'] = 'Este puesto no lleva carrera.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Si el puesto se nombra sobre esa unidad: el tipo es `Role::unitKind()` y, para la Subdirección, además es
     * Subdirección Académica (AZ-S10), sin borrar. Se decide con la unidad, sin consultar la base: la clave de una
     * unidad estructural es única aun entre las borradas y la de Subdirección Académica no cambia (AZ-S13), así que es
     * la misma que daría `OrgTree::idOf(OrgTree::ACADEMIC)`, y el Organigrama la pregunta por cada caja.
     */
    public function allowsUnit(Role $role, OrgUnit $unit): bool
    {
        if ($role->unitKind() === null || $unit->kind !== $role->unitKind()) {
            return false;
        }

        return $role !== Role::Subdireccion || ($unit->code === OrgTree::ACADEMIC && ! $unit->trashed());
    }

    /**
     * El puesto exclusivo cuyo titular es el de la unidad (AZ-S7): el jefe de un departamento académico, la
     * Subdirección de Subdirección Académica; nulo si la unidad no tiene.
     */
    public function titularRoleOf(OrgUnit $unit): ?Role
    {
        foreach (Role::cases() as $role) {
            if ($role->isExclusive() && $this->allowsUnit($role, $unit)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * AZ-S12: terminar un nombramiento `admin` deja al menos otro de un usuario activo que valga el día siguiente a
     * `$lastDay` (empieza ese día o antes, y su fin es nulo o no anterior a ese día). Los otros se bloquean para que
     * dos terminaciones simultáneas no dejen a nadie. Otros puestos no se revisan.
     *
     * @throws ValidationException sobre `lastDay`
     */
    public function ensureAdminRemains(RoleAssignment $assignment, CarbonImmutable $lastDay): void
    {
        if ($assignment->role !== Role::Admin) {
            return;
        }

        $day = $lastDay->addDay()->toDateString();

        $remains = RoleAssignment::query()
            ->where('role', Role::Admin)
            ->whereKeyNot($assignment->getKey())
            ->where('valid_from', '<=', $day)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $day))
            ->whereHas('user', fn (Builder $query) => $query->where('is_active', true))
            ->lockForUpdate()
            ->exists();

        if (! $remains) {
            throw ValidationException::withMessages([
                'lastDay' => 'Es el único nombramiento de Administración que seguiría vigente; nombra a otra persona antes de terminarlo.',
            ]);
        }
    }

    /**
     * Dónde se maneja un puesto que «Personas y puestos» no nombra ni termina (AZ-S11); nulo si se maneja aquí.
     */
    public function managedElsewhere(Role $role): ?string
    {
        return match ($role) {
            Role::Docente => 'El puesto de docente lo da la ficha del docente: se activa y se desactiva con el docente.',
            Role::JefeDepartamento, Role::JefeProyectoDocente => 'Las jefaturas se nombran y se terminan en la ficha del docente.',
            default => null,
        };
    }

    /**
     * La misma persona no ocupa dos veces el mismo puesto y alcance con fechas que se traslapan.
     *
     * @throws ValidationException sobre `role`
     */
    public function ensureNotDuplicated(User $user, Role $role, ?Campus $campus, ?OrgUnit $unit, ?string $careerId, string $from, ?string $to): void
    {
        $exists = $this->overlapping(RoleAssignment::query(), $from, $to)
            ->where('user_id', $user->getKey())
            ->where('role', $role)
            ->where(fn (Builder $query) => $this->sameOrNull($query, 'campus_id', $campus?->getKey()))
            ->where(fn (Builder $query) => $this->sameOrNull($query, 'org_unit_id', $unit?->getKey()))
            ->where(fn (Builder $query) => $this->sameOrNull($query, 'career_id', $careerId))
            ->exists();

        if ($exists) {
            $summary = $this->assignmentLabels->one(new RoleAssignment([
                'role' => $role,
                'campus_id' => $campus?->getKey(),
                'org_unit_id' => $unit?->getKey(),
                'career_id' => $careerId,
            ]))->summary;

            throw ValidationException::withMessages(['role' => "{$user->name} ya es {$summary} en esas fechas."]);
        }
    }

    /**
     * Un puesto exclusivo (AZ-S7) tiene un titular a la vez: si alguien lo ocupa en esas fechas, el error dice quién y
     * desde cuándo.
     *
     * @throws ValidationException sobre `unit`
     */
    public function ensureSeatFree(Role $role, ?OrgUnit $unit, string $from, ?string $to): void
    {
        if (! $role->isExclusive() || $unit === null) {
            return;
        }

        $occupant = $this->overlapping(RoleAssignment::query(), $from, $to)
            ->where('role', $role)
            ->where('org_unit_id', $unit->getKey())
            ->with('user:id,name')
            ->orderBy('valid_from')
            ->first();

        if ($occupant !== null) {
            throw $this->seatTaken($role, $unit, $occupant);
        }
    }

    /**
     * El error de un puesto exclusivo ocupado; sin ocupante (otra sesión lo ganó entre la revisión y la escritura,
     * SQLSTATE 23P01) no nombra a nadie.
     */
    public function seatTaken(Role $role, OrgUnit $unit, ?RoleAssignment $occupant = null): ValidationException
    {
        $holder = $role === Role::Subdireccion ? 'titular' : 'jefe';
        $who = $occupant === null ? '' : ": {$occupant->user->name} (desde {$occupant->valid_from->format('d/m/Y')})";

        return ValidationException::withMessages([
            'unit' => "{$unit->name} ya tiene {$holder} en esas fechas{$who}. Termina ese nombramiento antes de nombrar a otra persona.",
        ]);
    }

    /**
     * @param  Builder<RoleAssignment>  $query
     * @return Builder<RoleAssignment>
     */
    private function overlapping(Builder $query, string $from, ?string $to): Builder
    {
        return $query
            ->when($to !== null, fn (Builder $query) => $query->where('valid_from', '<=', $to))
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from));
    }

    /**
     * @param  Builder<RoleAssignment>  $query
     */
    private function sameOrNull(Builder $query, string $column, ?string $value): void
    {
        $value === null ? $query->whereNull($column) : $query->where($column, $value);
    }
}
