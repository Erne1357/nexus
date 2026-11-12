<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Events\CatalogReviewReopening;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Support\Collection;

/**
 * Lecturas de las revisiones de catálogo.
 */
final class CatalogReviewQuery
{
    public function __construct(private readonly CatalogContentsQuery $contents) {}

    /**
     * Los catálogos que todavía bloquean la salida de borrador de la carga de un departamento en un campus
     * (HC-14): cada catálogo que exige el campus (todos, y los salones solo si su perfil usa salones) cuya revisión del
     * periodo y el departamento falta o no está validada. Todas las revisiones son del departamento para todo el Tec,
     * también la de salones (decisión 6, confirmada el 12 de noviembre de 2026). En el orden del enum Catalog.
     *
     * Con `$lock` las revisiones que lee quedan con bloqueo compartido (FOR SHARE) hasta el fin de la
     * transacción: quien valida HC-14 dentro de una transacción (CatalogsValidated, desde TransitionLoad)
     * impide que otra sesión des-valide un catálogo entre la lectura y el cambio de estado. Fuera de una
     * transacción no tiene efecto.
     *
     * @return list<Catalog>
     */
    public function pendingFor(Term|string $term, Campus $campus, Department|string $department, bool $lock = false): array
    {
        $termId = $term instanceof Term ? $term->getKey() : $term;
        $departmentId = $department instanceof Department ? $department->getKey() : $department;

        $required = Catalog::requiredFor($campus->profile->usesRooms);

        $validated = CatalogReview::query()
            ->where('term_id', $termId)
            ->where('department_id', $departmentId)
            ->where('status', ReviewStatus::Validated)
            ->whereIn('catalog', $required)
            ->when($lock, fn ($query) => $query->sharedLock())
            ->pluck('catalog')
            ->all();

        return array_values(array_filter($required, fn (Catalog $catalog): bool => ! in_array($catalog, $validated, true)));
    }

    /**
     * Los departamentos cuya revisión puede abrir el usuario, por clave: los que cubre como jefe y, en lectura, todos
     * si es Subdirección o admin (CatalogReviewPolicy::view, CV-S1).
     *
     * @return Collection<int, Department>
     */
    public function departmentsFor(Actor $context): Collection
    {
        return Department::query()
            ->orderBy('code')
            ->get()
            ->filter(fn (Department $department): bool => self::allowsOnDepartment($context, CatalogValidationPermission::ReviewView, null, $department->getKey()))
            ->values();
    }

    /**
     * Si `$permission` vale sobre el departamento en el campus dado o, sin campus (las revisiones no son de uno), en
     * alguno de los campus del usuario. Es la regla única de CatalogReviewPolicy y de las pantallas.
     */
    public static function allowsOnDepartment(Actor $context, CatalogValidationPermission $permission, ?string $campus, string $department): bool
    {
        return $context->allows($permission, Target::of(campus: $campus ?? $context->campuses()->map->getKey()->all(), unit: $department));
    }

    /**
     * Las revisiones de un departamento en un periodo, para todo el Tec (la de salones, si existe, cubre todos los
     * campus; decisión 6), en el orden del enum Catalog.
     *
     * @return Collection<int, CatalogReview>
     */
    public function forDepartment(Term|string $term, Department|string $department): Collection
    {
        $order = array_flip(array_map(fn (Catalog $catalog): string => $catalog->value, Catalog::cases()));

        return CatalogReview::query()
            ->where('term_id', $term instanceof Term ? $term->getKey() : $term)
            ->where('department_id', $department instanceof Department ? $department->getKey() : $department)
            ->get()
            ->sortBy(fn (CatalogReview $review): int => $order[$review->catalog->value])
            ->values();
    }

    /**
     * La última revisión validada, con foto, del mismo departamento y catálogo en un periodo que empieza antes que el
     * de `$review` (CV3, confirmado el 2 de noviembre de 2026): contra su foto se cuentan las altas y las bajas. Una reabierta no cuenta aunque conserve su foto (CV-S6, supuesto por confirmar). Nula en el primer periodo.
     */
    public function previousValidation(CatalogReview $review): ?CatalogReview
    {
        $review->loadMissing('term');

        return CatalogReview::query()
            ->select('catalog_reviews.*')
            ->join('terms', 'terms.id', '=', 'catalog_reviews.term_id')
            ->where('catalog_reviews.department_id', $review->department_id)
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
