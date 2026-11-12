<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;

/**
 * Lo que muestra la lista de un catálogo de la pantalla de validación: los registros de la revisión
 * (CatalogContentsQuery) paginados, con búsqueda, filtro de estado, de observación abierta, de revisado (CV7) y, en
 * salones, de campus y edificio; las cifras del filtro segmentado; las observaciones de un registro; quién y cuándo
 * marcó los de la página; los otros departamentos de sus docentes y los docentes que «Agregar docente» ofrece. Quién ve
 * la revisión lo decide CatalogReviewPolicy.
 */
final class CatalogRecordsQuery
{
    public function __construct(
        private readonly CatalogContentsQuery $contents,
        private readonly RoleAssignmentRules $roles,
    ) {}

    /**
     * Una página de registros por clave. `$flag` es `with` (con observación abierta), `without` (sin ella) o vacío;
     * `$onlyIds`, si no es null, deja solo esos registros (el filtro «Falta» de docentes); `$reviewed` es `reviewed`
     * (marcados como revisados), `unreviewed` (sin marca), `pending` (activos sin marca: los que faltan por revisar, CV7)
     * o vacío. En salones, `$campusId` y `$buildingId` dejan los de ese campus o edificio (un id que no es uuid no deja
     * ninguno).
     *
     * @param  ?list<string>  $onlyIds
     * @return LengthAwarePaginator<int, Model>
     */
    public function page(CatalogReview $review, string $search = '', ?bool $active = null, string $flag = '', int $perPage = 15, ?array $onlyIds = null, string $reviewed = '', string $campusId = '', string $buildingId = ''): LengthAwarePaginator
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

        if (in_array($reviewed, ['reviewed', 'unreviewed', 'pending'], true)) {
            $marks = $review->marks()->select('record_id');
            $reviewed === 'reviewed' ? $query->whereIn($model->qualifyColumn('id'), $marks) : $query->whereNotIn($model->qualifyColumn('id'), $marks);
        }

        if ($reviewed === 'pending') {
            $query->where($model->qualifyColumn('is_active'), true);
        }

        if ($review->catalog === Catalog::Rooms) {
            foreach (['campus_id' => $campusId, 'building_id' => $buildingId] as $column => $id) {
                if ($id !== '') {
                    Str::isUuid($id) ? $query->where($model->qualifyColumn($column), $id) : $query->whereRaw('false');
                }
            }
        }

