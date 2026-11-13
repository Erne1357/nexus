<?php

namespace App\Modules\AcademicLoad\Notifications\Concerns;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\Core\Notifications\NotificationMessage;

/**
 * La propuesta de la que habla un aviso guardado: la de su periodo, su modalidad y su carrera (M6; MS14, supuesto por
 * confirmar). Los avisos de antes de la propuesta por modalidad, con solo el campus, pasaron a llevar la modalidad al
 * contraer (2026_11_12_211101). Null si falta algo o la propuesta ya no existe.
 */
trait LocatesProposal
{
    protected static function proposalOf(NotificationMessage $message, ?Career $career): ?CareerProposal
    {
        if ($career === null || $message->termId === null || $message->modalityId === null) {
            return null;
        }

        return CareerProposal::query()
            ->where('term_id', $message->termId)
            ->where('modality_id', $message->modalityId)
            ->where('career_id', $career->getKey())
            ->first();
    }
}
