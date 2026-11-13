<?php

namespace App\Modules\AcademicLoad\Notifications\Concerns;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Notifications\NotificationMessage;

/**
 * La propuesta de la que habla un aviso guardado: la de su periodo, su modalidad y su carrera (M6). Los avisos de antes
 * de la propuesta por modalidad guardaban el campus y no la modalidad; de esos, la modalidad es la del campus por el
 * puente `campuses.modality_id` (MS14, supuesto por confirmar). Null si falta algo o la propuesta ya no existe.
 */
trait LocatesProposal
{
    protected static function proposalOf(NotificationMessage $message, ?Career $career): ?CareerProposal
    {
        $modalityId = $message->modalityId
            ?? ($message->campusId === null ? null : Campus::withTrashed()->whereKey($message->campusId)->value('modality_id'));

        if ($career === null || $message->termId === null || $modalityId === null) {
            return null;
        }

        return CareerProposal::query()
            ->where('term_id', $message->termId)
            ->where('modality_id', $modalityId)
            ->where('career_id', $career->getKey())
            ->first();
    }
}
