<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Queries\ReviewOwner;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/**
 * El recordatorio del tablero de avance de validación (tarea 2.5): avisa a los jefes con catálogos por validar
 * (NotifyPendingReviews::notify, como recordatorio: con su título, lo que lleva revisado y la fecha límite, por la
 * campana y el correo; CV11) de los departamentos activos y de Servicios Escolares (CV15) de `$ownerIds` (ids de
 * departamento o ReviewOwner::SCHOOL_SERVICES) o, sin lista, de todos. Lo pendiente y los jefes no dependen del campus
 * (la revisión es de todo el Tec), así que avisa una sola vez por dueño. Quién puede lo decide
 * CatalogReviewPolicy::remind antes de llamarla (supuesto por confirmar).
 *
 * Va dueño por dueño, sin una transacción que envuelva el ciclo: en cada uno que avisó deja una entrada de bitácora
 * (evento `reminded`) con quién recordó, a quién y cuántos jefes, sobre el departamento o, en Servicios Escolares, sobre
 * su unidad del organigrama (con `owner` en sus propiedades); de ahí sale el «último recordatorio» del tablero. Da a
 * cuántos usuarios distintos avisó (un jefe de varios departamentos cuenta una vez).
 */
final class RemindPendingReviews
{
    public function __construct(
        private readonly NotifyPendingReviews $notify,
        private readonly CatalogReviewQuery $reviews,
    ) {}

    /**
     * @param  list<string>|null  $ownerIds
     */
    public function handle(Term $term, User $actor, ?array $ownerIds = null): int
    {
        $notifiedUsers = [];

        foreach ($this->reviews->activeOwners($ownerIds) as $owner) {
            $heads = $this->notify->notify($term, $owner->id, reminder: true)->unique('id')->values();

            if ($heads->isEmpty()) {
                continue;
            }

            $names = $heads->pluck('name')->all();
            $subject = $owner->isSchoolServices()
                ? OrgUnit::query()->whereKey(app(OrgTree::class)->idOf(OrgTree::SCHOOL_SERVICES))->first()
                : Department::query()->whereKey($owner->departmentId)->first();

            activity()
                ->when($subject !== null, fn ($logger) => $logger->performedOn($subject))
                ->causedBy($actor)
                ->event('reminded')
                ->withProperties(['term_id' => $term->getKey(), 'owner' => $owner->id, 'notified' => $heads->count(), 'recipients' => $names])
                ->log('Recordó a '.implode(', ', $names)." validar los catálogos de {$owner->name} en {$term->name}");

            foreach ($heads as $head) {
                $notifiedUsers[$head->getKey()] = true;
            }
        }

        return count($notifiedUsers);
    }
}
