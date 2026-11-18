<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Queries\ReanalysisAvailabilityQuery;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Inicia el re-análisis de una carga publicada: `published` → `reanalysis` (tarea 8.1; RA-01, confirmado el 18 de noviembre
 * de 2026). Lo inicia el jefe del departamento (AcademicLoadPolicy::startReanalysis, antes de llamarla) y solo está
 * habilitado cuando llegaron las calificaciones del semestre actual y la proyección ya las refleja (RA-02): la guarda es
 * ReanalysisAvailabilityQuery, que se evalúa con la carga ya bloqueada. No exige estar en la ventana del calendario
 * (el calendario solo informa; confirmado el 18 de noviembre de 2026).
 *
 * No toca la foto publicada: la última versión sigue siendo la pública mientras la carga está en re-análisis. En
 * re-análisis la carga se edita como en ajustes (LoadEditability); se cierra con CloseLoad (mismas guardas de HC-15), pasa
 * por el visto bueno si la modalidad lo exige (RA-03) y se publica como la versión n + 1 con PublishLoad. La transición
 * `reanalysis` → `closed` queda en `load_transitions` con `from_status = reanalysis`, que es lo que distingue este ciclo
 * del cierre normal (ReopenLoad lo usa para devolver a re-análisis, RA-08; la versión nueva, la 8.4).
 */
final class StartReanalysis
{
    public function __construct(
        private readonly TransitionLoad $transition,
        private readonly ReanalysisAvailabilityQuery $availability,
    ) {}

    /**
     * @throws ValidationException si la carga no está publicada, el re-análisis no está habilitado o un módulo lo veta
     */
    public function handle(AcademicLoad $load, User $actor, ?string $comment = null): AcademicLoad
    {
        return $this->transition->handle($load, LoadStatus::Reanalysis, $actor, $comment, $this->availability->reasons(...));
    }
}
