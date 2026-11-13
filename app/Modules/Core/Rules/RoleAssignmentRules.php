<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
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
 * lleva cada puesto (Role::scopeColumns()) y que lo elegido siga sirviendo. Una columna que el puesto no pide ni admite
 * (Role::optionalScopeColumns()) debe venir nula.
 *
 * - AZ-S17 (M5 y M9, confirmados el 12 de noviembre de 2026): el coordinador va sobre su carrera y una modalidad; el
 *   jefe de proyecto docente, sobre su departamento y una modalidad. La modalidad debe estar activa y sin borrar (MS8).
 *   El campus es opcional: si se elige, debe estar activo y la carrera del coordinador debe ofrecerse ahí; sin campus,
 *   la carrera debe ofrecerse en algún campus activo (supuesto por confirmar: la oferta de las modalidades sin salones
 *   se define en la fase 10, MS5). En una modalidad sin salones (o con perfil inválido, Modality::valid_profile) no se
 *   elige campus: sus grupos no tienen (MS4), así que un nombramiento con campus nunca los cubriría al escribir.
 *
 * - AZ-S10 (confirmado el 3 de noviembre de 2026): el puesto Subdirección Académica solo va sobre la unidad
 *   Subdirección Académica.
 * - D19 (confirmado el 5 de noviembre de 2026): el jefe de departamento va sobre un departamento académico o sobre el
 *   Departamento de Servicios Escolares (OrgTree::SCHOOL_SERVICES), que valida las materias de la migración del SII;
 *   los demás departamentos que no son académicos no tienen jefe en Nexus (supuesto por confirmar).
 * - AZ-S11: todos los puestos salvo el de docente se nombran en «Personas y puestos»; el docente lo da la ficha del
 *   docente (`managedElsewhere()`).
 * - AZ-S12 (confirmado el 3 de noviembre de 2026): el admin puede terminar su propio puesto de Administración si
 *   queda otro vigente; el último nunca se termina, y ningún día se queda sin admin vigente (`adminGap()`).
 * - AZ-S7: el titular de una unidad es quien ocupa su puesto exclusivo (`titularRoleOf()`).
 *
 * El campus, la unidad y la modalidad se releen de la base, borrados incluidos, para que un modal abierto con opciones
 * viejas reciba un error en español y no un 500.
 */
final class RoleAssignmentRules
{
    public function __construct(
        private readonly ScopeLabels $labels,
        private readonly AssignmentLabels $assignmentLabels,
    ) {}

