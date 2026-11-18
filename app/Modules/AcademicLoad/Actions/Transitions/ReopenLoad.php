<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Reabre una carga cerrada, aprobada o publicada: vuelve a ajustes para corregirla (P3, confirmado el 10 de noviembre
 * de 2026). Una carga cerrada o aprobada que venía de un re-análisis (su último cierre salió de `reanalysis`) no vuelve a
 * ajustes sino a `reanalysis` (RA-08, confirmado el 18 de noviembre de 2026), y la versión publicada anterior sigue vigente. El motivo es obligatorio y queda como comentario de su historial. Es también como Subdirección regresa con
 * comentarios una carga que revisa para el visto bueno (tarea 4.2). De ajustes vuelve a asignación y se cierra otra
 * vez; volver a publicar crea otra versión del snapshot (tarea 4.3). Quién puede reabrirla lo decide
 * AcademicLoadPolicy::reopen antes de llamarla.
 */
final class ReopenLoad
{
    private const array REOPENABLE = [LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published];

    public function __construct(private readonly TransitionLoad $transition) {}

    /**
     * @throws ValidationException si falta el motivo o el estado de la carga no permite reabrirla
     */
    public function handle(AcademicLoad $load, User $actor, ?string $reason): AcademicLoad
    {
        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Escribe el motivo para reabrir la carga.']);
        }

        return $this->transition->handle($load, $this->target($load), $actor, $reason, $this->reopenable(...));
    }

    /**
     * A dónde vuelve: a re-análisis si la carga cerrada o aprobada viene de uno (el último cierre salió de `reanalysis`,
     * RA-08); a ajustes en cualquier otro caso.
     */
    private function target(AcademicLoad $load): LoadStatus
    {
        if (! in_array($load->status, [LoadStatus::Closed, LoadStatus::Approved], true)) {
            return LoadStatus::Adjusting;
        }

        $closedFrom = $load->transitions()->where('to_status', LoadStatus::Closed)->orderByDesc('created_at')->orderByDesc('id')->value('from_status');

        return $closedFrom === LoadStatus::Reanalysis ? LoadStatus::Reanalysis : LoadStatus::Adjusting;
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
