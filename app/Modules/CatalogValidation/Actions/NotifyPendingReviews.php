<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Queries\ValidationWindowQuery;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\RecipientsQuery;
use Illuminate\Database\Eloquent\Collection;

/**
 * Avisa a los jefes que tienen catálogos por validar (tarea 2.9; reglas-de-negocio.md, «Periodo y
 * calendario»): a cada jefe de departamento vigente, el único que valida, de cada departamento activo con
 * revisiones pendientes del periodo, contadas igual que HC-14 (CatalogReviewQuery::pendingFor, sin modalidad: todo lo
 * que el admin abrió en el periodo, CV9). El aviso no lleva campus: la validación es de todo el Tec (decisión 6). Lleva
 * la fecha límite, el fin de la ventana general de la etapa (ValidationWindowQuery, CV11).
 * Con `$departmentId`, solo a los de ese departamento: el recordatorio del tablero de avance (tarea 2.5); un id
 * que no es de un departamento activo no avisa a nadie.
 * Lo llama NotifyPendingReviewsJob al abrir la validación (OpenCatalogValidation). `handle()` da a cuántos usuarios avisó y `notify()`, cuáles.
 */
final class NotifyPendingReviews
{
    public function __construct(
        private readonly CatalogReviewQuery $reviews,
        private readonly CatalogContentsQuery $contents,
        private readonly ValidationWindowQuery $window,
        private readonly RecipientsQuery $recipients,
        private readonly NotifyHolders $notifyHolders,
    ) {}

    /**
     * A cuántos usuarios avisó; un jefe de varios departamentos cuenta una vez por departamento.
     */
    public function handle(Term $term, ?string $departmentId = null): int
    {
        return $this->notify($term, $departmentId)->count();
    }

    /**
     * El aviso de que el admin abrió la validación (CV9): un aviso por jefe y departamento, con solo los catálogos recién
     * abiertos que le faltan en ese departamento. Lo pendiente y los jefes se leen una vez por departamento. Da los
     * usuarios avisados.
     *
     * @param  list<Catalog>  $catalogs
     * @return Collection<int, User>
     */
    public function notifyOpened(Term $term, array $catalogs): Collection
    {
        $notified = new Collection;
        $deadline = $this->window->deadline($term);

        foreach (Department::query()->where('is_active', true)->orderBy('code')->get() as $department) {
            $pending = array_values(array_filter(
                $this->reviews->pendingFor($term, null, $department),
                fn (Catalog $catalog): bool => in_array($catalog, $catalogs, true),
            ));

            if ($pending === []) {
                continue;
            }

            $heads = $this->recipients->holders(CatalogValidationPermission::ReviewValidate, Target::of(unit: $department->getKey()));
            $notified = $notified->concat($this->notifyHolders->handle($heads, new CatalogReviewsPending($term, $department, $pending, $deadline)));
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
    public function notify(Term $term, ?string $departmentId = null, bool $reminder = false): Collection
    {
        $query = Department::query()->where('is_active', true)->orderBy('code');

        if ($departmentId !== null) {
            $department = KeyLookup::find($query, $departmentId);
            $departments = $department === null ? collect() : collect([$department]);
        } else {
            $departments = $query->get();
        }

        $notified = new Collection;
        $deadline = $this->window->deadline($term);

        foreach ($departments as $department) {
            $pending = $this->reviews->pendingFor($term, null, $department);

            if ($pending === []) {
                continue;
            }

            $heads = $this->recipients->holders(CatalogValidationPermission::ReviewValidate, Target::of(unit: $department->getKey()));

            if ($heads === []) {
                continue;
            }

            $progress = $reminder ? $this->progressOf($term, $department, $pending) : [];

            $notified = $notified->concat($this->notifyHolders->handle($heads, new CatalogReviewsPending($term, $department, $pending, $deadline, $reminder, $progress)));
        }

        return $notified;
    }

    /**
     * «N de M revisados» de cada catálogo pendiente del departamento, por catálogo (CV7).
     *
     * @param  list<Catalog>  $pending
     * @return array<string, array{reviewed: int, total: int}>
     */
    private function progressOf(Term $term, Department $department, array $pending): array
    {
        $reviews = CatalogReview::query()
            ->where('term_id', $term->getKey())
            ->where('department_id', $department->getKey())
            ->whereIn('catalog', $pending)
            ->get();
        $progress = $this->contents->progressOf($reviews);

        return $reviews->mapWithKeys(fn (CatalogReview $review): array => [$review->catalog->value => $progress[$review->getKey()]])->all();
    }
}
