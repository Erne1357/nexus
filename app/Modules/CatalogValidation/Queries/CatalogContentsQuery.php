<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Los registros que el jefe revisa en el catálogo de una revisión (docs/dominio/modelo-de-datos.md,
 * "CatalogValidation"), sin los borrados, activos o no:
 *
 * - Docentes: los que tienen una adscripción sin borrar al departamento, sea o no la principal.
 * - Materias y carreras: las del departamento.
 * - Planes: los de las carreras sin borrar del departamento.
 * - Salones: los del departamento en todos los campus (decisión 6, confirmada el 12 de noviembre de 2026).
 *
 * La clave es el RFC en los docentes y `code` en lo demás. Cuántos de los activos ya marcó el jefe como revisados (CV7:
 * solo los activos hay que revisarlos) lo dicen progress() y progressOf(); las marcas de registros que ya no están en la
 * revisión no cuentan (CV-S7).
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
     * Si el registro es del catálogo y del departamento de la revisión y no está borrado.
     */
    public function contains(CatalogReview $review, Model $record): bool
    {
        $query = $this->query($review);

        return $record::class === $query->getModel()::class && $query->whereKey($record->getKey())->exists();
    }

    /**
     * Si todos los ids son de registros de la revisión, sin borrar. Un id que no es uuid no es de la revisión.
     *
     * @param  list<string>  $ids  sin repetidos
     */
    public function containsAll(CatalogReview $review, array $ids): bool
    {
        foreach ($ids as $id) {
            if (! Str::isUuid($id)) {
                return false;
            }
        }

        $query = $this->query($review);

        return $query->whereKey($ids)->count() === count($ids);
    }

    /**
     * Cuántos registros activos de la revisión quedan sin revisar (CV7): los que están en ella, activos y sin marca. Los
     * inactivos no hace falta revisarlos (confirmado el 12 de noviembre de 2026).
     */
    public function unreviewedCount(CatalogReview $review): int
    {
        $query = $this->active($this->scoped($review->catalog, $review->department_id));

        return $query->whereNotIn($query->getModel()->qualifyColumn('id'), $review->marks()->select('record_id'))->count();
    }

    /**
     * «N de M revisados» de una revisión, solo de los registros activos (CV7).
     *
     * @return array{reviewed: int, total: int}
     */
    public function progress(CatalogReview $review): array
    {
        return $this->progressOf(collect([$review]))[$review->getKey()];
    }

    /**
     * «N de M revisados» de varias revisiones, por id de revisión, con una consulta por catálogo y no una por revisión:
     * cuenta los registros activos de cada una con una subconsulta ligada a su departamento.
     *
     * @param  Collection<int, CatalogReview>  $reviews
     * @return array<string, array{reviewed: int, total: int}>
     */
    public function progressOf(Collection $reviews): array
    {
        $result = [];
        $department = new Expression('"catalog_reviews"."department_id"');

        foreach ($reviews->groupBy(fn (CatalogReview $review): string => $review->catalog->value) as $catalog => $group) {
            $records = fn (): Builder => $this->active($this->scoped(Catalog::from($catalog), $department));
            $model = $records()->getModel();
            $marks = CatalogReviewMark::query()->select('record_id')->whereColumn('catalog_review_marks.catalog_review_id', 'catalog_reviews.id');

            CatalogReview::query()
                ->whereKey($group->map->getKey()->all())
                ->select('catalog_reviews.id')
                ->selectSub($records()->selectRaw('count(*)'), 'records_total')
                ->selectSub($records()->whereIn($model->qualifyColumn('id'), $marks)->selectRaw('count(*)'), 'records_reviewed')
                ->toBase()
                ->get()
                ->each(function (object $row) use (&$result): void {
                    $result[$row->id] = ['reviewed' => (int) $row->records_reviewed, 'total' => (int) $row->records_total];
                });
        }

        return $result;
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
     * Los registros de la revisión como consulta, por clave, sin los borrados: la base de records(), contains() y
     * de la lista paginada de la pantalla (CatalogRecordsQuery).
     *
     * @return Builder<Model>
     */
    public function query(CatalogReview $review): Builder
    {
        $query = $this->scoped($review->catalog, $review->department_id);

        return $query->orderBy($query->getModel()->qualifyColumn($this->codeColumn($review->catalog)));
    }

    /**
     * Solo los activos de la consulta.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function active(Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('is_active'), true);
    }

    /**
     * Los registros de un catálogo de un departamento, sin orden. `$department` es un id o la columna de la revisión de
     * una consulta exterior (progressOf).
     *
     * @return Builder<Model>
     */
    private function scoped(Catalog $catalog, string|Expression $department): Builder
    {
        return match ($catalog) {
            Catalog::Teachers => Teacher::query()->whereHas('adscriptions', fn (Builder $adscription) => $adscription->where('department_id', $department)),
            Catalog::Subjects => Subject::query()->where('department_id', $department),
            Catalog::Careers => Career::query()->where('department_id', $department),
            Catalog::StudyPlans => StudyPlan::query()->whereIn('career_id', Career::query()->where('department_id', $department)->select('id')),
            Catalog::Rooms => Room::query()->where('department_id', $department),
        };
    }

    private function codeColumn(Catalog $catalog): string
    {
        return $catalog === Catalog::Teachers ? 'rfc' : 'code';
    }
}
