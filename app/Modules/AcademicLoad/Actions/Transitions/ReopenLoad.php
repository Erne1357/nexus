<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Reabre una carga cerrada, aprobada o publicada: vuelve a ajustes para corregirla (P3, confirmado el 10 de noviembre
 * de 2026). El motivo es obligatorio y queda como comentario de su historial. Es también como Subdirección regresa con
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

        return $this->transition->handle($load, LoadStatus::Adjusting, $actor, $reason, $this->reopenable(...));
    }

    /**
     * De asignación también se pasa a ajustes, pero eso lo hace una solicitud de cambio aprobada (StartAdjusting), no una
     * reapertura. Recibe la carga ya releída con bloqueo por TransitionLoad.
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
