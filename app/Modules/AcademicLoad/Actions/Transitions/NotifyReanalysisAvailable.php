<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Notifications\ReanalysisAvailable;
use App\Modules\AcademicLoad\Queries\ReanalysisAvailabilityQuery;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use Illuminate\Support\Facades\DB;

/**
 * Avisa por la campana a los jefes de los departamentos cuya carga publicada del periodo y la modalidad ya puede
 * re-analizarse (RA-01 y RA-02, tarea 8.1): los titulares de `academic_load.load.start_reanalysis` en la modalidad y el
 * departamento de cada carga con ReanalysisAvailabilityQuery verdadera (el admin no recibe, no es titular). Solo avisa;
 * lo que habilita el botón es la consulta, no este aviso. A quien ya recibió el aviso de esa carga después del último
 * kárdex aplicado no se lo repite: la proyección se recalcula por campus y cada recálculo despierta a este Action. Da a
 * cuántas personas avisó.
 */
final class NotifyReanalysisAvailable
{
    public function __construct(
        private readonly ReanalysisAvailabilityQuery $availability,
        private readonly RecipientsQuery $recipients,
        private readonly NotifyHolders $notifyHolders,
    ) {}

    public function handle(string $termId, string $modalityId): int
    {
        $notified = 0;

        foreach ($this->availability->availableLoads($termId, $modalityId) as $load) {
            $since = $this->availability->lastKardexBatchAfter($load->published_at);
            $holders = $this->recipients->holders(AcademicLoadPermission::LoadStartReanalysis, Target::of(modality: $load->modality_id, unit: $load->department_id));
            $pending = array_values(array_filter($holders, fn (array $holder): bool => ! $this->alreadyNotified($holder['user'], $load, $since)));

            $notified += $this->notifyHolders->handle($pending, new ReanalysisAvailable($load, $load->department, "Calificaciones del semestre anterior importadas; la carga está en la versión {$load->current_version}."))->count();
        }

        return $notified;
    }

    private function alreadyNotified(User $user, AcademicLoad $load, mixed $since): bool
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->getKey())
            ->where('type', ReanalysisAvailable::class)
            ->whereRaw("data::jsonb -> 'parameters' ->> 'departamento' = ?", [$load->department_id])
            ->whereRaw("data::jsonb ->> 'modality_id' = ?", [$load->modality_id])
            ->whereRaw("data::jsonb ->> 'term_id' = ?", [$load->term_id])
            ->where('created_at', '>=', $since)
            ->exists();
    }
}
