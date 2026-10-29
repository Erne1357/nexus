<?php

namespace App\Modules\Academic\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecturas de departamentos para las pantallas.
 */
final class DepartmentQuery
{
    /**
     * La tabla del CRUD de Departamentos, por clave y sin los borrados, con cuántas carreras y
     * materias sin borrar tiene cada uno (`careers_count`, `subjects_count`). Busca `$search` en la
     * clave o el nombre con TextSearch; `$active` filtra por estado y `null` trae los dos. Quién la
     * ve lo decide DepartmentPolicy en el componente.
     *
     * @return LengthAwarePaginator<int, Department>
     */
    public function forTable(string $search = '', ?bool $active = null, int $perPage = 15): LengthAwarePaginator
    {
        return Department::query()
            ->withCount(['careers', 'subjects'])
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->when($active !== null, fn (Builder $query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * Los departamentos sin borrar, por clave, como opciones de x-select para los filtros y los
     * formularios de las pantallas de Academic (Carreras, Materias): «SIS · Sistemas y Computación»;
     * los inactivos también, porque una carrera o materia puede seguir en uno, y lo dicen.
     *
     * @return list<array{id: string, name: string}>
     */
    public function options(): array
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
     * El departamento que se va a editar o borrar, sin los borrados.
     *
     * @throws ModelNotFoundException<Department>
     */
    public function findOrFail(string $id): Department
    {
        return KeyLookup::findOrFail(Department::query(), $id);
    }
}
