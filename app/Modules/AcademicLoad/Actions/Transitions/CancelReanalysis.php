<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Queries\ReanalysisChangesQuery;
use App\Modules\Core\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Cancela el re-análisis de una carga mientras no haya cambiado nada: `reanalysis` → `published` sin versión nueva (tarea 8.1,
 * seguimiento #600; RA-10, confirmado el 18 de noviembre de 2026). Lo cancela quien lo inicia (la Action autoriza
 * `cancelReanalysis` de AcademicLoadPolicy, el mismo permiso y alcance que StartReanalysis: el jefe del departamento y el admin).
 *
 * La guarda se evalúa con la carga ya bloqueada (TransitionLoad: la modalidad FOR SHARE y la carga FOR UPDATE, el estado que
 * vale es el de la base): la carga tiene que seguir en `reanalysis` y los grupos de ahora, comparados con la última versión
 * publicada con SnapshotDiff (ReanalysisChangesQuery), no deben traer diferencias. Si el jefe ya cambió algo, se rechaza
 * con cuántos grupos cambiaron: tiene que cerrarla y republicar la versión n + 1.
 *
 * No toca la foto ni la carga publicada: no crea versión, ni cambia `current_version`, `published_by` ni `published_at`, ni
 * sincroniza el historial docente (nada cambió). Deja la fila de `load_transitions` con su motivo, la bitácora y LoadTransitioned;
 * los listeners de la publicación no avisan ni sincronizan cuando salen de `reanalysis`.
 */
final class CancelReanalysis
{
    public const string COMMENT = 'Re-análisis cancelado sin cambios';

    public function __construct(
        private readonly TransitionLoad $transition,
        private readonly ReanalysisChangesQuery $changes,
    ) {}

    /**
     * @throws AuthorizationException si quien actúa no puede cancelar el re-análisis de la carga
     * @throws ValidationException si la carga no está en re-análisis, ya tiene cambios o un módulo lo veta
     */
    public function handle(AcademicLoad $load, User $actor): AcademicLoad
    {
        Gate::forUser($actor)->authorize('cancelReanalysis', $load);

        return $this->transition->handle($load, LoadStatus::Published, $actor, self::COMMENT, $this->cancellable(...));
    }

    /**
     * @return list<string>
     */
    private function cancellable(AcademicLoad $load): array
    {
        if ($load->status !== LoadStatus::Reanalysis) {
            return ["La carga está en {$load->status->label()}: solo se cancela un re-análisis en curso."];
        }

        $changed = $this->changes->changedGroups($load);

        return $changed === 0
            ? []
            : ["Ya cambiaste {$changed} ".($changed === 1 ? 'grupo' : 'grupos')." desde la versión {$load->current_version}: ciérralo y publica la versión ".($load->current_version + 1).'.'];
    }
}
