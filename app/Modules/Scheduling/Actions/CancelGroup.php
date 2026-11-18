<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Rules\SessionRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancela un grupo (tarea 8.3a, «Cerrar un grupo» del re-análisis; el permiso es GroupPolicy::cancel y lo revisa quien
 * llama). Un grupo cancelado no se borra (HC-15, GroupStatus): deja de contar para el validador, para los empalmes de la
 * base (el trigger de `groups` apaga sus sesiones) y para la carga de su docente, y la versión siguiente lo publica como
 * cancelado (SnapshotDiff). Su docente se queda en el grupo para el historial; el aviso le llega al republicar (RA-05).
 *
 * En una transacción toma la carga (FOR SHARE) y relee el grupo con bloqueo, como las Actions del editor, y exige la
 * carga editable y el grupo sin cancelar (LoadEditability). Cancela sus acuerdos vivos (préstamos de salón y cupos de
 * grupo compartido, propuestos o aceptados) con CancelAgreement, porque ya no hay grupo que los use (supuesto por
 * confirmar). Deja la bitácora `cancelled` en el grupo y avisa con GroupCancelled después del commit.
 */
final class CancelGroup
{
    public const string EVENT = 'cancelled';

    public function __construct(
        private readonly LoadEditability $editability,
        private readonly CancelAgreement $cancelAgreement,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Group $group, User $actor): Group
    {
        return $this->editability->guard(fn (): Group => DB::transaction(function () use ($group, $actor): Group {
            $this->editability->lockLoad($group);
            $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);

            $this->editability->ensure($group, 'grupos', 'group');

            $agreements = InterdepartmentalAgreement::query()->where('group_id', $group->getKey())->live()->orderBy('created_at')->get();

            foreach ($agreements as $agreement) {
                $this->cancelAgreement->handle($agreement, $actor);
            }

            $old = $group->status;
            $group->update(['status' => GroupStatus::Cancelled]);

            activity()
                ->performedOn($group)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withChanges(['old' => ['status' => $old->value], 'attributes' => ['status' => GroupStatus::Cancelled->value]])
                ->withProperties(['agreements_cancelled' => $agreements->count()])
                ->log('Canceló el grupo '.SessionRules::label($group));

            GroupCancelled::dispatch($group);

            return $group;
        }), 'group');
    }
}
