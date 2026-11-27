<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Da el visto bueno de Subdirección a una carga cerrada: `closed` → `approved` (tarea 4.2). Solo existe en un campus
 * con `requires_subdireccion_approval`: el diagrama de LoadStatus::transitions() ya lo decide y, sin ese parámetro,
 * TransitionLoad dice que la carga no puede pasar a Aprobada. En la misma transacción guarda quién y cuándo la aprobó
 * (`approved_by`, `approved_at`: la última aprobación; el historial completo está en `load_transitions`). El comentario
 * es opcional. «Regresar con comentarios» no es otra Action: es ReopenLoad desde cerrada. Quién puede aprobar lo
 * decide AcademicLoadPolicy::approve antes de llamarla.
 */
final class ApproveLoad
{
    public function __construct(private readonly TransitionLoad $transition) {}

    /**
     * @throws ValidationException
     */
    public function handle(AcademicLoad $load, User $actor, ?string $comment = null): AcademicLoad
    {
        return DB::transaction(function () use ($load, $actor, $comment): AcademicLoad {
            $load = $this->transition->handle($load, LoadStatus::Approved, $actor, $comment, fn (): array => []);
            $load->update(['approved_by' => $actor->getKey(), 'approved_at' => now()]);

            return $load;
        });
    }
}
