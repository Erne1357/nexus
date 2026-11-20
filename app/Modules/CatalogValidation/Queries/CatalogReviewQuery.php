<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Events\CatalogReviewReopening;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\CampusQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lecturas de las revisiones de catálogo.
 */
final class CatalogReviewQuery
{
    public function __construct(
        private readonly CatalogContentsQuery $contents,
        private readonly CampusQuery $campuses,
    ) {}

    /**
     * Los catálogos que todavía bloquean la salida de borrador de la carga de un departamento en una modalidad (HC-14,
     * M1): cada catálogo que exige la modalidad (todos, y los salones solo si su perfil usa salones) cuya revisión del
     * periodo falta o no está validada. Docentes y salones son revisiones del departamento para todo el Tec (la de
     * salones cubre todos los campus, decisión 6, confirmada el 12 de noviembre de 2026); materias, planes y carreras,
     * la revisión de Servicios Escolares del Tec, que cuenta para todos los departamentos (CV15, confirmada el 20 de
     * noviembre de 2026). En el orden del enum Catalog.
     *
     * Con `$lock` las revisiones que lee quedan con bloqueo compartido (FOR SHARE) hasta el fin de la
     * transacción: quien valida HC-14 dentro de una transacción (CatalogsValidated, desde TransitionLoad)
     * impide que otra sesión des-valide un catálogo entre la lectura y el cambio de estado. Fuera de una
     * transacción no tiene efecto.
     *
     * Solo cuentan los catálogos que el admin abrió en el periodo, es decir, que tienen revisiones en él (CV9, HC-14,
     * confirmado el 12 de noviembre de 2026): uno que no se abrió no se exige, y sin ninguno abierto no falta nada.
     *
     * `$modality` es la modalidad de la carga: sin salones (o con perfil guardado inválido, Modality::valid_profile), no
     * exige la revisión de salones. Sin modalidad cuenta todo lo abierto. Lo que le toca validar a cada quien (el aviso a
     * los jefes, Inicio) es pendingOf().
     *
     * @return list<Catalog>
     */
    public function pendingFor(Term|string $term, ?Modality $modality, Department|string $department, bool $lock = false): array
    {
        $termId = $term instanceof Term ? $term->getKey() : $term;
        $departmentId = $department instanceof Department ? $department->getKey() : $department;

        $required = Catalog::requiredFor($modality === null || $modality->valid_profile?->usesRooms === true);

        $validated = CatalogReview::query()
            ->where('term_id', $termId)
            ->where(fn ($query) => $query->where('department_id', $departmentId)->orWhereNull('department_id'))
            ->where('status', ReviewStatus::Validated)
            ->whereIn('catalog', $required)
            ->when($lock, fn ($query) => $query->sharedLock())
            ->pluck('catalog')
            ->all();

        $opened = array_map(fn (Catalog $catalog): string => $catalog->value, $this->openedCatalogs($termId));

        return array_values(array_filter($required, fn (Catalog $catalog): bool => ! in_array($catalog, $validated, true)
            && in_array($catalog->value, $opened, true)));
    }

    /**
     * Lo que le falta validar a `$owner` en el periodo (CV15): de sus catálogos (ReviewOwner::catalogs), los abiertos
     * (CV9) cuya revisión falta o no está validada, en el orden del enum. Lo que dicen el aviso de apertura, el
     * recordatorio (CV11) y la tarjeta de Inicio (CV-S13): al jefe de un departamento, sus docentes y salones; al de
     * Servicios Escolares, las materias, los planes y las carreras.
     *
     * @return list<Catalog>
     */
    public function pendingOf(Term|string $term, ReviewOwner $owner): array
    {
        $termId = $term instanceof Term ? $term->getKey() : $term;

        $validated = CatalogReview::query()
            ->where('term_id', $termId)
            ->tap(fn ($query) => self::ownedBy($query, $owner->departmentId))
            ->where('status', ReviewStatus::Validated)
            ->pluck('catalog')
            ->all();

        $opened = $this->openedCatalogs($termId);

        return array_values(array_filter($owner->catalogs(), fn (Catalog $catalog): bool => in_array($catalog, $opened, true)
            && ! in_array($catalog, $validated, true)));
    }

    /**
     * Los catálogos con revisiones abiertas en el periodo (CV9), en el orden del enum Catalog. Sin ninguno, la validación
     * del periodo no está abierta.
     *
     * @return list<Catalog>
     */
    public function openedCatalogs(Term|string $term): array
    {
        $opened = CatalogReview::query()
            ->where('term_id', $term instanceof Term ? $term->getKey() : $term)
            ->distinct()
            ->pluck('catalog')
            ->all();

        return array_values(array_filter(Catalog::cases(), fn (Catalog $catalog): bool => in_array($catalog, $opened, true)));
    }

    /**
     * Si la revisión de salones aplica al periodo: alguna modalidad donde está abierto usa salones y hay un campus activo
     * donde se dan sus grupos (CV9, decisión 6; M4; CampusQuery::openIn).
     */
    public function roomsApply(Term|string $term): bool
    {
        return $this->campuses->openIn($term)->isNotEmpty();
    }

