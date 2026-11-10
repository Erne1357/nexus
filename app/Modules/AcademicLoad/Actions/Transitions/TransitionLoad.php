<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El único lugar que cambia `academic_loads.status` (ver "Transiciones de la carga" en
 * docs/arquitectura/arquitectura.md). Cada transición (ProposeLoad y las que llegan en las fases 3 y 4) la
 * llama con su estado destino y su guarda.
 *
 * En una transacción relee la carga con bloqueo (el estado que vale es el de la base, no el del modelo que
 * llegó), exige que el diagrama permita pasar de ahí al destino (LoadStatus::canTransitionTo, según el perfil del campus) y junta los
 * motivos de bloqueo: los de la guarda que da quien llama (reglas de los módulos de la izquierda) y los del
 * veto de LoadTransitioning (módulos de la derecha). Con cualquier motivo, una ValidationException sobre
 * `status` con todos y no se escribe nada. Si no, cambia el estado, agrega la fila de `load_transitions`
 * (HC-18), deja bitácora de la carga y, después del commit, avisa con LoadTransitioned. Quien transita se
 * recibe como parámetro (no sale de la sesión) para que también pueda llamarla un job o un comando. Quién puede
 * transitar lo decide AcademicLoadPolicy antes de llamarla.
 */
final class TransitionLoad
{
    /**
     * @param  Closure(AcademicLoad): list<string>  $guard  motivos que bloquean la transición; vacío si puede seguir
     *
     * @throws ValidationException si el diagrama no la permite o hay motivos de bloqueo
     */
    public function handle(AcademicLoad $load, LoadStatus $to, User $actor, ?string $comment, Closure $guard): AcademicLoad
    {
        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);

        return DB::transaction(function () use ($load, $to, $comment, $guard, $actor): AcademicLoad {
            $load->setRawAttributes($load->newQuery()->lockForUpdate()->findOrFail($load->getKey())->getAttributes(), sync: true);
            $from = $load->status;

            if (! $from->canTransitionTo($to, $load->campus->profile)) {
                throw ValidationException::withMessages([
                    'status' => "La carga está en {$from->label()}: no puede pasar a {$to->label()}.",
                ]);
            }

            $event = new LoadTransitioning($load, $from, $to);
            event($event);

            $reasons = [...$guard($load), ...$event->reasons()];

            if ($reasons !== []) {
                throw ValidationException::withMessages(['status' => $reasons]);
            }

            $load->update(['status' => $to]);

            $load->transitions()->create([
                'from_status' => $from,
                'to_status' => $to,
                'user_id' => $actor->getKey(),
                'comment' => $comment,
            ]);

            $load->loadMissing(['department', 'campus']);

            activity()
                ->performedOn($load)
                ->causedBy($actor)
                ->event('transitioned')
                ->withChanges(['old' => ['status' => $from->value], 'attributes' => ['status' => $to->value]])
                ->withProperties(['comment' => $comment])
                ->log("Pasó la carga de {$load->department->code} en {$load->campus->code} de {$from->label()} a {$to->label()}");

            LoadTransitioned::dispatch($load, $from, $to, $comment);

            return $load;
        });
    }
}