        return $query->paginate($perPage);
    }

    /**
     * Las cifras del filtro segmentado de la lista, de todo el catálogo de la revisión: `pending` (activos sin revisar,
     * los que detienen Validar, CV7), `reviewed` (marcados, activos o no), `flagged` (con observación abierta) y `all`.
     *
     * @return array{pending: int, reviewed: int, flagged: int, all: int}
     */
    public function segmentCounts(CatalogReview $review): array
    {
        $model = $this->contents->query($review)->getModel();
        $id = $model->qualifyColumn('id');

        return [
            'pending' => $this->contents->unreviewedCount($review),
            'reviewed' => $this->contents->query($review)->whereIn($id, $review->marks()->select('record_id'))->count(),
            'flagged' => $this->contents->query($review)->whereIn($id, $this->openFlagged($review, $model))->count(),
            'all' => $this->contents->query($review)->count(),
        ];
    }

    /**
     * Los ids de los registros activos sin revisar de la revisión, por clave y hasta `$limit`: los que elige «Elegir los
     * N por revisar» para marcarlos juntos (CV7).
     *
     * @return list<string>
     */
    public function pendingIds(CatalogReview $review, int $limit = 500): array
    {
        $query = $this->contents->query($review);
        $model = $query->getModel();

        return $query
            ->where($model->qualifyColumn('is_active'), true)
            ->whereNotIn($model->qualifyColumn('id'), $review->marks()->select('record_id'))
            ->limit($limit)
            ->pluck($model->qualifyColumn('id'))
            ->all();
    }

    /**
     * Quién marcó como revisado cada uno de los registros dados que ya lo está, y cuándo (CV7), por id de registro.
     *
     * @param  list<string>  $ids
     * @return array<string, array{by: ?string, at: CarbonImmutable}>
     */
    public function marksOf(CatalogReview $review, array $ids): array
    {
        return $review->marks()
            ->with('reviewer:id,name')
            ->whereIn('record_id', $ids)
            ->get()
            ->mapWithKeys(fn (CatalogReviewMark $mark): array => [$mark->record_id => ['by' => $mark->reviewer?->name, 'at' => $mark->reviewed_at]])
            ->all();
    }

    /**
     * Los otros departamentos de los docentes dados (sus adscripciones sin borrar fuera del de la revisión), la principal
     * primero y luego de la más antigua a la más nueva (el orden en que RemoveAdscription escoge la principal que queda),
     * y si la del departamento de la revisión es su principal: con eso la fila dice «También en» o «Principal en» y la
     * confirmación de la baja dice qué pasa (CV8).
     *
     * @param  list<string>  $teacherIds
     * @return array<string, array{here_primary: bool, others: list<array{code: string, name: string, is_primary: bool}>}>
     */
    public function teacherDepartments(CatalogReview $review, array $teacherIds): array
    {
        $result = array_fill_keys($teacherIds, ['here_primary' => false, 'others' => []]);

        TeacherDepartment::query()
            ->with(['department' => fn (BelongsTo $department) => $department->withTrashed()->select(['id', 'code', 'name'])])
            ->whereIn('teacher_id', $teacherIds)
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->get()
            ->each(function (TeacherDepartment $adscription) use ($review, &$result): void {
                if ($adscription->department_id === $review->department_id) {
                    $result[$adscription->teacher_id]['here_primary'] = $adscription->is_primary;

                    return;
                }

                $result[$adscription->teacher_id]['others'][] = ['code' => (string) $adscription->department?->code, 'name' => (string) $adscription->department?->name, 'is_primary' => $adscription->is_primary];
            });

        return $result;
    }

    /**
     * Los docentes que «Agregar docente» ofrece para la revisión de docentes (CV-S9): todos los que no están borrados,
     * activos o dados de baja, cuyo RFC o nombre contiene `$search` (sin buscar con menos de dos letras), por nombre y
     * hasta `$limit`, con sus adscripciones sin borrar y su departamento. `more` dice si hay más.
     *
     * @return array{teachers: Collection<int, Teacher>, more: bool}
     */
    public function teacherCandidates(string $search, int $limit = 8): array
    {
        if (mb_strlen(trim($search)) < 2) {
            return ['teachers' => new Collection, 'more' => false];
        }

        $teachers = Teacher::query()
            ->with(['adscriptions' => fn (HasMany $adscriptions) => $adscriptions->orderByDesc('is_primary')->orderBy('created_at'), 'adscriptions.department' => fn (BelongsTo $department) => $department->withTrashed()])
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['rfc', 'name'], trim($search)))
            ->orderBy('name')
            ->limit($limit + 1)
            ->get();

        return ['teachers' => $teachers->take($limit)->values(), 'more' => $teachers->count() > $limit];
    }

    /**
     * Los docentes activos de la revisión de docentes sin correo institucional (AC-06), por RFC: el filtro «Falta: Sin
     * correo institucional» y su cifra en la tarjeta. Sin correo no les llega la invitación al validar (AC-09).
     *
     * @return list<string>
     */
    public function teachersWithoutEmail(CatalogReview $review): array
    {
        if ($review->catalog !== Catalog::Teachers) {
            return [];
        }

        return $this->contents->query($review)
            ->where('teachers.is_active', true)
            ->whereNull('teachers.institutional_email')
            ->pluck('teachers.id')
            ->all();
    }

    /**
     * Las opciones de los filtros «Campus» y «Edificio» de la lista de salones: los de los salones de la revisión, por
     * clave, como opciones de x-select («C2 · Campus II», «C2 · LC · Laboratorio de cómputo»).
     *
     * @return array{campuses: list<array{id: string, name: string}>, buildings: list<array{id: string, name: string}>}
     */
    public function roomFilterOptions(CatalogReview $review): array
    {
        $rooms = $this->contents->query($review)->with(['campus:id,code,name', 'building:id,code,name'])->get(['rooms.id', 'rooms.campus_id', 'rooms.building_id']);

        return [
            'campuses' => $rooms->pluck('campus')->filter()->unique('id')->sortBy('code')
                ->map(fn ($campus): array => ['id' => $campus->id, 'name' => "{$campus->code} · {$campus->name}"])->values()->all(),
            'buildings' => $rooms->filter(fn ($room): bool => $room->building !== null)->unique('building_id')->sortBy(fn ($room): string => ($room->campus?->code ?? '').' '.$room->building->code)
                ->map(fn ($room): array => ['id' => $room->building->id, 'name' => trim(($room->campus?->code ?? '').' · '.$room->building->code.' · '.$room->building->name, ' ·')])->values()->all(),
        ];
    }

    /**
     * Los ids de los registros de un modelo con observación abierta en la revisión, como subconsulta.
     *
     * @return Builder<CatalogReviewFlag>
     */
    private function openFlagged(CatalogReview $review, Model $model): Builder
    {
        return $review->flags()->whereNull('resolved_at')->where('flaggable_type', $model->getMorphClass())->select('flaggable_id')->getQuery();
    }

    /**
     * Las observaciones abiertas de los registros dados, por id de registro.
     *
     * @param  SupportCollection<int, string>|list<string>  $ids
     * @return array<string, list<ReviewFlag>>
     */
    public function openFlags(CatalogReview $review, SupportCollection|array $ids): array
    {
        return $review->flags()
            ->whereNull('resolved_at')
            ->whereIn('flaggable_id', $ids)
            ->orderBy('created_at')
            ->get()
            ->groupBy('flaggable_id')
            ->map(fn (SupportCollection $flags): array => $flags->pluck('flag')->all())
            ->all();
    }

    /**
     * Las adscripciones sin borrar de los docentes dados al departamento de la revisión, por id de docente: con ellas
     * TeacherPolicy::removeAdscription decide quién da de baja a cada uno (CV8, CV-S8) y DischargeReviewTeacher lo da de baja.
     *
     * @param  list<string>  $teacherIds
     * @return array<string, TeacherDepartment>
     */
    public function teacherAdscriptions(CatalogReview $review, array $teacherIds): array
    {
        return TeacherDepartment::query()
            ->with('teacher:id,user_id')
            ->where('department_id', $review->department_id)
            ->whereIn('teacher_id', $teacherIds)
            ->get()
            ->keyBy('teacher_id')
            ->all();
    }

    /**
     * De los usuarios dados, los que ocupan un puesto vigente o por empezar (RoleAssignmentRules::holdsStaffRole), como
     * llaves, con una sola consulta: a sus docentes solo los dan de baja el admin y la Subdirección (CV-S8).
     *
     * @param  list<string>  $userIds
     * @return array<string, true>
     */
    public function staffUserIds(array $userIds): array
    {
        return $userIds === [] ? [] : array_fill_keys($this->roles->staffUserIds()->whereIn('user_id', $userIds)->distinct()->pluck('user_id')->all(), true);
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
