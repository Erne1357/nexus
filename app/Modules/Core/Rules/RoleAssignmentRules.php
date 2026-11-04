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
use Illuminate\Support\Facades\DB;
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
 *   queda otro vigente; el último nunca se termina, y ningún día se queda sin admin vigente (`adminGap()`).
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
     * La llave del candado consultivo que serializa las escrituras sobre los puestos de Administración (AZ-S12):
     * EndRoleAssignment lo toma antes del candado del renglón, así dos terminaciones simultáneas se esperan y la
     * segunda revisa la cobertura con lo que la primera ya confirmó.
     */
    public const string ADMIN_SEATS_LOCK = 'role_assignments.admin';

    /**
     * AZ-S12, ningún día sin admin vigente (supuesto por confirmar): si `$assignment` es de Administración y terminarlo
     * con `$lastDay` como último día (o quitarlo, si empieza después) deja algún día sin admin, dice cuál; nulo si no.
     *
     * El primer día que deja de cubrir es el siguiente a `$lastDay` o, si se quita, su inicio, y nunca antes de hoy:
     * desde ahí, la unión de los demás nombramientos `admin` de usuarios activos (`range_agg`) debe cubrir todos los
     * días, sin fin. El que se termina cuenta solo hasta `$lastDay`, que ya quedó atrás de ese día. Uno que ya termina
     * ese día o antes no cambia. Lo usan EndRoleAssignment, bajo ADMIN_SEATS_LOCK, y `endBlocker()`, sin candado.
     * Dice «Es el único…» solo si nadie más cubre ningún día desde ahí; si no, el primer día que queda sin admin.
     */
    public function adminGap(RoleAssignment $assignment, CarbonImmutable $lastDay): ?string
    {
        if ($assignment->role !== Role::Admin) {
            return null;
        }

        $last = $lastDay->toDateString();

        if ($assignment->valid_to !== null && $assignment->valid_to->toDateString() <= $last) {
            return null;
        }

        $from = $assignment->valid_from->toDateString();
        $start = max($from > $last ? $from : $lastDay->addDay()->toDateString(), today()->toDateString());

        $uncovered = $this->uncoveredAdminDays($start, 'ra.id', $assignment->getKey());

        if ($uncovered['first'] === null) {
            return null;
        }

        return $uncovered['all']
            ? 'Es el único nombramiento de Administración que seguiría vigente; nombra a otra persona antes de terminarlo.'
            : 'El '.CarbonImmutable::parse($uncovered['first'])->format('d/m/Y').' nadie tendría un nombramiento de Administración vigente; nombra a otra persona antes de terminarlo.';
    }

    /**
     * AZ-S12: el primer último día, desde hoy, con el que terminar o quitar `$assignment` no deja ningún día sin admin
     * vigente; hoy si no es de Administración y nulo si ningún último día sirve. Los demás nombramientos `admin` de
     * usuarios activos dejan sin cubrir los días `m` desde hoy: si `m` es vacío sirve hoy, si no tiene fin ninguno
     * sirve, y si lo tiene sirve el último día de `m` (hoy, si el nombramiento empieza después de ese día, porque
     * quitarlo no causa el hueco) siempre que sea anterior al fin del propio nombramiento.
     */
    public function earliestLastDay(RoleAssignment $assignment): ?CarbonImmutable
    {
        $today = today()->toImmutable();

        if ($assignment->role !== Role::Admin) {
            return $today;
        }

        $uncovered = $this->uncoveredAdminDays($today->toDateString(), 'ra.id', $assignment->getKey());

        if ($uncovered['first'] === null) {
            $day = $today;
        } elseif (! $uncovered['bounded']) {
            return null;
        } else {
            $day = $assignment->valid_from->toDateString() > $uncovered['last'] ? $today : CarbonImmutable::parse($uncovered['last']);
        }

        return $assignment->valid_to !== null && $day->toDateString() >= $assignment->valid_to->toDateString() ? null : $day;
    }

    /**
     * Por qué el nombramiento no se termina desde «Personas y puestos» ni el Organigrama; nulo si sí: se maneja en otra
     * parte (AZ-S11) o, siendo de Administración, ningún último día desde hoy evita días sin admin (AZ-S12).
     */
    public function endBlocker(RoleAssignment $assignment): ?string
    {
        return $this->managedElsewhere($assignment->role)
            ?? ($this->earliestLastDay($assignment) === null ? $this->adminGap($assignment, today()->toImmutable()) : null);
    }

    /**
     * El único SQL de cobertura de Administración (AZ-S12): los días desde `$from`, sin fin, que ningún nombramiento
     * `admin` de un usuario activo cubre, sin contar el excluido (`$exceptColumn` es `ra.id` o `ra.user_id`).
     *
     * @return array{first: ?string, last: ?string, bounded: bool, all: bool}
     */
    private function uncoveredAdminDays(string $from, string $exceptColumn, string $exceptId): array
    {
        $exceptColumn = $exceptColumn === 'ra.user_id' ? 'ra.user_id' : 'ra.id';

        $row = DB::selectOne(<<<SQL
            select lower(m) as first,
                   upper(m) - 1 as last,
                   (isempty(m) or upper(m) is not null) as bounded,
                   (m = base) as "all"
            from (
                select datemultirange(daterange(cast(? as date), null, '[)')) as base,
                       datemultirange(daterange(cast(? as date), null, '[)')) - coalesce(range_agg(daterange(ra.valid_from, ra.valid_to, '[]')), '{}'::datemultirange) as m
                from role_assignments ra
                join users u on u.id = ra.user_id
                where ra.role = 'admin' and {$exceptColumn} <> ? and u.is_active
            ) covered
            SQL, [$from, $from, $exceptId]);

        return ['first' => $row->first, 'last' => $row->last, 'bounded' => (bool) $row->bounded, 'all' => (bool) $row->all];
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
