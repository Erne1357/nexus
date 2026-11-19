<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Queries\ReanalysisCycleQuery;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reabre una carga cerrada, aprobada o publicada: vuelve a ajustes para corregirla (P3, confirmado el 10 de noviembre
 * de 2026). Una carga cerrada o aprobada que venía de un re-análisis (su último cierre salió de `reanalysis`) no vuelve a
 * ajustes sino a `reanalysis` (RA-08, confirmado el 17 de noviembre de 2026), y la versión publicada anterior sigue vigente.
 * El destino se decide con la carga bloqueada (la modalidad FOR SHARE y la carga FOR UPDATE, el mismo orden de
 * TransitionLoad) y no con el modelo que llegó: si la carga ya no está en el estado que quien llama veía, se rechaza en
 * lugar de elegir un destino con datos viejos. El motivo es obligatorio y queda como comentario de su historial. Es
 * también como Subdirección regresa con comentarios una carga que revisa para el visto bueno (tarea 4.2). De ajustes vuelve a asignación y se cierra otra
 * vez; volver a publicar crea otra versión del snapshot (tarea 4.3). Quién puede reabrirla lo decide
 * AcademicLoadPolicy::reopen antes de llamarla.
 */
final class ReopenLoad
{
    private const array REOPENABLE = [LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published];

    public function __construct(
        private readonly TransitionLoad $transition,
        private readonly ReanalysisCycleQuery $cycle,
    ) {}

    /**
     * @throws ValidationException si falta el motivo, la carga cambió de estado o el estado no permite reabrirla
     */
    public function handle(AcademicLoad $load, User $actor, ?string $reason): AcademicLoad
    {
        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Escribe el motivo para reabrir la carga.']);
        }

        return DB::transaction(function () use ($load, $actor, $reason): AcademicLoad {
            Modality::withTrashed()->whereKey($load->modality_id)->sharedLock()->firstOrFail();
            $locked = $load->newQuery()->lockForUpdate()->findOrFail($load->getKey());

            if ($locked->status !== $load->status) {
                throw ValidationException::withMessages([
                    'status' => "La carga cambió de estado (ahora está en {$locked->status->label()}): recárgala y vuelve a intentarlo.",
                ]);
            }

            return $this->transition->handle($load, $this->destination($locked), $actor, $reason, $this->reopenable(...));
        });
    }

    /**
     * A dónde vuelve: a re-análisis si la carga cerrada o aprobada viene de uno (el último cierre salió de `reanalysis`,
     * RA-08); a ajustes en cualquier otro caso. handle() lo decide con la carga bloqueada; las pantallas lo preguntan sin
     * bloquearla para decir el destino en el diálogo de reabrir (ensayo 8.11, F-03), con esta misma regla.
     */
    public function destination(AcademicLoad $load): LoadStatus
    {
        if (! in_array($load->status, [LoadStatus::Closed, LoadStatus::Approved], true)) {
            return LoadStatus::Adjusting;
        }

        return $this->cycle->closedFromReanalysis($load) ? LoadStatus::Reanalysis : LoadStatus::Adjusting;
    }

    /**
     * De asignación también se pasa a ajustes, pero eso lo hace una solicitud de cambio aprobada (StartAdjusting), no una
     * reapertura; y de publicada a re-análisis es StartReanalysis, no una devolución. Recibe la carga ya releída con bloqueo por TransitionLoad.
     *
     * @return list<string>
     */
    private function reopenable(AcademicLoad $load): array
    {
        return in_array($load->status, self::REOPENABLE, true)
            ? []
            : ["La carga está en {$load->status->label()}: solo se reabre una carga cerrada, aprobada o publicada."];
    }
}
