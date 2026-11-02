<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Los registros que el jefe revisa en el catálogo de una revisión (docs/dominio/modelo-de-datos.md,
 * "CatalogValidation"), sin los borrados, activos o no:
 *
 * - Docentes: los que tienen una adscripción sin borrar al departamento, sea o no la principal.
 * - Materias y carreras: las del departamento.
 * - Planes: los de las carreras sin borrar del departamento.
 * - Salones: los del departamento en el campus de la revisión.
 *
 * La clave es el número de empleado en los docentes y `code` en lo demás.
 */
final class CatalogContentsQuery
{
    /**
     * Los registros con su id, clave, nombre y estado, por clave.
     *
     * @return list<array{id: string, code: string, name: ?string, is_active: bool}>
     */
    public function records(CatalogReview $review): array
    {
        return $this->rows($this->query($review), $review->catalog);
    }

    /**
     * @param  Builder<Model>  $query
     * @return list<array{id: string, code: string, name: ?string, is_active: bool}>
     */
    private function rows(Builder $query, Catalog $catalog): array
    {
        $model = $query->getModel();

        return $query
            ->get([$model->qualifyColumn('id'), $model->qualifyColumn($this->codeColumn($catalog)).' as code', $model->qualifyColumn('name'), $model->qualifyColumn('is_active')])
            ->map(fn (Model $record): array => [
                'id' => $record->getKey(),
                'code' => $record->getAttribute('code'),
                'name' => $record->getAttribute('name'),
                'is_active' => (bool) $record->getAttribute('is_active'),
            ])
            ->all();
    }

    /**
     * La foto que se guarda al validar: los registros activos, con id, clave y nombre.
     *
     * @return list<array{id: string, code: string, name: ?string}>
     */
    public function snapshot(CatalogReview $review): array
    {
        $query = $this->query($review);

        return array_map(
            fn (array $record): array => ['id' => $record['id'], 'code' => $record['code'], 'name' => $record['name']],
            $this->rows($query->where($query->getModel()->qualifyColumn('is_active'), true), $review->catalog),
        );
    }

    /**
     * Si el registro es del catálogo, del departamento (y del campus, si son salones) de la revisión y no está
     * borrado.
     */
    public function contains(CatalogReview $review, Model $record): bool
    {
        $query = $this->query($review);

        return $record::class === $query->getModel()::class && $query->whereKey($record->getKey())->exists();
    }

    /**
     * Un registro de la revisión por su id, que llega del navegador.
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(CatalogReview $review, string $id): Model
    {
        return KeyLookup::findOrFail($this->query($review), $id);
    }

    /**
     * Los registros de la revisión como consulta, por clave, para listarlos paginados (CatalogRecordsQuery).
     *
     * @return Builder<Model>
     */
    public function builder(CatalogReview $review): Builder
    {
        return $this->query($review);
    }

    /**
     * @return Builder<Model>
     */
    private function query(CatalogReview $review): Builder
    {
        $department = $review->department_id;

        $query = match ($review->catalog) {
            Catalog::Teachers => Teacher::query()->whereHas('adscriptions', fn (Builder $adscription) => $adscription->where('department_id', $department)),
            Catalog::Subjects => Subject::query()->where('department_id', $department),
            Catalog::Careers => Career::query()->where('department_id', $department),
            Catalog::StudyPlans => StudyPlan::query()->whereIn('career_id', Career::query()->where('department_id', $department)->select('id')),
            Catalog::Rooms => Room::query()->where('department_id', $department)->where('campus_id', $review->campus_id),
        };

        return $query->orderBy($query->getModel()->qualifyColumn($this->codeColumn($review->catalog)));
    }

    private function codeColumn(Catalog $catalog): string
    {
        return $catalog === Catalog::Teachers ? 'employee_number' : 'code';
    }
}
