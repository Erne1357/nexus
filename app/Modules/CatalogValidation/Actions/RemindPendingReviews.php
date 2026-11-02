<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Events\PendingReviewsReminded;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\RecipientsQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El recordatorio del tablero de avance de validación (tarea 2.5): avisa a los jefes con catálogos por validar
 * (NotifyPendingReviews) en cada campus dado, de todos los departamentos activos o solo de `$departmentId`. Quién
 * puede lo decide CatalogReviewPolicy::remind antes de llamarla (supuesto por confirmar).
 *
 * Va departamento por departamento para dejar, en cada uno que avisó, una entrada de bitácora (evento `reminded`) con
 * quién recordó, a quién, en qué campus y cuántos avisos; de ahí sale el «último recordatorio» del tablero. Da a
 * cuántos usuarios distintos avisó (un jefe con avisos en varios campus o departamentos cuenta una vez).
 */
final class RemindPendingReviews
{
    public function __construct(
        private readonly NotifyPendingReviews $notify,
        private readonly RecipientsQuery $recipients,
    ) {}

    /**
     * @param  Collection<int, Campus>  $campuses
     */
    public function handle(Term $term, Collection $campuses, User $actor, ?string $departmentId = null): int
    {
        $query = Department::query()->where('is_active', true)->orderBy('code');
        $departments = $departmentId === null ? $query->get() : collect([KeyLookup::find($query, $departmentId)])->filter();

        return DB::transaction(function () use ($term, $campuses, $actor, $departments): int {
            /** @var array<string, true> $notifiedUsers */
            $notifiedUsers = [];

            foreach ($departments as $department) {
                $heads = collect();
                $codes = [];

                foreach ($campuses as $campus) {
                    if ($this->notify->handle($term, $campus, $department->getKey()) > 0) {
                        $heads = $heads->concat($this->recipients->for(Role::JefeDepartamento, $campus, $department->getKey()));
                        $codes[] = $campus->code;
                    }
                }

                $heads = $heads->unique('id')->values();

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

                PendingReviewsReminded::dispatch($term, $department, $heads->count());

                foreach ($heads as $head) {
                    $notifiedUsers[$head->getKey()] = true;
                }
            }

            return count($notifiedUsers);
        });
    }
}
