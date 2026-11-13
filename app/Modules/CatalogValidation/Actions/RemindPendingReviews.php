<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Collection;

/**
 * El recordatorio del tablero de avance de validación (tarea 2.5): avisa a los jefes con catálogos por validar
 * (NotifyPendingReviews::notify) de los departamentos activos de `$departmentIds` o, sin lista, de todos. Lo pendiente
 * y los jefes no dependen del campus (la revisión es del departamento en todo el Tec), así que avisa una sola vez por
 * departamento aunque el tablero muestre varios campus («Todos»), con el primero, como el aviso de apertura
 * (NotifyPendingReviews::notifyOpened). Quién puede lo decide CatalogReviewPolicy::remind antes de llamarla (supuesto
 * por confirmar).
 *
 * Va departamento por departamento, sin una transacción que envuelva el ciclo: en cada uno que avisó deja una
 * entrada de bitácora (evento `reminded`) con quién recordó, a quién, los campus del tablero y cuántos jefes; de ahí sale
 * el «último recordatorio» del tablero en cualquiera de esos campus. Da a cuántos usuarios distintos avisó (un jefe de
 * varios departamentos cuenta una vez).
 */
final class RemindPendingReviews
{
    public function __construct(private readonly NotifyPendingReviews $notify) {}

    /**
     * @param  Collection<int, Campus>  $campuses
     * @param  list<string>|null  $departmentIds
     */
    public function handle(Term $term, Collection $campuses, User $actor, ?array $departmentIds = null): int
    {
        $departments = Department::query()
            ->where('is_active', true)
            ->when($departmentIds !== null, fn ($query) => $query->whereIn('id', $departmentIds))
            ->orderBy('code')
            ->get();

        $notifiedUsers = [];

        $campus = $campuses->first();

        if ($campus === null) {
            return 0;
        }

        $codes = $campuses->pluck('code')->values()->all();

        foreach ($departments as $department) {
            $heads = $this->notify->notify($term, $campus, $department->getKey())->unique('id')->values();

            if ($heads->isEmpty()) {
                continue;
            }

            $names = $heads->pluck('name')->all();

            activity()
                ->performedOn($department)
                ->causedBy($actor)
                ->event('reminded')
                ->withProperties(['term_id' => $term->getKey(), 'campuses' => $codes, 'notified' => $heads->count(), 'recipients' => $names])
                ->log('Recordó a '.implode(', ', $names)." validar los catálogos de {$department->name} en {$term->name}");

            foreach ($heads as $head) {
                $notifiedUsers[$head->getKey()] = true;
            }
        }

        return count($notifiedUsers);
    }
}
