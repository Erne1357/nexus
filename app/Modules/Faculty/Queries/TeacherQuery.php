<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lecturas de docentes para las pantallas (tarea 1.12). El listado deja ver lo mismo que TeacherPolicy (`view`),
 * filtrado en SQL con los alcances de ScopedContext y la misma regla que ScopedContext::covers(): al admin, todos;
 * a quien tiene un alcance de TeacherPolicy::EDITORS, los docentes con una adscripción sin borrar en el campus del
 * alcance y, si el alcance fija departamento (jefes), en ese departamento. Incluye a los inactivos; los borrados no.
 */
final class TeacherQuery
{
    public function __construct(private readonly TeacherBaseHoursQuery $baseHours) {}

    /**
     * La tabla de Docentes, por número de empleado, con sus adscripciones sin borrar (la principal primero) y su
     * departamento y campus, aunque estén borrados. Busca `$search` en el número o el nombre con TextSearch.
     * `$departmentId` y `$campusId` filtran por una adscripción sin borrar (las dos a la vez, la misma adscripción;
     * un id que no es uuid no trae a nadie); `$kind`, por una plaza de ese tipo vigente el primer día del periodo
     * activo (S1, supuesto por confirmar; sin periodo, hoy); `$active`, por estado. `null` no filtra.
     *
     * @return LengthAwarePaginator<int, Teacher>
     */
    public function forTable(
        ScopedContext $context,
        string $search = '',
        ?string $departmentId = null,
        ?string $campusId = null,
        ?PositionKind $kind = null,
        ?bool $active = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return $this->visible($context)
            ->with(['adscriptions' => fn (HasMany $adscriptions) => $adscriptions
                ->with([
                    'department' => fn (BelongsTo $department) => $department->withTrashed()->select(['id', 'code', 'name']),
                    'campus' => fn (BelongsTo $campus) => $campus->withTrashed()->select(['id', 'code', 'name']),
                ])
                ->orderByDesc('is_primary')
                ->orderBy('id')])
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['employee_number', 'name'], $search))
            ->when($departmentId !== null || $campusId !== null, fn (Builder $query) => $query->whereHas('adscriptions', fn (Builder $adscription) => $adscription
                ->when($departmentId !== null, fn (Builder $adscription) => $this->whereKeyFromClient($adscription, 'department_id', $departmentId))
                ->when($campusId !== null, fn (Builder $adscription) => $this->whereKeyFromClient($adscription, 'campus_id', $campusId))))
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
     * Las opciones de los filtros: los departamentos y los campus de las adscripciones de los docentes que ve, en el
     * formato de los select de MaryUI.
     *
     * @return array{departments: list<array{id: string, name: string}>, campuses: list<array{id: string, name: string}>}
     */
    public function filterOptions(ScopedContext $context): array
    {
        $adscriptions = fn (string $column): Builder => TeacherDepartment::query()
            ->whereIn('teacher_id', $this->visible($context)->select('teachers.id'))
            ->select($column);

        return [
            'departments' => $this->options(Department::withTrashed()->whereIn('id', $adscriptions('department_id'))),
            'campuses' => $this->options(Campus::withTrashed()->whereIn('id', $adscriptions('campus_id'))),
        ];
    }

    /**
     * Dónde puede dar de alta (o adscribir) docentes: los departamentos y los campus sin borrar que cubre con
     * TeacherPolicy::EDITORS. Al admin, todos los departamentos y los campus activos; a la subdirección, todos los
     * departamentos en su campus; a un jefe, su departamento en su campus. Con `code` para nombrar el destino.
     *
     * @return array{departments: list<array{id: string, code: string, name: string}>, campuses: list<array{id: string, code: string, name: string}>}
     */
    public function adscriptionTargets(ScopedContext $context): array
    {
        if ($context->isAdmin()) {
            return [
                'departments' => $this->options(Department::query(), withCode: true),
                'campuses' => $this->campusOptions($context->campuses()),
            ];
        }

        $scopes = $this->editorScopes($context);
        $wholeCampus = $scopes->contains(fn (RoleScope $scope): bool => $scope->department_id === null);
        $campusIds = $scopes->pluck('campus_id')->unique()->all();

        return [
            'departments' => $this->options(
                Department::query()->when(! $wholeCampus, fn (Builder $query) => $query->whereKey($scopes->pluck('department_id')->unique()->all())),
                withCode: true,
            ),
            'campuses' => $this->campusOptions($context->campuses()->filter(fn (Campus $campus): bool => in_array($campus->id, $campusIds, true))),
        ];
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
     * El docente sin borrar con ese número de empleado (ya normalizado), para ofrecer adscribirlo en lugar de darlo
     * de alta otra vez (S9, supuesto por confirmar).
     */
    public function findByEmployeeNumber(string $number): ?Teacher
    {
        return Teacher::query()->with('adscriptions')->where('employee_number', $number)->first();
    }

    /**
     * Los docentes que el usuario puede ver: con cada alcance de TeacherPolicy::EDITORS, los que covers() le daría.
     *
     * @return Builder<Teacher>
     */
    private function visible(ScopedContext $context): Builder
    {
        $query = Teacher::query();

        if ($context->isAdmin()) {
            return $query;
        }

        $scopes = $this->editorScopes($context);

        return $query->whereHas('adscriptions', fn (Builder $adscription) => $adscription->where(function (Builder $covered) use ($scopes): void {
            $covered->whereRaw('false');

            foreach ($scopes as $scope) {
                $covered->orWhere(fn (Builder $one) => $one
                    ->where('campus_id', $scope->campus_id)
                    ->when($scope->department_id, fn (Builder $one, string $department) => $one->where('department_id', $department)));
            }
        }));
    }

    /**
     * Los alcances vigentes con los que cubre docentes. Como covers(), uno que fija carrera no cubre nada que no sea
     * de una carrera, y un docente no lo es.
     *
     * @return Collection<int, RoleScope>
     */
    private function editorScopes(ScopedContext $context): Collection
    {
        return $context->scopes()
            ->filter(fn (RoleScope $scope): bool => in_array($scope->role, TeacherPolicy::EDITORS, true) && $scope->career_id === null)
            ->values();
    }

    /**
     * @param  Builder<covariant Department|Campus>  $query
     * @return list<array{id: string, name: string, code?: string}>
     */
    private function options(Builder $query, bool $withCode = false): array
    {
        return $query->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (Department|Campus $row): array => [
                'id' => $row->id,
                'name' => "{$row->code} · {$row->name}",
                ...($withCode ? ['code' => $row->code] : []),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Campus>  $campuses
     * @return list<array{id: string, code: string, name: string}>
     */
    private function campusOptions(Collection $campuses): array
    {
        return $campuses->map(fn (Campus $campus): array => ['id' => $campus->id, 'name' => "{$campus->code} · {$campus->name}", 'code' => $campus->code])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<TeacherDepartment>  $query
     * @return Builder<TeacherDepartment>
     */
    private function whereKeyFromClient(Builder $query, string $column, string $id): Builder
    {
        return Str::isUuid($id) ? $query->where($column, $id) : $query->whereRaw('false');
    }
}
