<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogOwner;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Events\LoadStatusesRequested;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\CampusQuery;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\ModalityQuery;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Lo que lee el tablero de avance de validación (tarea 2.5): la matriz de departamentos por catálogo de un periodo, con
 * Servicios Escolares como un renglón más (CV15, confirmada el 20 de noviembre de 2026).
 *
 * El total de un renglón son sus catálogos (docentes y salones de un departamento; materias, planes y carreras de
 * Servicios Escolares) que el admin abrió en el periodo (CV9; la de salones es una para todos los campus, decisión 6,
 * confirmada el 12 de noviembre de 2026). Una revisión que falta de un catálogo abierto, por
 * ejemplo la de un departamento nuevo, cuenta como pendiente. Todo sale de pocas consultas
 * para todos los departamentos, sin una por fila.
 */
final class ValidationProgressQuery
{
    public function __construct(
        private readonly RecipientsQuery $recipients,
        private readonly CatalogContentsQuery $contents,
        private readonly CatalogReviewQuery $catalogReviews,
        private readonly CampusQuery $campuses,
        private readonly ModalityQuery $modalityQuery,
    ) {}

    /**
     * Los campus donde el periodo está abierto, activos y sin borrar, por código: donde se dan los grupos de las
     * modalidades donde se abrió (M4, MS4; CampusQuery::openIn).
     *
     * @return Collection<int, Campus>
     */
    public function openCampuses(Term $term): Collection
    {
        return $this->campuses->openIn($term);
    }

    /**
     * Las modalidades donde se abrió el periodo cuyos grupos se dan en sus campus (M4): las que usan salones (MS4), por
     * clave.
     *
     * @return Collection<int, Modality>
     */
    public function openModalities(Term $term): Collection
    {
        return $this->modalityQuery->openedWithRooms($term);
    }