    /**
     * @throws ValidationException con las llaves `modality`, `campus`, `unit` y `career`, todos los errores juntos
     */
    public function ensureScopeFits(Role $role, ?Campus $campus, ?OrgUnit $unit, ?string $careerId, ?Modality $modality = null): void
    {
        $columns = $role->scopeColumns();
        $optional = $role->optionalScopeColumns();
        $errors = [];

        $campus = $campus === null ? null : Campus::withTrashed()->find($campus->getKey());
        $unit = $unit === null ? null : OrgUnit::withTrashed()->find($unit->getKey());
        $modality = $modality === null ? null : Modality::withTrashed()->find($modality->getKey());
        $campusUsable = false;

        if (in_array('modality_id', $columns, true)) {
            if ($modality === null) {
                $errors['modality'] = 'Elige la modalidad.';
            } elseif ($modality->trashed() || ! $modality->is_active) {
                $errors['modality'] = "La modalidad {$modality->name} está inactiva.";
            }
        } elseif ($modality !== null) {
            $errors['modality'] = 'Este puesto no lleva modalidad.';
        }

        if (in_array('campus_id', $columns, true) || in_array('campus_id', $optional, true)) {
            if ($campus === null) {
                if (in_array('campus_id', $columns, true)) {
                    $errors['campus'] = 'Elige el campus.';
                }
            } elseif ($campus->trashed() || ! $campus->is_active) {
                $errors['campus'] = "El campus {$campus->code} está inactivo.";
            } elseif ($modality !== null && ! isset($errors['modality']) && $modality->valid_profile?->usesRooms !== true) {
                $errors['campus'] = "{$modality->name} no usa salones: sus grupos no tienen campus, así que el nombramiento va sin campus.";
            } else {
                $campusUsable = true;
            }
        } elseif ($campus !== null) {
            $errors['campus'] = 'Este puesto no lleva campus.';
        }

        if (in_array('org_unit_id', $columns, true)) {
            if ($unit === null) {
                $errors['unit'] = match ($role) {
                    Role::Subdireccion => 'Elige la subdirección.',
                    Role::JefeDepartamento => 'Elige el departamento.',
                    default => 'Elige el departamento académico.',
                };
            } elseif ($unit->kind !== $role->unitKind() && ! $this->isSchoolServicesHead($role, $unit)) {
                $errors['unit'] = match ($role) {
                    Role::Subdireccion => "{$unit->name} no es una subdirección.",
                    Role::JefeDepartamento => "{$unit->name} no es un departamento académico ni Servicios Escolares.",
                    default => "{$unit->name} no es un departamento académico.",
                };
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
            } elseif ($campus === null && ! array_key_exists($careerId, $this->labels->careerOptions(null))) {
                $errors['career'] = 'Esa carrera no se ofrece en ningún campus.';
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
     * Subdirección Académica (AZ-S10), sin borrar; el jefe de departamento también va sobre Servicios Escolares (D19).
     * Se decide con la unidad, sin consultar la base: la clave de una unidad estructural es única aun entre las
     * borradas y la de Subdirección Académica no cambia (AZ-S13), así que es la misma que daría
     * `OrgTree::idOf(OrgTree::ACADEMIC)`, y el Organigrama la pregunta por cada caja.
     */
    public function allowsUnit(Role $role, OrgUnit $unit): bool
    {
        if ($this->isSchoolServicesHead($role, $unit)) {
            return true;
        }

        if ($role->unitKind() === null || $unit->kind !== $role->unitKind()) {
            return false;
        }

        return $role !== Role::Subdireccion || ($unit->code === OrgTree::ACADEMIC && ! $unit->trashed());
    }

    /**
     * El tipo de unidad que el puesto pide sobre `$unit`: el de Role::unitKind(), salvo el jefe del Departamento de
     * Servicios Escolares, que pide un departamento (D19). Nulo si el puesto no lleva unidad. Lo usa OrgUnitRules para
     * que un cambio de tipo no deje sin lugar a los nombramientos de la unidad.
     */
    public function kindNeeded(Role $role, OrgUnit $unit): ?OrgUnitKind
    {
        return $this->isSchoolServicesHead($role, $unit) ? OrgUnitKind::Departamento : $role->unitKind();
    }

    /**
     * D19: el jefe del Departamento de Servicios Escolares, un departamento que no es académico.
     */
    private function isSchoolServicesHead(Role $role, OrgUnit $unit): bool
    {
        return $role === Role::JefeDepartamento && $unit->kind === OrgUnitKind::Departamento && $unit->code === OrgTree::SCHOOL_SERVICES;
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
     * Los puestos que se nombran sobre esa unidad desde el Organigrama: los que la admiten (`allowsUnit()`) y no se
     * manejan en otra parte (AZ-S11), en el orden del enum.
     *
     * @return list<Role>
     */
    public function rolesForUnit(OrgUnit $unit): array
    {
        return array_values(array_filter(
            Role::cases(),
            fn (Role $role): bool => $this->allowsUnit($role, $unit) && $this->managedElsewhere($role) === null,
        ));
    }

    /**
     * La llave del candado consultivo que serializa las escrituras sobre los puestos de Administración (AZ-S12):
     * EndRoleAssignment lo toma antes del candado del renglón y SyncTeacherAccess (la baja del docente) antes del
     * candado del usuario, así dos escrituras simultáneas se esperan y la segunda revisa la cobertura con lo que la
     * primera ya confirmó.
     */
    public const string ADMIN_SEATS_LOCK = 'role_assignments.admin';

    /**
     * AZ-S12, ningún día sin admin vigente (supuesto por confirmar): si `$assignment` es de Administración y terminarlo
     * con `$lastDay` como último día (o quitarlo, si empieza después) deja algún día sin admin, dice cuál; nulo si no.
     *
     * El primer día que deja de cubrir es el siguiente a `$lastDay` o, si se quita, su inicio, y nunca antes de hoy:
     * desde ahí, la unión de los demás nombramientos `admin` de usuarios activos (`range_agg`) debe cubrir todos los
     * días, sin fin. El que se termina cuenta solo hasta `$lastDay`, que ya quedó atrás de ese día. Uno que ya termina
     * ese día o antes no cambia. Lo usa EndRoleAssignment, bajo ADMIN_SEATS_LOCK; `endState()` responde lo mismo para hoy,
     * sin candado y en la misma consulta que el primer último día posible.
     * Dice «Es el único…» solo si nadie más cubre ningún día desde ahí; si no, el primer día que queda sin admin.
     */
    public function adminGap(RoleAssignment $assignment, CarbonImmutable $lastDay): ?string
    {
        if ($assignment->role !== Role::Admin) {
            return null;
        }

        if ($assignment->valid_to !== null && $assignment->valid_to->toDateString() <= $lastDay->toDateString()) {
            return null;
        }

        $uncovered = $this->uncoveredAdminDays($this->gapStart($assignment, $lastDay), 'ra.id', $assignment->getKey());

        return $this->gapMessage($uncovered['first'], $uncovered['all']);
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
        return $this->endState($assignment)['earliestLastDay'];
    }

    /**
     * Lo que la pantalla necesita para «Terminar…»: el primer último día posible (`earliestLastDay()`) y por qué no se
     * termina aquí (`endBlocker()`), con una sola consulta de cobertura para un nombramiento de Administración y
     * ninguna para los demás. El bloqueo de Administración es el de `adminGap()` con hoy como último día.
     *
     * @return array{earliestLastDay: ?CarbonImmutable, blocker: ?string}
     */
    public function endState(RoleAssignment $assignment): array
    {
        $today = today()->toImmutable();

        if ($assignment->role !== Role::Admin) {
            return ['earliestLastDay' => $today, 'blocker' => $this->managedElsewhere($assignment->role)];
        }

        $uncovered = $this->uncoveredAdminDays($today->toDateString(), 'ra.id', $assignment->getKey(), $this->gapStart($assignment, $today));

        if ($uncovered['first'] === null) {
            $day = $today;
        } elseif (! $uncovered['bounded']) {
            $day = null;
        } else {
            $day = $assignment->valid_from->toDateString() > $uncovered['last'] ? $today : CarbonImmutable::parse($uncovered['last']);
        }

        $endsFirst = $assignment->valid_to !== null && $assignment->valid_to->toDateString() <= $today->toDateString();

        if ($day !== null && $assignment->valid_to !== null && $day->toDateString() >= $assignment->valid_to->toDateString()) {
            $day = null;
        }

        return [
            'earliestLastDay' => $day,
            'blocker' => $day === null && ! $endsFirst ? $this->gapMessage($uncovered['gapFirst'], $uncovered['gapAll']) : null,
        ];
    }

    /**
     * Si `$userId` tiene un nombramiento distinto de `docente` y de `alumno` vigente o por empezar: es personal con
     * puesto (un jefe, la Subdirección, un coordinador, el admin). A ellos no se les invita a activar su acceso; se lo da
     * el admin a mano (AC-03 y AC-07). Ser alumno no es un puesto de personal (AC-10).
     */
    public function holdsStaffRole(string $userId): bool
    {
        return $this->staffAssignments()->where('user_id', $userId)->exists();
    }

    /**
     * Los ids de los usuarios con un puesto distinto de `docente` y de `alumno` vigente o por empezar (holdsStaffRole),
     * como subconsulta.
     *
     * @return Builder<RoleAssignment>
     */
    public function staffUserIds(): Builder
    {
        return $this->staffAssignments()->select('user_id');
    }

    /**
     * @return Builder<RoleAssignment>
     */
    private function staffAssignments(): Builder
    {
        return RoleAssignment::query()->whereNotIn('role', [Role::Docente, Role::Alumno])->notEnded();
    }

    /**
     * AZ-S12 y P3 (confirmado el 3 de noviembre de 2026): el primer día sin admin vigente si se desactiva `$user`; nulo
     * si no tiene nombramientos `admin` sin terminar o si, sin ellos, otros admin activos cubren todos los días desde
     * `max(hoy, el menor inicio de los suyos)`. Se llama bajo ADMIN_SEATS_LOCK.
     */
    public function deactivationGap(User $user): ?CarbonImmutable
    {
        $from = RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->where('role', Role::Admin)
            ->notEnded()
            ->min('valid_from');

        if ($from === null) {
            return null;
        }

        $first = $this->uncoveredAdminDays(max(today()->toDateString(), CarbonImmutable::parse($from)->toDateString()), 'ra.user_id', $user->getKey())['first'];

        return $first === null ? null : CarbonImmutable::parse($first);
    }

    /**
     * Por qué el nombramiento no se termina desde «Personas y puestos» ni el Organigrama; nulo si sí: se maneja en otra
     * parte (AZ-S11) o, siendo de Administración, ningún último día desde hoy evita días sin admin (AZ-S12).
     */
    public function endBlocker(RoleAssignment $assignment): ?string
    {
        return $this->endState($assignment)['blocker'];
    }

    /**
     * El primer día que dejaría sin cubrir terminar `$assignment` con `$lastDay` como último día (o quitarlo, si
     * empieza después): el siguiente a `$lastDay` o, si se quita, su inicio, y nunca antes de hoy.
     */
    private function gapStart(RoleAssignment $assignment, CarbonImmutable $lastDay): string
    {
        $from = $assignment->valid_from->toDateString();

        return max($from > $lastDay->toDateString() ? $from : $lastDay->addDay()->toDateString(), today()->toDateString());
    }

    /**
     * El texto de AZ-S12 para el primer día sin admin: «Es el único…» si nadie más cubre ningún día desde ahí; nulo si
     * no queda ningún día sin cubrir.
     */
    private function gapMessage(?string $first, bool $all): ?string
    {
        if ($first === null) {
            return null;
        }

        return $all
            ? 'Es el único nombramiento de Administración que seguiría vigente; nombra a otra persona antes de terminarlo.'
            : 'El '.CarbonImmutable::parse($first)->format('d/m/Y').' nadie tendría un nombramiento de Administración vigente; nombra a otra persona antes de terminarlo.';
    }

    /**
     * El único SQL de cobertura de Administración (AZ-S12): los días desde `$from`, sin fin, que ningún nombramiento
     * `admin` de un usuario activo cubre, sin contar el excluido (`$exceptColumn` es `ra.id` o `ra.user_id`). En la
     * misma consulta da también el primero de esos días desde `$gapFrom` (no antes de `$from`; por omisión, `$from`) y
     * si ninguno desde ahí está cubierto.
     *
     * @return array{first: ?string, last: ?string, bounded: bool, all: bool, gapFirst: ?string, gapAll: bool}
     */
    private function uncoveredAdminDays(string $from, string $exceptColumn, string $exceptId, ?string $gapFrom = null): array
    {
        $exceptColumn = $exceptColumn === 'ra.user_id' ? 'ra.user_id' : 'ra.id';
        $gapFrom = max($gapFrom ?? $from, $from);

        $row = DB::selectOne(<<<SQL
            select lower(m) as first,
                   upper(m) - 1 as last,
                   (isempty(m) or upper(m) is not null) as bounded,
                   (m = base) as "all",
                   lower(m * gap) as gap_first,
                   (m * gap = gap) as gap_all
            from (
                select datemultirange(daterange(cast(? as date), null, '[)')) as base,
                       datemultirange(daterange(cast(? as date), null, '[)')) as gap,
                       datemultirange(daterange(cast(? as date), null, '[)')) - coalesce(range_agg(daterange(ra.valid_from, ra.valid_to, '[]')), '{}'::datemultirange) as m
                from role_assignments ra
                join users u on u.id = ra.user_id
                where ra.role = 'admin' and {$exceptColumn} <> ? and u.is_active
            ) covered
            SQL, [$from, $gapFrom, $from, $exceptId]);

        return [
            'first' => $row->first,
            'last' => $row->last,
            'bounded' => (bool) $row->bounded,
            'all' => (bool) $row->all,
            'gapFirst' => $row->gap_first,
            'gapAll' => (bool) $row->gap_all,
        ];
    }

    /**
     * Dónde se maneja un puesto que «Personas y puestos» no nombra ni termina (AZ-S11); nulo si se maneja aquí.
     */
    public function managedElsewhere(Role $role): ?string
    {
        return match ($role) {
            Role::Docente => 'El puesto de docente lo da la ficha del docente: se activa y se desactiva con el docente.',
            Role::Alumno => 'El puesto de alumno lo da la migración del SII: su usuario se activa y se desactiva con su estatus.',
            default => null,
        };
    }

    /**
     * La misma persona no ocupa dos veces el mismo puesto y alcance (modalidad incluida) con fechas que se traslapan.
     * Del jefe de proyecto docente lo garantiza también la base (`role_assignments_jpd_no_duplicate`).
     *
     * @throws ValidationException sobre `role`
     */
    public function ensureNotDuplicated(User $user, Role $role, ?Campus $campus, ?OrgUnit $unit, ?string $careerId, string $from, ?string $to, ?Modality $modality = null): void
    {
        $exists = $this->overlapping(RoleAssignment::query(), $from, $to)
            ->where('user_id', $user->getKey())
            ->where('role', $role)
            ->where(fn (Builder $query) => $this->sameOrNull($query, 'campus_id', $campus?->getKey()))
            ->where(fn (Builder $query) => $this->sameOrNull($query, 'org_unit_id', $unit?->getKey()))
            ->where(fn (Builder $query) => $this->sameOrNull($query, 'career_id', $careerId))
            ->where(fn (Builder $query) => $this->sameOrNull($query, 'modality_id', $modality?->getKey()))
            ->exists();

        if ($exists) {
            throw $this->duplicated($user, $role, $campus, $unit, $careerId, $modality);
        }
    }

    /**
     * El error de la misma persona dos veces en el mismo puesto y alcance: lo lanza `ensureNotDuplicated()` y, si otra
     * sesión escribió el duplicado entre la revisión y la escritura (SQLSTATE 23P01), AppointRole.
     */
    public function duplicated(User $user, Role $role, ?Campus $campus, ?OrgUnit $unit, ?string $careerId, ?Modality $modality = null): ValidationException
    {
        $summary = $this->assignmentLabels->one(new RoleAssignment([
            'role' => $role,
            'campus_id' => $campus?->getKey(),
            'org_unit_id' => $unit?->getKey(),
            'career_id' => $careerId,
            'modality_id' => $modality?->getKey(),
        ]))->summary;

        return ValidationException::withMessages(['role' => "{$user->name} ya es {$summary} en esas fechas."]);
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
