<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Modality;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Staffing\Models\ChangeRequest;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Los candados de una solicitud de cambio, en un solo orden para todas las Actions que la deciden o la aplican (el de
 * TransitionLoad y el editor de horarios): la modalidad FOR SHARE, la carga del departamento FOR UPDATE, el grupo FOR
 * UPDATE y, al final, la solicitud. Ninguna toma la solicitud antes que la carga: dos personas que aprueban y rechazan
 * la misma solicitud a la vez esperan en vez de trabarse. La carga va FOR UPDATE y no FOR SHARE porque aprobar la
 * pasa a ajustes en la misma transacción (subir de candado entre dos aprobaciones es un deadlock). guard() deja que un
 * deadlock (40P01) salga como error de validación y no como un 500.
 */
final class LocksChangeRequestScope
{
    public function __construct(private readonly LoadEditability $editability) {}

    /**
     * Toma los candados (dentro de una transacción), deja `$request` con los datos de la base y devuelve la carga del
     * departamento del grupo, la que vale ahora.
     */
    public function lock(ChangeRequest $request): ?AcademicLoad
    {
        $group = Group::query()->findOrFail($request->group_id, ['id', 'term_id', 'modality_id', 'department_id']);

        Modality::withTrashed()->whereKey($group->modality_id)->sharedLock()->firstOrFail();

        $load = AcademicLoad::query()
            ->ofDepartment($group->term_id, $group->modality_id, $group->department_id)
            ->lockForUpdate()
            ->first();

        Group::query()->whereKey($group->getKey())->lockForUpdate()->first(['id']);
        $request->setRawAttributes($request->newQuery()->lockForUpdate()->findOrFail($request->getKey())->getAttributes(), sync: true);

        return $load;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws ValidationException
     */
    public function guard(Closure $work): mixed
    {
        return $this->editability->guard($work, 'status', 'Otra persona está decidiendo esta solicitud; intenta de nuevo.');
    }
}
