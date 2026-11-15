<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Faculty\Enums\FacultyPermission;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Lecturas de docentes para las pantallas (tarea 1.12). Los docentes son de todo el Tec: nada aquí depende de un
 * campus. El listado deja ver lo mismo que TeacherPolicy (`view`): con `faculty.teacher.view_all` (el admin y la
 * Subdirección), todos, también los que no tienen departamento (confirmado el 30 de octubre de 2026); a los demás,
 * filtrado en SQL con Actor::constrain() y `faculty.teacher.view`, los docentes con una adscripción sin borrar
 * a un departamento que cubren. Incluye a los inactivos; los borrados no.
 */
final class TeacherQuery
{
    public function __construct(private readonly TeacherBaseHoursQuery $baseHours) {}

    /**
     * La tabla de Docentes, por RFC, con sus adscripciones sin borrar (la principal primero y luego
     * de la más antigua a la más nueva, el orden en que RemoveAdscription escoge la principal que queda) y su
     * departamento, aunque esté borrado. Busca `$search` en el número o el nombre con TextSearch. `$departmentId`
     * filtra por una adscripción sin borrar (un id que no es uuid no trae a nadie); `$kind`, por una plaza de ese
     * tipo vigente el primer día del periodo activo (S1, supuesto por confirmar; sin periodo, hoy); `$active`, por
     * estado; `$withInstitutionalEmail`, por si tiene correo institucional (AC-06). `null` no filtra. Cada docente
     * trae su usuario con lo que pide el estado de su acceso (AC-04): si tiene contraseña (`has_password`), si se le
     * invitó, si debe cambiarla y si el admin dio de baja su cuenta en el panel (`deactivated_at`, CF-09).
     *
     * @return LengthAwarePaginator<int, Teacher>
     */
    public function forTable(
        Actor $context,
        string $search = '',
        ?string $departmentId = null,
        ?PositionKind $kind = null,
        ?bool $active = null,
        int $perPage = 15,
        ?bool $withInstitutionalEmail = null,
    ): LengthAwarePaginator {
        return $this->filtered($context, $search, $departmentId, $kind)
            ->with([
                'adscriptions' => fn (HasMany $adscriptions) => $this->orderedAdscriptions($adscriptions),
                // Para el estado del acceso (AC-04, UserAccess::resolve()), sin leer la contraseña.
                'user' => fn (BelongsTo $user) => $user
                    ->select(['id', 'must_change_password', 'access_invited_at', 'deactivated_at'])
                    ->selectRaw('password is not null as has_password'),
            ])
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->when($withInstitutionalEmail !== null, fn (Builder $query) => $withInstitutionalEmail
                ? $query->whereNotNull('institutional_email')
                : $query->whereNull('institutional_email'))
            ->orderBy('rfc')
            ->paginate($perPage);
    }

    /**
     * Las horas base de los docentes de una página en el periodo, en una sola consulta (TeacherBaseHoursQuery), por
     * id; un docente sin plazas vigentes da 0. Sin periodo, ninguna.
     *
     * @param  iterable<int, Teacher>  $teachers
     * @return array<string, int>
     */
    public function baseHoursFor(?Term $term, iterable $teachers): array
    {
        $ids = [];

        foreach ($teachers as $teacher) {
            $ids[] = $teacher->getKey();
        }

        return $term === null || $ids === [] ? [] : $this->baseHours->forTerm($term, $ids);
    }

    /**
     * Cuántos docentes activos de los que ve no tienen correo institucional (AC-06): sin él no reciben su invitación de
     * acceso (AC-07). Para el aviso de la lista, con su búsqueda y sus filtros de departamento y tipo de plaza (los de
     * forTable()), así que «Ver solo esos» muestra exactamente a los que cuenta (AC-04, tarea 1.21).
     */
    public function missingInstitutionalEmailCount(Actor $context, string $search = '', ?string $departmentId = null, ?PositionKind $kind = null): int
    {
        return $this->filtered($context, $search, $departmentId, $kind)
            ->where('is_active', true)
            ->whereNull('institutional_email')
            ->count();
    }

