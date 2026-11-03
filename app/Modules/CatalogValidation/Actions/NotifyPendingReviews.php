<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\Academic\Models\Department;
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
 * revisiones pendientes del periodo en el campus, contadas igual que HC-14 (CatalogReviewQuery::pendingFor).
 * Con `$departmentId`, solo a los de ese departamento: el recordatorio del tablero de avance (tarea 2.5); un id
 * que no es de un departamento activo no avisa a nadie.
 * Lo llama NotifyPendingReviewsOnTermOpened al abrir el periodo. `handle()` da a cuántos usuarios avisó y `notify()`, cuáles.
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
