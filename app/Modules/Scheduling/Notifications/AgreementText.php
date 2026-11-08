<?php

namespace App\Modules\Scheduling\Notifications;

use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Scheduling\Validation\Minutes;

/**
 * El renglón que resume un acuerdo en un aviso: «LC-201 · Lun 9:00–11:00» o «5 lugares de ISC».
 */
final class AgreementText
{
    public static function summary(InterdepartmentalAgreement $agreement): string
    {
        $agreement->loadMissing(['room' => fn ($room) => $room->withTrashed(), 'career' => fn ($career) => $career->withTrashed()]);

        if ($agreement->kind === AgreementKind::RoomLoan) {
            $day = Minutes::dayLabel((int) $agreement->day, short: true);

            return "{$agreement->room?->code} · {$day} ".Minutes::labelOf($agreement->starts_at).'–'.Minutes::labelOf($agreement->ends_at);
        }

        return $agreement->max_seats.($agreement->max_seats === 1 ? ' lugar' : ' lugares')." de {$agreement->career?->code}";
    }
}
