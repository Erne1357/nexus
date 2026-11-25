<?php

namespace App\Modules\Academic\Queries;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Lecturas de materias para las pantallas.
 */
final class SubjectQuery
{
    /** El valor del filtro de tipo para las materias sin tipo capturado. */
    public const string WITHOUT_KIND = 'none';

    /** El valor del filtro de tipo de espacio para las materias sin marcar (`required_space` nulo). */
    public const string WITHOUT_SPACE = 'none';

    /**
     * La tabla del CRUD de Materias, por clave y sin las borradas, con su departamento (aunque esté
     * borrado) y en cuántos planes está (`study_plans_count`: planes sin borrar que la tienen en su
     * retícula, activos o no). Busca `$search` en la clave o el nombre con TextSearch;
     * `$departmentId` filtra por departamento (un id que no es uuid no trae nada), `$kind` por un
     * valor de SubjectKind o WITHOUT_KIND, y `$active` por estado; `null` no filtra. Quién la ve lo
     * decide SubjectPolicy en el componente. `$space` filtra por un valor de RequiredSpace o WITHOUT_SPACE, y
     * `$onlyDepartments`, si no es null, deja solo las de esos departamentos (quien cambia el tipo de espacio sin ver
     * el catálogo completo, SEL-17).
     *
     * @param  list<string>|null  $onlyDepartments
     * @return LengthAwarePaginator<int, Subject>
     */
    public function forTable(string $search = '', ?string $departmentId = null, ?string $kind = null, ?bool $active = null, int $perPage = 20, ?string $space = null, ?array $onlyDepartments = null): LengthAwarePaginator
    {
        return Subject::query()
            ->with(['department' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'name'])])
            ->withCount('studyPlans')
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($departmentId !== null, fn (Builder $query) => $query->whereIn('department_id', Str::isUuid($departmentId) ? [$departmentId] : []))
            ->when($kind === self::WITHOUT_KIND, fn (Builder $query) => $query->whereNull('kind'))
            ->when($kind !== null && $kind !== self::WITHOUT_KIND, fn (Builder $query) => $query->where('kind', $kind))
            ->when($space === self::WITHOUT_SPACE, fn (Builder $query) => $query->whereNull('required_space'))
            ->when($space !== null && $space !== self::WITHOUT_SPACE, fn (Builder $query) => $query->where('required_space', $space))
            ->when($onlyDepartments !== null, fn (Builder $query) => $query->whereIn('department_id', $onlyDepartments))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * La materia que se va a editar, activar o desactivar, sin las borradas.
     *
     * @throws ModelNotFoundException<Subject>
     */
    public function findOrFail(string $id): Subject
    {
        return KeyLookup::findOrFail(Subject::query(), $id);
    }
}
