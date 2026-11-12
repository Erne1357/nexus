<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\RecipientsQuery;
use Illuminate\Database\Eloquent\Collection;

/**
 * Avisa a los jefes que tienen catálogos por validar (tarea 2.9; reglas-de-negocio.md, «Periodo y
 * calendario»): a cada jefe de departamento vigente, el único que valida, de cada departamento activo con
 * revisiones pendientes del periodo en el campus, contadas igual que HC-14 (CatalogReviewQuery::pendingFor) pero solo las que el admin abrió en el periodo (CV9).
 * Con `$departmentId`, solo a los de ese departamento: el recordatorio del tablero de avance (tarea 2.5); un id
 * que no es de un departamento activo no avisa a nadie.
 * Lo llama NotifyPendingReviewsJob al abrir la validación (OpenCatalogValidation). `handle()` da a cuántos usuarios avisó y `notify()`, cuáles.
 */
final class NotifyPendingReviews
{
    public function __construct(
        private readonly CatalogReviewQuery $reviews,
        private readonly RecipientsQuery $recipients,
        private readonly NotifyHolders $notifyHolders,
    ) {}

    /**
     * A cuántos usuarios avisó; un jefe de varios departamentos cuenta una vez por departamento.
     */
    public function handle(Term $term, Campus $campus, ?string $departmentId = null): int
    {
        return $this->notify($term, $campus, $departmentId)->count();
    }

    /**
     * El aviso de que el admin abrió la validación (CV9): un aviso por jefe y departamento, aunque el jefe cubra varios de
     * los campus dados (los jefes son de todo el Tec), con solo los catálogos recién abiertos que le faltan en ese
     * departamento. Da los usuarios avisados.
     *
     * @param  Collection<int, Campus>  $campuses
     * @param  list<Catalog>  $catalogs
     * @return Collection<int, User>
     */
    public function notifyOpened(Term $term, Collection $campuses, array $catalogs): Collection
    {
        $notified = new Collection;

        foreach (Department::query()->where('is_active', true)->orderBy('code')->get() as $department) {
            $reached = [];

            foreach ($campuses as $campus) {
                $pending = array_values(array_filter(
                    $this->reviews->pendingFor($term, $campus, $department),
                    fn (Catalog $catalog): bool => in_array($catalog, $catalogs, true),
                ));

                if ($pending === []) {
                    continue;
                }

                $heads = array_values(array_filter(
                    $this->recipients->holders(CatalogValidationPermission::ReviewValidate, Target::of(campus: $campus->getKey(), unit: $department->getKey())),
                    fn (array $holder): bool => ! isset($reached[$holder['user']->getKey()]),
                ));

                foreach ($heads as $holder) {
                    $reached[$holder['user']->getKey()] = true;
                }

                $notified = $notified->concat($this->notifyHolders->handle($heads, new CatalogReviewsPending($term, $campus, $department, $pending)));
            }
        }

        return $notified;
    }

    /**
     * Avisa y da los usuarios avisados, uno por aviso enviado (un jefe de varios departamentos aparece una vez por
     * departamento), para que quien lo llama no vuelva a consultar los destinatarios (RemindPendingReviews).
     *
     * @return Collection<int, User>
     */
    public function notify(Term $term, Campus $campus, ?string $departmentId = null): Collection
    {
        $query = Department::query()->where('is_active', true)->orderBy('code');

        if ($departmentId !== null) {
            $department = KeyLookup::find($query, $departmentId);
            $departments = $department === null ? collect() : collect([$department]);
        } else {
            $departments = $query->get();
        }

        $notified = new Collection;

        foreach ($departments as $department) {
            $pending = $this->reviews->pendingFor($term, $campus, $department);

            if ($pending === []) {
                continue;
            }

            $heads = $this->recipients->holders(CatalogValidationPermission::ReviewValidate, Target::of(campus: $campus->getKey(), unit: $department->getKey()));

            $notified = $notified->concat($this->notifyHolders->handle($heads, new CatalogReviewsPending($term, $campus, $department, $pending)));
        }

        return $notified;
    }
}
