<?php

namespace App\Modules\Scheduling\Notifications;

use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Scheduling\Validation\Minutes;

/**
 * El renglón que resume un acuerdo en un aviso («LC-201 · Lun 9:00–11:00» o «5 lugares de ISC») y los parámetros con
 * los que el aviso abre Acuerdos.
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

    /**
     * Con qué abre el aviso la pantalla Acuerdos: el acuerdo y, si es un préstamo de salón, su campus como filtro de la
     * pantalla (`?campus=`, MS14); la modalidad la pone OpenNotification desde `modalityId`. El campus va como parámetro
     * de la pantalla y no como `campusId` del aviso, que es alcance: quien lo recibe decide en toda su modalidad (M12) y
     * lo abre aunque su nombramiento sea de otro campus.
     *
     * @return array{acuerdo: string, campus?: string}
     */
    public static function parameters(InterdepartmentalAgreement $agreement): array
    {
        if ($agreement->kind !== AgreementKind::RoomLoan) {
            return ['acuerdo' => $agreement->getKey()];
        }

        $agreement->loadMissing(['campus' => fn ($campus) => $campus->withTrashed()]);

        return ['acuerdo' => $agreement->getKey(), ...($agreement->campus === null ? [] : ['campus' => $agreement->campus->code])];
    }
}
