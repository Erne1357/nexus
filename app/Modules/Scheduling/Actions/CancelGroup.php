<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupsCancelling;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Rules\SessionRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancela un grupo (reglas-de-negocio.md, «Grupos y horarios»: un grupo no se borra, se cancela). La fase 3 dejó el estado,
 * el evento GroupCancelled y la ability GroupPolicy::cancel, pero no la Action; esta es la que usa armar paralelos (tarea
 * 8.8) para los grupos que sobran. No autoriza adentro, a propósito: su único llamador, ArmParallelGroups, ya autorizó
 * `ParallelsPolicy::arrange`, que cubre cancelar los grupos sueltos de la materia aunque un rol editable no tenga
 * `scheduling.group.manage`; una pantalla que la llame suelta debe autorizar antes GroupPolicy::cancel.
 *
 * En una transacción toma la carga (FOR SHARE), relee el grupo con bloqueo y exige la carga editable y el grupo sin
 * cancelar (LoadEditability). Un grupo con docente no se cancela: primero se le quita (confirmado el 18 de noviembre de
 * 2026 para los paralelos; para todo grupo, supuesto por confirmar). Antes de escribir pregunta con el evento con veto
 * GroupsCancelling: Staffing lo bloquea si el grupo tiene una solicitud de cambio pendiente o aprobada (supuesto por
 * confirmar). Al cancelarlo:
 * - sus sesiones se borran (como RemoveGroupSession, no son catálogo): un grupo cancelado pierde su horario y lo que tenía
 *   queda en su bitácora;
 * - sus acuerdos vivos (préstamos de salón y grupos compartidos) se cancelan con CancelAgreement; el de un préstamo avisa al
 *   dueño del salón por la vía de siempre (AgreementCancelled → NotifyOwnerOnLoanCancelled; confirmado el 8 de noviembre
 *   de 2026: se avisa también cuando Nexus lo cancela solo);
 * - sus cupos se quedan como estaban, de historia: un grupo cancelado no cuenta en ningún lado (supuesto por confirmar).
 *
 * Deja la bitácora `cancelled` con las sesiones que perdió y, después del commit, avisa con GroupCancelled y, si tenía
 * horario, con GroupScheduleChanged. Devuelve el grupo ya cancelado y cuántas sesiones y minutos perdió.
 */
final class CancelGroup
{
    public function __construct(
        private readonly LoadEditability $editability,
        private readonly CancelAgreement $cancelAgreement,
    ) {}

    /**
     * @param  string  $key  la llave de los errores (`groups` cuando lo llama ArmParallelGroups)
     * @return array{group: Group, sessions: int, minutes: int}
     *
     * @throws ValidationException
     */
    public function handle(Group $group, User $actor, string $key = 'group'): array
    {
        return $this->editability->guard(fn (): array => DB::transaction(function () use ($group, $actor, $key): array {
            $this->editability->lockLoad($group);
            $group = Group::query()->lockForUpdate()->findOrFail($group->getKey());
            $this->editability->ensure($group, 'grupos', $key);

            if ($group->teacher_id !== null) {
                throw ValidationException::withMessages([$key => "El grupo {$group->code} tiene docente: quítalo antes de cancelarlo."]);
            }

            $veto = new GroupsCancelling([$group->getKey() => SessionRules::label($group)]);
            event($veto);

            if ($veto->reasonsFor($group->getKey()) !== []) {
                throw ValidationException::withMessages([$key => $veto->reasonsFor($group->getKey())]);
            }

            $sessions = GroupSession::query()->where('group_id', $group->getKey())->orderBy('day')->orderBy('starts_at')->lockForUpdate()->get();

            InterdepartmentalAgreement::query()->where('group_id', $group->getKey())->live()->orderBy('id')->get()
                ->each(fn (InterdepartmentalAgreement $agreement) => $this->cancelAgreement->handle($agreement, $actor));

            GroupSession::query()->whereKey($sessions->modelKeys())->delete();
            $old = $group->status;
            $group->update(['status' => GroupStatus::Cancelled]);

            activity()
                ->causedBy($actor)
                ->performedOn($group)
                ->event('cancelled')
                ->withChanges([
                    'old' => ['status' => $old->value, 'sessions' => $sessions->map(fn (GroupSession $session): array => SessionRules::snapshot($session))->all()],
                    'attributes' => ['status' => GroupStatus::Cancelled->value],
                ])
                ->log('Canceló el grupo '.SessionRules::label($group));

            GroupCancelled::dispatch($group);

            if ($sessions->isNotEmpty()) {
                GroupScheduleChanged::dispatch($group);
            }

            return ['group' => $group, 'sessions' => $sessions->count(), 'minutes' => (int) $sessions->sum('minutes')];
        }), $key);
    }
}
