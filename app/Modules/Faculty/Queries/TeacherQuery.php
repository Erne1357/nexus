<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Queries\TextSearch;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Policies\TeacherPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Lecturas de docentes para las pantallas (tarea 1.12). Los docentes son de todo el Tec: nada aquí depende de un
 * campus. El listado deja ver lo mismo que TeacherPolicy (`view`), filtrado en SQL con los alcances de
 * ScopedContext y la misma regla que ScopedContext::coversDepartment(): al admin y a quien tiene un alcance de
 * TeacherPolicy::EDITORS sin departamento (la Subdirección), todos; a los jefes, los docentes con una adscripción
 * sin borrar a uno de sus departamentos. Incluye a los inactivos; los borrados no.
 */
final class TeacherQuery
{
    public function __construct(private readonly TeacherBaseHoursQuery $baseHours) {}

    /**
     * La tabla de Docentes, por número de empleado, con sus adscripciones sin borrar (la principal primero y luego
     * de la más antigua a la más nueva, el orden en que RemoveAdscription escoge la principal que queda) y su
     * departamento, aunque esté borrado. Busca `$search` en el número o el nombre con TextSearch. `$departmentId`
     * filtra por una adscripción sin borrar (un id que no es uuid no trae a nadie); `$kind`, por una plaza de ese
     * tipo vigente el primer día del periodo activo (S1, supuesto por confirmar; sin periodo, hoy); `$active`, por
     * estado. `null` no filtra.
     *
     * @return LengthAwarePaginator<int, Teacher>
     */
    public function forTable(
        ScopedContext $context,
        string $search = '',
        ?string $departmentId = null,
        ?PositionKind $kind = null,
        ?bool $active = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return $this->visible($context)
            ->with(['adscriptions' => fn (HasMany $adscriptions) => $this->orderedAdscriptions($adscriptions)])
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['employee_number', 'name'], $search))
            ->when($departmentId !== null, fn (Builder $query) => $query->whereHas('adscriptions', fn (Builder $adscription) => Str::isUuid($departmentId)
                ? $adscription->where('department_id', $departmentId)
                : $adscription->whereRaw('false')))
            ->when($kind !== null, fn (Builder $query) => $query->whereHas('positions', fn (Builder $position) => $position
                ->currentOn($context->activeTerm()?->starts_on ?? today())
                ->where('kind', $kind)))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('employee_number')
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
     * Las opciones del filtro de departamento: los departamentos de las adscripciones de los docentes que ve, en el
     * formato de los select de MaryUI, con los inactivos marcados.
     *
     * @return array{departments: list<array{id: string, name: string}>}
     */
    public function filterOptions(ScopedContext $context): array
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
     * En qué departamentos puede dar de alta (o adscribir) docentes: los sin borrar que cubre con
     * TeacherPolicy::EDITORS. Al admin y a la Subdirección, todos; a un jefe, el suyo. Con `code` para nombrar el
     * destino y los inactivos marcados.
     *
     * @return list<array{id: string, code: string, name: string}>
     */
    public function adscriptionTargets(ScopedContext $context): array
    {
        $departments = $this->coveredDepartments($context);

        return Department::query()
            ->when($departments !== null, fn (Builder $query) => $query->whereKey($departments))
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
     * El docente sin borrar con ese número de empleado (ya normalizado), para ofrecer adscribirlo en lugar de darlo
     * de alta otra vez (S9, supuesto por confirmar).
     */
    public function findByEmployeeNumber(string $number): ?Teacher
    {
        return Teacher::query()->with('adscriptions')->where('employee_number', $number)->first();
    }

    /**
     * Los docentes que el usuario puede ver: todos, o los adscritos a uno de los departamentos que cubre.
     *
     * @return Builder<Teacher>
     */
    private function visible(ScopedContext $context): Builder
    {
        $departments = $this->coveredDepartments($context);

        return Teacher::query()->when($departments !== null, fn (Builder $query) => $departments === []
            ? $query->whereRaw('false')
            : $query->whereHas('adscriptions', fn (Builder $adscription) => $adscription->whereIn('department_id', $departments)));
    }

    /**
     * Los departamentos que cubre con TeacherPolicy::EDITORS, como ScopedContext::coversDepartment(): null si los
     * cubre todos (el admin, o un alcance sin departamento ni carrera, como el de la Subdirección). Un alcance que
     * fija carrera no cubre docentes.
     *
     * @return list<string>|null
     */
    public function coveredDepartments(ScopedContext $context): ?array
    {
        if ($context->isAdmin()) {
            return null;
        }

        $scopes = $context->scopes()
            ->filter(fn (RoleScope $scope): bool => in_array($scope->role, TeacherPolicy::EDITORS, true) && $scope->career_id === null);

        if ($scopes->contains(fn (RoleScope $scope): bool => $scope->department_id === null)) {
            return null;
        }

        return $scopes->pluck('department_id')->unique()->values()->all();
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
     * «SC · Sistemas y Computación», con «(inactivo)» si el departamento está inactivo.
     */
    private function label(Department $department): string
    {
        return "{$department->code} · {$department->name}".($department->is_active ? '' : ' (inactivo)');
    }
}