    /**
     * Los docentes que ve `$context` con la búsqueda y los filtros de departamento y tipo de plaza de la lista
     * (forTable()); `null` no filtra.
     *
     * @return Builder<Teacher>
     */
    private function filtered(Actor $context, string $search, ?string $departmentId, ?PositionKind $kind): Builder
    {
        return $this->visible($context)
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['rfc', 'name'], $search))
            ->when($departmentId !== null, fn (Builder $query) => $query->whereHas('adscriptions', fn (Builder $adscription) => Str::isUuid($departmentId)
                ? $adscription->where('department_id', $departmentId)
                : $adscription->whereRaw('false')))
            ->when($kind !== null, fn (Builder $query) => $query->whereHas('positions', fn (Builder $position) => $position
                ->currentOn($context->activeTerm()?->starts_on ?? today())
                ->where('kind', $kind)));
    }

    /**
     * El estado del acceso de los docentes de una página (AC-04), por id, con el usuario que ya trae forTable(): sin
     * usuario no tiene acceso.
     *
     * @param  iterable<int, Teacher>  $teachers
     * @return array<string, UserAccess>
     */
    public function accessFor(iterable $teachers): array
    {
        $access = [];

        foreach ($teachers as $teacher) {
            $user = $teacher->user;
            $access[$teacher->getKey()] = $user === null
                ? UserAccess::None
                : UserAccess::resolve((bool) $user->getAttribute('has_password'), $user->access_invited_at !== null, (bool) $user->must_change_password);
        }

        return $access;
    }

    /**
     * Las opciones del filtro de departamento: los departamentos de las adscripciones de los docentes que ve, en el
     * formato de los select de MaryUI, con los inactivos marcados.
     *
     * @return array{departments: list<array{id: string, name: string}>}
     */
    public function filterOptions(Actor $context): array
    {
        $departments = Department::withTrashed()
            ->whereIn('id', TeacherDepartment::query()->whereIn('teacher_id', $this->visible($context)->select('teachers.id'))->select('department_id'))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active'])
            ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $this->label($department)])
            ->all();

        return ['departments' => $departments];
    }

    /**
     * En qué departamentos puede dar de alta (o adscribir) docentes: los sin borrar en los que tiene
     * `faculty.teacher.create` (Actor::constrain()), como TeacherPolicy::create(). Al admin, todos; a la
     * Subdirección, los de Subdirección Académica; a un jefe, el suyo. Con `code` para nombrar el destino y los
     * inactivos marcados.
     *
     * @return list<array{id: string, code: string, name: string}>
     */
    public function adscriptionTargets(Actor $context): array
    {
        return $context->constrain(Department::query(), FacultyPermission::TeacherCreate, ['unit' => 'departments.id'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active'])
            ->map(fn (Department $department): array => ['id' => $department->id, 'code' => $department->code, 'name' => $this->label($department)])
            ->all();
    }

    /**
     * El docente que se va a editar, activar o desactivar, sin los borrados y con sus adscripciones, que usa
     * TeacherPolicy. El id llega del navegador: uno que no es uuid no existe (ADR-0014).
     *
     * @throws ModelNotFoundException<Teacher>
     */
    public function findOrFail(string $id): Teacher
    {
        return KeyLookup::findOrFail(Teacher::query()->with('adscriptions'), $id);
    }

    /**
     * La adscripción sin borrar que se va a quitar, con su departamento (aunque esté borrado) y su docente, que usa
     * TeacherPolicy para no dejar que alguien se quite a sí mismo. El id llega del navegador (ADR-0014).
     *
     * @throws ModelNotFoundException<TeacherDepartment>
     */
    public function findAdscriptionOrFail(string $id): TeacherDepartment
    {
        return KeyLookup::findOrFail(TeacherDepartment::query()->with([
            'department' => fn (BelongsTo $department) => $department->withTrashed(),
            'teacher',
        ]), $id);
    }

    /**
     * De los usuarios dados, los que ocupan un puesto vigente o por empezar (RoleAssignmentRules::holdsStaffRole), como
     * llaves, con una sola consulta: a sus docentes solo los quitan de un departamento o los dan de baja el admin y la
     * Subdirección (CV-S8).
     *
     * @param  list<string>  $userIds
     * @return array<string, true>
     */
    public function staffUserIds(array $userIds): array
    {
        return $userIds === [] ? [] : array_fill_keys(app(RoleAssignmentRules::class)->staffUserIds()->whereIn('user_id', $userIds)->distinct()->pluck('user_id')->all(), true);
    }

    /**
     * El docente con ese RFC (ya normalizado), para ofrecer adscribirlo en lugar de darlo de alta otra
     * vez (S9, supuesto por confirmar); con `$withTrashed`, también si está borrado, para decir por qué no se puede.
     */
    public function findByRfc(string $number, bool $withTrashed = false): ?Teacher
    {
        return Teacher::query()
            ->when($withTrashed, fn (Builder $query) => $query->withTrashed())
            ->with('adscriptions')
            ->where('rfc', $number)
            ->first();
    }

    /**
     * El docente que se va a adscribir (S9), aunque se haya borrado mientras tanto, para decirlo en el formulario en
     * lugar de un 404; null si el id no existe o no es uuid (ADR-0014).
     */
    public function findForAdscription(string $id): ?Teacher
    {
        return KeyLookup::find(Teacher::withTrashed()->with('adscriptions'), $id);
    }

    /**
     * Los docentes que el usuario puede ver, como TeacherPolicy::view(): todos con `faculty.teacher.view_all` (el admin
     * y la Subdirección); si no, con Actor::constrain() y `faculty.teacher.view`, los que tienen una adscripción
     * sin borrar a un departamento de su unidad o de su subárbol.
     *
     * @return Builder<Teacher>
     */
    private function visible(Actor $context): Builder
    {
        $query = Teacher::query();

        if ($context->allows(FacultyPermission::TeacherViewAll)) {
            return $query;
        }

        return $context->constrain($query, FacultyPermission::TeacherView, [
            'unit' => fn (Builder $teachers, array $departments) => $teachers
                ->whereHas('adscriptions', fn (Builder $adscription) => $adscription->whereIn('department_id', $departments)),
        ]);
    }

    /**
     * Los departamentos en los que puede `$permission` (uno por unidad), para filtrar lo que no es un docente, como
     * las materias del perfil: null si los cubre todos, es decir, si cubre un objetivo sin unidad (el admin, o un
     * nombramiento sin unidad); si no, los ids de los departamentos, también los borrados, de su unidad o de su
     * subárbol (Actor::constrain()): a un jefe, el suyo; a la Subdirección, los de Subdirección Académica.
     *
     * @return list<string>|null
     */
    public function coveredDepartments(Actor $context, FacultyPermission $permission): ?array
    {
        if ($context->allows($permission, Target::of())) {
            return null;
        }

        return $context->constrain(Department::withTrashed(), $permission, ['unit' => 'departments.id'])
            ->pluck('departments.id')
            ->all();
    }

    /**
     * @param  HasMany<TeacherDepartment, Teacher>  $adscriptions
     * @return HasMany<TeacherDepartment, Teacher>
     */
    private function orderedAdscriptions(HasMany $adscriptions): HasMany
    {
        return $adscriptions
            ->with(['department' => fn (BelongsTo $department) => $department->withTrashed()->select(['id', 'code', 'name', 'is_active'])])
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Qué pasa si se quita al docente de esa adscripción (D10, confirmado el 14 de noviembre de 2026), para la
     * confirmación de «Quitar de <Departamento>» en la lista y en la ficha: si era su último departamento, queda dado
     * de baja y su cuenta se inhabilita (CV8); si no, solo sale de ese departamento, y si era la principal dice cuál
     * queda, con la regla de RemoveAdscription (la más antigua de las que quedan: `$adscriptions` llegan en ese orden
     * detrás de la principal, con su departamento).
     *
     * @param  iterable<int, TeacherDepartment>  $adscriptions  las adscripciones sin borrar del docente
     */
    public function removalConsequence(TeacherDepartment $adscription, iterable $adscriptions): string
    {
        $rest = collect($adscriptions)->reject(fn (TeacherDepartment $other): bool => $other->is($adscription))->values();
        $name = $adscription->department?->name;

        return match (true) {
            $rest->isEmpty() => 'Es su último departamento: queda dado de baja y su cuenta se inhabilita.',
            $adscription->is_primary => "Solo sale de {$name}: sigue activo y su adscripción principal pasa a {$rest->first()->department?->name}.",
            default => "Solo sale de {$name}: sigue activo y su adscripción principal no cambia.",
        };
    }

    /**
     * «SC · Sistemas y Computación», con «(inactivo)» si el departamento está inactivo.
     */
    private function label(Department $department): string
    {
        return "{$department->code} · {$department->name}".($department->is_active ? '' : ' (inactivo)');
    }
}
