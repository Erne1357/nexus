<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\RecipientsQuery;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa a los jefes que tienen catálogos por validar (tarea 2.9; reglas-de-negocio.md, «Periodo y
 * calendario»): a cada jefe de departamento vigente, el único que valida, de cada departamento activo con
 * revisiones pendientes del periodo en el campus, contadas igual que HC-14 (CatalogReviewQuery::pendingFor).
 * Con `$departmentId`, solo a los de ese departamento: el recordatorio del tablero de avance (tarea 2.5).
 * Lo llama NotifyPendingReviewsOnTermOpened al abrir el periodo. Da a cuántos usuarios avisó.
 */
final class NotifyPendingReviews
{
    public function __construct(
        private readonly CatalogReviewQuery $reviews,
        private readonly RecipientsQuery $recipients,
    ) {}

    public function handle(Term $term, Campus $campus, ?string $departmentId = null): int
    {
        $departments = Department::query()
            ->where('is_active', true)
            ->when($departmentId !== null, fn ($query) => $query->whereKey($departmentId))
            ->orderBy('code')
            ->get();

        $notified = 0;

        foreach ($departments as $department) {
            $pending = $this->reviews->pendingFor($term, $campus, $department);

            if ($pending === []) {
                continue;
            }

            $heads = $this->recipients->for(Role::JefeDepartamento, $campus, $department->getKey());

            Notification::send($heads, new CatalogReviewsPending($term, $campus, $department, $pending));

            $notified += $heads->count();
        }

        return $notified;
    }
}