    /**
     * La matriz del periodo: la validación es para todo el Tec, sin campus (decisión 6; desde el 14 de noviembre de 2026
     * el tablero tampoco filtra por campus).
     *
     * `columns`: los catálogos abiertos en el periodo, primero los de cada departamento (Docentes y Salones) y después
     * los de Servicios Escolares (Materias, Planes de estudio y Carreras; CV15), ninguno si no se abrió la validación.
     * `rows`: un renglón por dueño con algún catálogo suyo abierto (ReviewOwner en `department`): Servicios Escolares
     * primero y después cada departamento activo. `columns` de cada renglón son solo sus catálogos, y `cells` y
     * `reviewed` solo los traen a ellos: la revisión por columna (nula si falta), cuántos registros lleva revisados de
     * cuántos (nulo si falta; CV7). Cada renglón trae además cuántas lleva validadas del total, sus pendientes y en
     * revisión, las observaciones abiertas, el jefe vigente, el estado de su carga en cada modalidad donde se abrió el
     * periodo (la carga es por departamento y modalidad, M1; evento LoadStatusesRequested; Servicios Escolares no tiene
     * carga) y el último recordatorio del periodo. El jefe sale de lo mismo que recibe el aviso (RecipientsQuery::holders:
     * permiso de validar la revisión sobre el dueño, usuario activo), `heads` y `head` (el primero), y de ahí
     * `remind_blocked_reason`, el motivo por el que «Recordar» no aplica (nada pendiente o sin jefe), nulo si aplica;
     * `remindable` cuenta los renglones visibles a los que sí y sus jefes distintos. `totals`: por columna, de los
     * renglones a los que les toca. `global` y `flags` cuentan todos los renglones; `search` (departamento o jefe) y
     * `status` (`pending`: con algo sin validar; `validated`: todo validado) solo filtran `rows` y `totals`.
     *
     * Con `$ownerId` (un id de departamento o ReviewOwner::SCHOOL_SERVICES), solo ese (para el drawer de uno que los
     * filtros dejaron fuera).
     *
     * @return array{
     *     columns: list<array{key: string, catalog: Catalog}>,
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, array{validated: int, in_review: int, total: int}>,
     *     global: array{validated: int, in_review: int, pending: int, total: int, percent: int},
     *     flags: array{open: int, departments: int, resolved: int},
     *     remindable: array{departments: int, heads: int}
     * }
     */
    public function board(Term $term, string $search = '', string $status = '', ?string $ownerId = null): array
    {
        $catalogs = $this->catalogReviews->openedCatalogs($term);
        $ordered = [
            ...array_filter($catalogs, fn (Catalog $catalog): bool => $catalog->owner() === CatalogOwner::Department),
            ...array_filter($catalogs, fn (Catalog $catalog): bool => $catalog->owner() === CatalogOwner::SchoolServices),
        ];
        $columns = array_map(fn (Catalog $catalog): array => ['key' => $catalog->value, 'catalog' => $catalog], array_values($ordered));

        $owners = $this->catalogReviews->activeOwners($ownerId === null ? null : [$ownerId])
            ->filter(fn (ReviewOwner $owner): bool => array_filter($owner->catalogs(), fn (Catalog $catalog): bool => in_array($catalog, $catalogs, true)) !== [])
            ->values();
        $departmentIds = $owners->pluck('departmentId')->filter()->values()->all();

        $reviews = CatalogReview::query()
            ->where('term_id', $term->getKey())
            ->where(fn ($query) => $query->whereIn('department_id', $departmentIds)->orWhereNull('department_id'))
            ->whereIn('catalog', $catalogs)
            ->with('validator')
            ->get()
            ->keyBy(fn (CatalogReview $review): string => $review->owner_id.'|'.$review->catalog->value);

        $reviewed = $this->contents->progressOf($reviews->values());

        $flags = CatalogReviewFlag::query()
            ->join('catalog_reviews', 'catalog_reviews.id', '=', 'catalog_review_flags.review_id')
            ->where('catalog_reviews.term_id', $term->getKey())
            ->where(fn ($query) => $query->whereIn('catalog_reviews.department_id', $departmentIds)->orWhereNull('catalog_reviews.department_id'))
            ->whereIn('catalog_reviews.catalog', $catalogs)
            ->selectRaw('coalesce(catalog_reviews.department_id::text, ?) as owner, count(*) filter (where catalog_review_flags.resolved_at is null) as open, count(*) filter (where catalog_review_flags.resolved_at is not null) as resolved', [ReviewOwner::SCHOOL_SERVICES])
            ->groupByRaw('1')
            ->toBase()
            ->get()
            ->keyBy('owner');

        $heads = $owners->mapWithKeys(fn (ReviewOwner $owner): array => [
            $owner->id => collect($this->recipients->holders(CatalogValidationPermission::ReviewValidate, CatalogReviewQuery::targetOf($owner->id)))->pluck('user'),
        ]);

        $modalities = $this->openModalities($term);
        $statuses = new LoadStatusesRequested($term, $modalities->modelKeys());
        event($statuses);

        $reminders = $this->lastReminders($term, $owners->pluck('id')->all());

        $rows = $owners->map(function (ReviewOwner $owner) use ($columns, $reviews, $reviewed, $flags, $heads, $modalities, $statuses, $reminders): array {
            $own = array_values(array_filter($columns, fn (array $column): bool => in_array($column['catalog'], $owner->catalogs(), true)));
            $cells = [];

            foreach ($own as $column) {
                $cells[$column['key']] = $reviews->get($owner->id.'|'.$column['key']);
            }

            $validated = count(array_filter($cells, fn (?CatalogReview $review): bool => $review?->status === ReviewStatus::Validated));
            $inReview = count(array_filter($cells, fn (?CatalogReview $review): bool => $review?->status === ReviewStatus::InReview));
            $total = count($own);

            return [
                'department' => $owner,
                'columns' => $own,
                'heads' => $heads->get($owner->id),
                'head' => $heads->get($owner->id)->first(),
                'cells' => $cells,
                'reviewed' => array_map(fn (?CatalogReview $review): ?array => $review === null ? null : $reviewed[$review->getKey()], $cells),
                'validated' => $validated,
                'in_review' => $inReview,
                'pending' => $total - $validated - $inReview,
                'remind_blocked_reason' => match (true) {
                    $validated === $total => 'No le falta nada por validar.',
                    $heads->get($owner->id)->isEmpty() => 'No tiene jefe vigente.',
                    default => null,
                },
                'pending_columns' => array_values(array_filter($own, fn (array $column): bool => $cells[$column['key']]?->status !== ReviewStatus::Validated)),
                'total' => $total,
                'open_flags' => (int) ($flags->get($owner->id)?->open ?? 0),
                'resolved_flags' => (int) ($flags->get($owner->id)?->resolved ?? 0),
                'loads' => $owner->departmentId === null ? [] : $modalities->map(fn (Modality $modality): array => ['modality' => $modality, 'load' => $statuses->for($owner->departmentId, $modality->getKey())])
                    ->filter(fn (array $entry): bool => $entry['load'] !== null)->values()->all(),
                'last_reminder' => $reminders[$owner->id] ?? null,
            ];
        })->all();

        $global = [
            'validated' => array_sum(array_column($rows, 'validated')),
            'in_review' => array_sum(array_column($rows, 'in_review')),
            'pending' => array_sum(array_column($rows, 'pending')),
            'total' => array_sum(array_column($rows, 'total')),
        ];
        $global['percent'] = $global['total'] === 0 ? 0 : (int) round($global['validated'] / $global['total'] * 100);

        $summary = [
            'open' => array_sum(array_column($rows, 'open_flags')),
            'departments' => count(array_filter($rows, fn (array $row): bool => $row['open_flags'] > 0)),
            'resolved' => array_sum(array_column($rows, 'resolved_flags')),
        ];

        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->matches($row, $search, $status)));

        $remindable = array_filter($rows, fn (array $row): bool => $row['remind_blocked_reason'] === null);
        $remindableHeads = ['departments' => count($remindable), 'heads' => collect($remindable)->flatMap(fn (array $row) => $row['heads']->pluck('id'))->unique()->count()];

        $totals = [];

        foreach ($columns as $column) {
            $cells = array_column(array_filter(array_column($rows, 'cells'), fn (array $cells): bool => array_key_exists($column['key'], $cells)), $column['key']);
            $validated = count(array_filter($cells, fn (?CatalogReview $review): bool => $review?->status === ReviewStatus::Validated));

            $totals[$column['key']] = [
                'validated' => $validated,
                'in_review' => count(array_filter($cells, fn (?CatalogReview $review): bool => $review?->status === ReviewStatus::InReview)),
                'total' => count(array_filter($rows, fn (array $row): bool => array_key_exists($column['key'], $row['cells']))),
            ];
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'global' => $global, 'flags' => $summary, 'remindable' => $remindableHeads];
    }

    /**
     * Si alguien vigente valida las revisiones de `$ownerId` (un departamento o ReviewOwner::SCHOOL_SERVICES): el mismo
     * jefe que recibe los avisos. Sin él, nadie valida lo de ese dueño (el drawer «Abrir validación» lo advierte para
     * Servicios Escolares, CV15).
     */
    public function hasHead(string $ownerId): bool
    {
        return $this->recipients->holders(CatalogValidationPermission::ReviewValidate, CatalogReviewQuery::targetOf($ownerId)) !== [];
    }

    /**
     * Las observaciones abiertas de un departamento o de Servicios Escolares (CV15) en el periodo, de las más recientes a
     * las más antiguas, con el registro observado (aunque se haya borrado), quién la puso y su revisión. Las revisiones no
     * son de un campus (decisión 6): entran las de los catálogos abiertos.
     *
     * @return Collection<int, CatalogReviewFlag>
     */
    public function openFlags(Term $term, ReviewOwner $owner): Collection
    {
        return $this->openFlagsQuery($term)
            ->whereHas('review', fn ($query) => CatalogReviewQuery::ownedBy($query, $owner->departmentId))
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Las observaciones abiertas del periodo de Servicios Escolares y de todos los departamentos activos, para
     * «Observaciones abiertas» del tablero (CV10, confirmado el 14 de noviembre de 2026; CV15): las de Servicios
     * Escolares primero, luego por clave del departamento y, dentro de cada uno, de la más reciente a la más antigua.
     *
     * @return Collection<int, CatalogReviewFlag>
     */
    public function allOpenFlags(Term $term): Collection
    {
        return $this->openFlagsQuery($term)
            ->whereHas('review', fn ($query) => $query->whereNull('department_id')->orWhereHas('department', fn ($query) => $query->where('is_active', true)))
            ->get()
            ->sortBy([fn (CatalogReviewFlag $a, CatalogReviewFlag $b): int => ($a->review->department?->code ?? '') <=> ($b->review->department?->code ?? ''), fn (CatalogReviewFlag $a, CatalogReviewFlag $b): int => $b->created_at <=> $a->created_at])
            ->values();
    }

    /**
     * Una observación por su id, que llega del navegador, con su revisión.
     */
    public function findFlag(string $id): CatalogReviewFlag
    {
        return KeyLookup::findOrFail(CatalogReviewFlag::query()->with('review.department', 'review.campus'), $id);
    }

    /**
     * Cuántos departamentos ya validaron todo lo que les toca de lo que el admin abrió en el periodo, de cuántos (la
     * tarjeta de Inicio de la validación, CV-S13): Servicios Escolares y los departamentos activos (CV15), cada uno con
     * sus propios catálogos; solo cuentan los que tienen alguno abierto. Una revisión que falta cuenta como pendiente,
     * igual que en el tablero.
     *
     * @return array{validated: int, total: int}
     */
    public function departmentsValidated(Term $term): array
    {
        $opened = $this->catalogReviews->openedCatalogs($term);
        $validated = CatalogReview::query()
            ->where('term_id', $term->getKey())
            ->where('status', ReviewStatus::Validated)
            ->get(['department_id', 'catalog'])
            ->groupBy(fn (CatalogReview $review): string => $review->owner_id)
            ->map(fn (Collection $reviews): array => $reviews->pluck('catalog')->all());

        $counted = $this->catalogReviews->activeOwners()
            ->mapWithKeys(fn (ReviewOwner $owner): array => [$owner->id => array_values(array_filter($owner->catalogs(), fn (Catalog $catalog): bool => in_array($catalog, $opened, true)))])
            ->filter(fn (array $catalogs): bool => $catalogs !== []);
        $done = $counted->filter(fn (array $catalogs, string $ownerId): bool => array_diff(
            array_map(fn (Catalog $catalog): string => $catalog->value, $catalogs),
            array_map(fn (Catalog $catalog): string => $catalog->value, $validated->get($ownerId, [])),
        ) === []);

        return ['validated' => $done->count(), 'total' => $counted->count()];
    }

    /**
     * Cuándo se recordó por última vez a cada departamento, o a Servicios Escolares (CV15), en el periodo, según la
     * bitácora de RemindPendingReviews (su propiedad `owner`; las entradas de antes, sin ella, por el departamento sobre
     * el que se registraron). La validación es de todo el Tec: no importa en qué campus estaba el tablero (las entradas
     * viejas guardan `campuses`).
     *
     * @param  list<string>  $ownerIds  ids de departamento o ReviewOwner::SCHOOL_SERVICES
     * @return array<string, CarbonImmutable>
     */
    public function lastReminders(Term $term, array $ownerIds): array
    {
        return Activity::query()
            ->where('event', 'reminded')
            ->where('properties->term_id', $term->getKey())
            ->where(fn ($query) => $query
                ->whereIn('properties->owner', $ownerIds)
                ->orWhere(fn ($query) => $query->whereNull('properties->owner')->where('subject_type', (new Department)->getMorphClass())->whereIn('subject_id', array_values(array_filter($ownerIds, fn (string $id): bool => Str::isUuid($id))))))
            ->selectRaw("coalesce(properties->>'owner', subject_id::text) as owner, max(created_at) as last")
            ->groupByRaw('1')
            ->toBase()
            ->pluck('last', 'owner')
            ->map(fn (string $last): CarbonImmutable => CarbonImmutable::parse($last))
            ->all();
    }

    /**
     * El registro observado como «ISIC-2010 · Ingeniería en Sistemas»: el RFC en los docentes y la clave con su campus en
     * los salones (CatalogContentsQuery::roomCode). Uno que ya no existe se nombra así.
     */
    public static function recordLabel(Catalog $catalog, ?Model $record): string
    {
        if ($record === null) {
            return 'un registro que ya no está';
        }

        $code = match ($catalog) {
            Catalog::Teachers => (string) $record->getAttribute('rfc'),
            Catalog::Rooms => CatalogContentsQuery::roomCode($record),
            default => (string) $record->getAttribute('code'),
        };

        return filled($record->getAttribute('name')) ? "{$code} · {$record->getAttribute('name')}" : $code;
    }

    /**
     * Dónde se actúa sobre el registro observado, en su catálogo (CV10): la ficha del plan o del docente, y la lista de
     * carreras, materias o salones, que no abren un registro por su dirección (supuesto por confirmar). Null si el
     * registro ya no existe o está borrado. Quién lo ve lo decide la Policy del registro en la pantalla.
     */
    public static function recordUrl(?Model $record): ?string
    {
        if ($record === null || (method_exists($record, 'trashed') && $record->trashed())) {
            return null;
        }

        return match (true) {
            $record instanceof StudyPlan => route('academic.plans.show', $record),
            $record instanceof Teacher => route('faculty.teachers.show', $record),
            $record instanceof Career => route('academic.careers'),
            $record instanceof Subject => route('academic.subjects'),
            $record instanceof Room => route('facilities.rooms'),
            default => null,
        };
    }

    /**
     * Las observaciones abiertas del periodo en los catálogos abiertos, con su revisión y departamento, el registro
     * (aunque se haya borrado, con el campus de un salón), quién la puso.
     *
     * @return Builder<CatalogReviewFlag>
     */
    private function openFlagsQuery(Term $term): Builder
    {
        return CatalogReviewFlag::query()
            ->whereNull('resolved_at')
            ->whereHas('review', fn ($query) => $query
                ->where('term_id', $term->getKey())
                ->whereIn('catalog', $this->catalogReviews->openedCatalogs($term)))
            ->with(['review.department', 'flaggable' => fn (MorphTo $morph) => $morph->morphWith([Room::class => ['campus']]), 'flagger']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matches(array $row, string $search, string $status): bool
    {
        if ($status === 'pending' && $row['validated'] === $row['total']) {
            return false;
        }

        if ($status === 'validated' && $row['validated'] !== $row['total']) {
            return false;
        }

        $needle = Str::lower(Str::ascii(trim($search)));

        if ($needle === '') {
            return true;
        }

        $haystack = Str::lower(Str::ascii($row['department']->code.' '.$row['department']->name.' '.($row['heads']->pluck('name')->implode(' '))));

        return str_contains($haystack, $needle);
    }
}
