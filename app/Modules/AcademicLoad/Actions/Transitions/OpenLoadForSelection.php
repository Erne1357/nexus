<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SEL-11. Abre la carga a la asignación docente para la selección por turnos: de Borrador, Propuesta o Ajustes pasa a
 * Asignación sin exigir la propuesta aprobada (HC-14) ni el veto de choques de Scheduling, porque la selección ocurre
 * antes de que el departamento cierre su oferta. Deja la fila de `load_transitions` (HC-18) y la bitácora como
 * TransitionLoad y avisa con LoadTransitioned. Si la carga ya está en Asignación no hace nada; de Cerrada en adelante
 * la rechaza. Quién publica la selección lo decide SelectionPolicy.
 */
final class OpenLoadForSelection
{
    /**
     * @throws ValidationException si la carga ya no puede abrirse a la selección
     */
    public function handle(AcademicLoad $load, User $actor, string $reason = 'Orden de selección'): AcademicLoad
    {
        return DB::transaction(function () use ($load, $actor, $reason): AcademicLoad {
            $load->setRawAttributes($load->newQuery()->lockForUpdate()->findOrFail($load->getKey())->getAttributes(), sync: true);
            $from = $load->status;

            if ($from === LoadStatus::Staffing) {
                return $load;
            }

            if (! $from->opensForSelection()) {
                throw ValidationException::withMessages([
                    'status' => "La carga {$from->predicate()}: no puede abrirse a la selección de docentes.",
                ]);
            }

            $load->update(['status' => LoadStatus::Staffing]);

            $load->transitions()->create([
                'from_status' => $from,
                'to_status' => LoadStatus::Staffing,
                'user_id' => $actor->getKey(),
                'comment' => $reason,
            ]);

            $load->loadMissing(['department', 'modality']);

            activity()
                ->performedOn($load)
                ->causedBy($actor)
                ->event('transitioned')
                ->withChanges(['old' => ['status' => $from->value], 'attributes' => ['status' => LoadStatus::Staffing->value]])
                ->withProperties(['comment' => $reason])
                ->log("Pasó la carga de {$load->department->code} en {$load->modality->name} de {$from->label()} a ".LoadStatus::Staffing->label());

            LoadTransitioned::dispatch($load, $from, LoadStatus::Staffing, $reason);

            return $load;
        });
    }
}
