<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Escribe o quita la justificación de HC-15 de un grupo (P2, confirmado el 10 de noviembre de 2026): el grupo que se
 * queda sin docente o sin salón en alguna sesión presencial pasa el cierre con ella. Solo mientras la carga se edita
 * (LoadEditability) y solo para un grupo al que le falta algo de eso; un texto vacío la quita. Guarda quién y cuándo, y
 * deja bitácora en el grupo. Quién puede lo decide GroupPolicy::justify antes de llamarla.
 */
final class JustifyGroup
{
    public function __construct(private readonly LoadEditability $editability) {}

    /**
     * @throws ValidationException
     */
    public function handle(Group $group, ?string $justification, User $actor): Group
    {
        return $this->editability->guard(fn (): Group => $this->apply($group, $justification, $actor), 'justification');
    }

    /**
     * Lo mismo sin traducir el deadlock, para quien ya corre dentro de su propia transacción (JustifyGroups): ahí un
     * deadlock abortó la transacción de afuera y no es un rechazo del grupo, así que sale como error de base y quien
     * llama lo traduce por fuera. Solo las reglas salen como ValidationException.
     *
     * @throws ValidationException
     */
    public function apply(Group $group, ?string $justification, User $actor): Group
    {
        $justification = $justification === null || trim($justification) === '' ? null : trim($justification);

        return DB::transaction(function () use ($group, $justification, $actor): Group {
            $this->editability->ensure($group, 'justificaciones', 'justification');
            $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);

            if ($justification !== null && ! $this->lacksTeacherOrRoom($group)) {
                throw ValidationException::withMessages(['justification' => 'El grupo ya tiene docente y salón: no necesita justificación.']);
            }

            $old = $group->close_justification;

            if ($old === $justification) {
                return $group;
            }

            $group->update([
                'close_justification' => $justification,
                'close_justified_by' => $justification === null ? null : $actor->getKey(),
                'close_justified_at' => $justification === null ? null : now(),
            ]);

            activity()
                ->performedOn($group)
                ->causedBy($actor)
                ->event('justified')
                ->withChanges(['old' => ['close_justification' => $old], 'attributes' => ['close_justification' => $justification]])
                ->log($justification === null
                    ? "Quitó la justificación del grupo {$group->code}"
                    : "Justificó el grupo {$group->code}: {$justification}");

            return $group;
        });
    }

    private function lacksTeacherOrRoom(Group $group): bool
    {
        return $group->teacher_id === null || ($group->modality->profile->usesRooms && $group->sessions()
            ->where('is_active', true)
            ->whereNull('room_id')
            ->where('kind', '<>', SessionKind::Virtual->value)
            ->exists());
    }
}
