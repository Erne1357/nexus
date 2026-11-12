<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Lo que muestra la lista de un catálogo de la pantalla de validación: los registros de la revisión
 * (CatalogContentsQuery) paginados, con búsqueda, filtro de estado, de observación abierta y de revisado (CV7), las
 * observaciones de un registro y cuáles de la página ya están revisados. Quién ve la revisión lo decide CatalogReviewPolicy.
 */
final class CatalogRecordsQuery
{
    public function __construct(private readonly CatalogContentsQuery $contents) {}

    /**
     * Una página de registros por clave. `$flag` es `with` (con observación abierta), `without` (sin ella) o vacío;
     * `$onlyIds`, si no es null, deja solo esos registros (el filtro «Falta» de docentes); `$reviewed` es `reviewed`
     * (marcados como revisados), `unreviewed` (sin marca) o vacío.
     *
     * @param  ?list<string>  $onlyIds
     * @return LengthAwarePaginator<int, Model>
     */
    public function page(CatalogReview $review, string $search = '', ?bool $active = null, string $flag = '', int $perPage = 15, ?array $onlyIds = null, string $reviewed = ''): LengthAwarePaginator
    {
        $query = $this->contents->query($review);
        $model = $query->getModel();

        match ($review->catalog) {
            Catalog::Rooms => $query->with(['building', 'campus']),
            Catalog::StudyPlans => $query->with('career'),
            default => null,
        };

        TextSearch::apply($query, [$review->catalog === Catalog::Teachers ? 'rfc' : 'code', 'name'], $search);

        if ($active !== null) {
            $query->where($model->qualifyColumn('is_active'), $active);
        }

        if ($flag === 'with' || $flag === 'without') {
            $open = $review->flags()->whereNull('resolved_at')->where('flaggable_type', $model->getMorphClass())->select('flaggable_id');
            $flag === 'with' ? $query->whereIn($model->qualifyColumn('id'), $open) : $query->whereNotIn($model->qualifyColumn('id'), $open);
        }

        if ($onlyIds !== null) {
            $query->whereIn($model->qualifyColumn('id'), $onlyIds);
        }

        if ($reviewed === 'reviewed' || $reviewed === 'unreviewed') {
            $marks = $review->marks()->select('record_id');
            $reviewed === 'reviewed' ? $query->whereIn($model->qualifyColumn('id'), $marks) : $query->whereNotIn($model->qualifyColumn('id'), $marks);
        }

        return $query->paginate($perPage);
    }

    /**
     * Las observaciones abiertas de los registros dados, por id de registro.
     *
     * @param  Collection<int, string>|list<string>  $ids
     * @return array<string, list<ReviewFlag>>
     */
    public function openFlags(CatalogReview $review, Collection|array $ids): array
    {
        return $review->flags()
            ->whereNull('resolved_at')
            ->whereIn('flaggable_id', $ids)
            ->orderBy('created_at')
            ->get()
            ->groupBy('flaggable_id')
            ->map(fn (Collection $flags): array => $flags->pluck('flag')->all())
            ->all();
    }

    /**
     * De los registros dados, los que ya están marcados como revisados (CV7), como llaves.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    public function reviewedIds(CatalogReview $review, array $ids): array
    {
        return array_fill_keys($review->marks()->whereIn('record_id', $ids)->pluck('record_id')->all(), true);
    }

    /**
     * Las adscripciones sin borrar de los docentes dados al departamento de la revisión, por id de docente: con ellas
     * TeacherPolicy::discharge decide quién da de baja a cada uno (CV8, CV-S8) y DischargeReviewTeacher lo da de baja.
     *
     * @param  list<string>  $teacherIds
     * @return array<string, TeacherDepartment>
     */
    public function teacherAdscriptions(CatalogReview $review, array $teacherIds): array
    {
        return TeacherDepartment::query()
            ->where('department_id', $review->department_id)
            ->whereIn('teacher_id', $teacherIds)
            ->get()
            ->keyBy('teacher_id')
            ->all();
    }

    /**
     * Todas las observaciones de un registro: las abiertas primero, con quién las puso y las resolvió.
     *
     * @return Collection<int, CatalogReviewFlag>
     */
    public function flagsOf(CatalogReview $review, Model $record): Collection
    {
        return $review->flags()
            ->where('flaggable_type', $record->getMorphClass())
            ->where('flaggable_id', $record->getKey())
            ->with(['flagger', 'resolver'])
            ->orderByRaw('resolved_at is not null')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Una observación de la revisión por su id, que llega del navegador.
     *
     * @throws ModelNotFoundException
     */
    public function findFlag(CatalogReview $review, string $id): CatalogReviewFlag
    {
        return KeyLookup::findOrFail($review->flags()->getQuery(), $id);
    }
}
