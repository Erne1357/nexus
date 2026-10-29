<?php

namespace App\Modules\Academic\Queries;

use App\Modules\Academic\Models\Department;
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

    /**
     * La tabla del CRUD de Materias, por clave y sin las borradas, con su departamento (aunque esté
     * borrado) y en cuántos planes está (`study_plans_count`: planes sin borrar que la tienen en su
     * retícula, activos o no). Busca `$search` en la clave o el nombre con TextSearch;
     * `$departmentId` filtra por departamento (un id que no es uuid no trae nada), `$kind` por un
     * valor de SubjectKind o WITHOUT_KIND, y `$active` por estado; `null` no filtra. Quién la ve lo
     * decide SubjectPolicy en el componente.
     *
     * @return LengthAwarePaginator<int, Subject>
     */
    public function forTable(string $search = '', ?string $departmentId = null, ?string $kind = null, ?bool $active = null, int $perPage = 20): LengthAwarePaginator
    {
        return Subject::query()
            ->with(['department' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'name'])])
            ->withCount('studyPlans')
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($departmentId !== null, fn (Builder $query) => $query->whereIn('department_id', Str::isUuid($departmentId) ? [$departmentId] : []))
            ->when($kind === self::WITHOUT_KIND, fn (Builder $query) => $query->whereNull('kind'))
            ->when($kind !== null && $kind !== self::WITHOUT_KIND, fn (Builder $query) => $query->where('kind', $kind))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * Los departamentos sin borrar para el filtro y el formulario, por clave, como opciones de
     * x-select («SIS · Sistemas y Computación»; los inactivos lo dicen).
     *
     * @return list<array{id: string, name: string}>
     */
    public function departmentOptions(): array
    {
        return Department::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active'])
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => "{$department->code} · {$department->name}".($department->is_active ? '' : ' (inactivo)'),
            ])
            ->all();
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