    /**
     * Los catálogos del periodo para el drawer «Abrir validación»: cada uno con si ya está abierto y, si no se puede
     * abrir, el motivo (los salones sin un campus abierto que use salones).
     *
     * @return list<array{catalog: Catalog, open: bool, blocked: ?string}>
     */
    public function openingOptions(Term $term): array
    {
        $opened = $this->openedCatalogs($term);
        $roomsApply = $this->roomsApply($term);

        return array_map(fn (Catalog $catalog): array => [
            'catalog' => $catalog,
            'open' => in_array($catalog, $opened, true),
            'blocked' => $catalog === Catalog::Rooms && ! $roomsApply && ! in_array($catalog, $opened, true)
                ? 'Ninguna modalidad abierta en este periodo usa salones.'
                : null,
        ], Catalog::cases());
    }

    /**
     * Los departamentos cuya revisión puede abrir el usuario, por clave: los que cubre como jefe y, en lectura, todos si
     * es Subdirección o admin (CatalogReviewPolicy::view, CV-S1). Solo los activos y, si se desactivó después de abrir
     * la validación, uno que tiene revisiones en el periodo activo, para que su jefe la termine: las revisiones se crean
     * para los activos (CV9), así que un inactivo sin ellas no se ofrece ni se abre (tarea 1.21).
     *
     * @return Collection<int, Department>
     */
    public function departmentsFor(Actor $context): Collection
    {
        $termId = $context->activeTerm()?->getKey();

        return Department::query()
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->when($termId !== null, fn ($query) => $query->orWhereIn('id', CatalogReview::query()->where('term_id', $termId)->whereNotNull('department_id')->select('department_id'))))
            ->orderBy('code')
            ->get()
            ->filter(fn (Department $department): bool => self::allowsOn($context, CatalogValidationPermission::ReviewView, $department->getKey()))
            ->values();
    }

    /**
     * De quién son las revisiones que puede abrir el usuario (CV15): Servicios Escolares primero, si la ve (su jefe, y
     * en lectura Subdirección y el admin, CV-S1), y después sus departamentos (departmentsFor()).
     *
     * @return Collection<int, ReviewOwner>
     */
    public function ownersFor(Actor $context): Collection
    {
        $owners = $this->departmentsFor($context)->map(fn (Department $department): ReviewOwner => ReviewOwner::ofDepartment($department));

        return self::allowsOn($context, CatalogValidationPermission::ReviewView, ReviewOwner::SCHOOL_SERVICES)
            ? $owners->prepend(ReviewOwner::schoolServices())->values()
            : $owners->values();
    }

    /**
     * Quienes validan en el periodo (CV15): Servicios Escolares y después los departamentos activos, por clave. Con
     * `$ownerIds`, solo esos (un id que no es de Servicios Escolares ni de un departamento activo no cuenta). A quién
     * avisan la apertura y el recordatorio (CV11).
     *
     * @param  list<string>|null  $ownerIds
     * @return Collection<int, ReviewOwner>
     */
    public function activeOwners(?array $ownerIds = null): Collection
    {
        $departmentIds = $ownerIds === null ? null : array_values(array_filter($ownerIds, fn (string $id): bool => Str::isUuid($id)));

        $owners = Department::query()
            ->where('is_active', true)
            ->when($departmentIds !== null, fn ($query) => $query->whereIn('id', $departmentIds))
            ->orderBy('code')
            ->get()
            ->map(fn (Department $department): ReviewOwner => ReviewOwner::ofDepartment($department));

        return $ownerIds === null || in_array(ReviewOwner::SCHOOL_SERVICES, $ownerIds, true)
            ? $owners->prepend(ReviewOwner::schoolServices())->values()
            : $owners->values();
    }

    /**
     * Sobre qué se pide un permiso de las revisiones de `$ownerId` (un id de departamento o ReviewOwner::SCHOOL_SERVICES).
     * Las revisiones no son de un campus ni de una modalidad (AZ-S17), así que solo cuenta la unidad: la del departamento
     * o, en las de Servicios Escolares (CV15), la unidad de Servicios Escolares y la Subdirección Académica, que las ve y
     * resuelve sus observaciones como las de los departamentos (CV-S1) aunque Servicios Escolares no cuelgue de ella
     * (R-V2-5). Sin esas unidades en el organigrama, solo las alcanza quien no se acota por unidad (el admin).
     */
    public static function targetOf(string $ownerId): Target
    {
        if ($ownerId !== ReviewOwner::SCHOOL_SERVICES) {
            return Target::of(unit: $ownerId);
        }

        $tree = app(OrgTree::class);

        return Target::of(unit: array_values(array_filter([$tree->idOf(OrgTree::SCHOOL_SERVICES), $tree->idOf(OrgTree::ACADEMIC)])));
    }

    /**
     * Si `$permission` vale sobre las revisiones de `$ownerId` (targetOf()). Es la regla única de CatalogReviewPolicy y
     * de las pantallas.
     */
    public static function allowsOn(Actor $context, CatalogValidationPermission $permission, string $ownerId): bool
    {
        return $context->allows($permission, self::targetOf($ownerId));
    }

    /**
     * Las revisiones de un dueño en un periodo, para todo el Tec (la de salones, si existe, cubre todos los campus;
     * decisión 6), en el orden del enum Catalog: las de un departamento, sus docentes y salones; las de Servicios
     * Escolares, sus materias, planes y carreras (CV15).
     *
     * @return Collection<int, CatalogReview>
     */
    public function forOwner(Term|string $term, ReviewOwner $owner): Collection
    {
        $order = array_flip(array_map(fn (Catalog $catalog): string => $catalog->value, Catalog::cases()));

        return CatalogReview::query()
            ->where('term_id', $term instanceof Term ? $term->getKey() : $term)
            ->tap(fn ($query) => self::ownedBy($query, $owner->departmentId))
            ->get()
            ->sortBy(fn (CatalogReview $review): int => $order[$review->catalog->value])
            ->values();
    }

    /**
     * Las revisiones del departamento dado o, con null, las de Servicios Escolares, que no tienen departamento (CV15).
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function ownedBy(Builder $query, ?string $departmentId, string $column = 'department_id'): Builder
    {
        return $departmentId === null ? $query->whereNull($column) : $query->where($column, $departmentId);
    }

    /**
     * La última revisión validada, con foto, del mismo departamento y catálogo en un periodo que empieza antes que el
     * de `$review` (CV3, confirmado el 2 de noviembre de 2026): contra su foto se cuentan las altas y las bajas. En las de
     * Servicios Escolares, la última de Servicios Escolares del mismo catálogo en el Tec (CV15). Una reabierta no cuenta aunque conserve su foto (CV-S6, supuesto por confirmar). Nula en el primer periodo.
     */
    public function previousValidation(CatalogReview $review): ?CatalogReview
    {
        $review->loadMissing('term');

        return CatalogReview::query()
            ->select('catalog_reviews.*')
            ->join('terms', 'terms.id', '=', 'catalog_reviews.term_id')
            ->tap(fn ($query) => self::ownedBy($query, $review->department_id, 'catalog_reviews.department_id'))
            ->where('catalog_reviews.catalog', $review->catalog)
            ->where('catalog_reviews.status', ReviewStatus::Validated)
            ->whereNotNull('catalog_reviews.snapshot')
            ->where('terms.starts_on', '<', $review->term->starts_on->toDateString())
            ->orderByDesc('terms.starts_on')
            ->first();
    }

    /**
     * Lo que muestra el bloque de una revisión: cuántos registros activos e inactivos tiene su catálogo, las altas
     * (activos que no estaban en la foto anterior) y las bajas (de la foto anterior que ya no están activos, porque
     * se desactivaron, se borraron o dejaron el departamento), con la clave y el nombre de la foto; `null` las dos
     * si no hay foto anterior. Y cuántas observaciones siguen abiertas.
     *
     * @return array{active: int, inactive: int, added: ?list<array{id: string, code: string, name: ?string}>, removed: ?list<array{id: string, code: string, name: ?string}>, open_flags: int}
     */
    public function summary(CatalogReview $review): array
    {
        $records = $this->contents->records($review);
        $active = array_values(array_filter($records, fn (array $record): bool => $record['is_active']));
        $previous = $this->previousValidation($review)?->snapshot;
        $added = $removed = null;

        if ($previous !== null) {
            $previousIds = array_flip(array_column($previous, 'id'));
            $activeIds = array_flip(array_column($active, 'id'));

            $added = array_values(array_map(
                fn (array $record): array => ['id' => $record['id'], 'code' => $record['code'], 'name' => $record['name']],
                array_filter($active, fn (array $record): bool => ! isset($previousIds[$record['id']])),
            ));
            $removed = array_values(array_filter($previous, fn (array $record): bool => ! isset($activeIds[$record['id']])));
        }

        return [
            'active' => count($active),
            'inactive' => count($records) - count($active),
            'added' => $added,
            'removed' => $removed,
            'open_flags' => $review->flags()->whereNull('resolved_at')->count(),
        ];
    }

    /**
     * Los motivos por los que reabrir la revisión se vetaría hoy (CV5), para que la pantalla deshabilite «Reabrir»
     * con su motivo. Despacha CatalogReviewReopening sin transacción ni bloqueo: sus listeners solo leen (el de
     * AcademicLoad cuenta las cargas del departamento que ya salieron de borrador), y ReopenReview vuelve a
     * preguntar, ya con bloqueo, al escribir. Sin motivos, la lista va vacía.
     *
     * @return list<string>
     */
    public function reopeningVetoes(CatalogReview $review): array
    {
        $event = new CatalogReviewReopening($review);
        event($event);

        return $event->reasons();
    }
}
