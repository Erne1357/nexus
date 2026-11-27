<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Queries\ReviewOwner;
use App\Modules\CatalogValidation\Queries\ValidationWindowQuery;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Avisa a los jefes que tienen catálogos por validar (tarea 2.9; reglas-de-negocio.md, «Periodo y
 * calendario»): a cada jefe vigente que valida, el único que valida, de cada departamento activo y de Servicios
 * Escolares (CV15, confirmada el 20 de noviembre de 2026), con lo que le falta de sus propios catálogos en el periodo
 * (CatalogReviewQuery::pendingOf: todo lo que el admin abrió, CV9): al jefe de un departamento, sus docentes y salones;
 * al de Servicios Escolares, las materias, los planes y las carreras de todo el Tec. El aviso no lleva campus: la
 * validación es de todo el Tec (decisión 6). Lleva la fecha límite, el fin de la ventana general de la etapa
 * (ValidationWindowQuery, CV11).
 * Con `$ownerId`, solo a los de ese departamento o de Servicios Escolares (ReviewOwner::SCHOOL_SERVICES): el
 * recordatorio del tablero de avance (tarea 2.5); un id que no es de un departamento activo no avisa a nadie.
 * Lo llama NotifyPendingReviewsJob al abrir la validación (OpenCatalogValidation). `handle()` da a cuántos usuarios avisó y `notify()`, cuáles.
 */
final class NotifyPendingReviews
{
    public function __construct(
        private readonly CatalogReviewQuery $reviews,
        private readonly CatalogContentsQuery $contents,
        private readonly ValidationWindowQuery $window,
        private readonly NotifyHolders $notifyHolders,
    ) {}

    /**
     * A cuántos usuarios avisó; un jefe de varios departamentos cuenta una vez por departamento.
     */
    public function handle(Term $term, ?string $ownerId = null): int
    {
        return $this->notify($term, $ownerId)->count();
    }

    /**
     * El aviso de que el admin abrió la validación (CV9): un aviso por jefe y dueño, con solo los catálogos recién
     * abiertos que le faltan. Lo pendiente y los jefes se leen una vez por dueño. Da los usuarios avisados.
     *
     * @param  list<Catalog>  $catalogs
     * @return Collection<int, User>
     */
    public function notifyOpened(Term $term, array $catalogs): Collection
    {
        $notified = new Collection;
        $deadline = $this->window->deadline($term);

        foreach ($this->reviews->activeOwners() as $owner) {
            $pending = array_values(array_filter(
                $this->reviews->pendingOf($term, $owner),
                fn (Catalog $catalog): bool => in_array($catalog, $catalogs, true),
            ));

            if ($pending === []) {
                continue;
            }

            $heads = $this->reviews->holdersOf($owner->id, CatalogValidationPermission::ReviewValidate);
            $notified = $notified->concat($this->notifyHolders->handle($heads, new CatalogReviewsPending($term, $owner, $pending, $deadline)));
        }

        return $notified;
    }

    /**
     * Avisa y da los usuarios avisados, uno por aviso enviado (un jefe de varios departamentos aparece una vez por
     * departamento), para que quien lo llama no vuelva a consultar los destinatarios (RemindPendingReviews). Con
     * `$reminder` es el recordatorio (CV11): con su propio título y cuánto lleva revisado de cada catálogo que falta (CV7).
     *
     * @return Collection<int, User>
     */
    public function notify(Term $term, ?string $ownerId = null, bool $reminder = false): Collection
    {
        $notified = new Collection;
        $deadline = $this->window->deadline($term);

        foreach ($this->reviews->activeOwners($ownerId === null ? null : [$ownerId]) as $owner) {
            $pending = $this->reviews->pendingOf($term, $owner);

            if ($pending === []) {
                continue;
            }

            $heads = $this->reviews->holdersOf($owner->id, CatalogValidationPermission::ReviewValidate);

            if ($heads === []) {
                continue;
            }

            $progress = $reminder ? $this->progressOf($term, $owner, $pending) : [];

            $notified = $notified->concat($this->notifyHolders->handle($heads, new CatalogReviewsPending($term, $owner, $pending, $deadline, $reminder, $progress)));
        }

        return $notified;
    }

    /**
     * «N de M revisados» de cada catálogo pendiente del dueño, por catálogo (CV7).
     *
     * @param  list<Catalog>  $pending
     * @return array<string, array{reviewed: int, total: int}>
     */
    private function progressOf(Term $term, ReviewOwner $owner, array $pending): array
    {
        $reviews = $this->reviews->forOwner($term, $owner)
            ->filter(fn (CatalogReview $review): bool => in_array($review->catalog, $pending, true))
            ->values();
        $progress = $this->contents->progressOf($reviews);

        return $reviews->mapWithKeys(fn (CatalogReview $review): array => [$review->catalog->value => $progress[$review->getKey()]])->all();
    }
}
