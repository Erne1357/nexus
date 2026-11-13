<?php

namespace App\Modules\Scheduling\Actions\Agreements;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Scheduling\Notifications\AgreementAnswered;
use App\Modules\Scheduling\Queries\AgreementPlaceQuery;

/**
 * Avisa que se aceptó o se rechazó un acuerdo (reglas-de-negocio.md, "Acuerdos entre departamentos"; supuesto por
 * confirmar): a los titulares de `academic_load.agreement.request` del departamento que lo pidió, en la modalidad y el
 * campus del lugar (AgreementPlaceQuery: el del salón en un préstamo, el del grupo en un grupo compartido), y un rechazo
 * con su motivo (AgreementAnswered). Da a cuántos usuarios avisó.
 */
final class NotifyAgreementAnswered
{
    public function __construct(
        private readonly RecipientsQuery $recipients,
        private readonly NotifyHolders $notifyHolders,
        private readonly AgreementPlaceQuery $place,
    ) {}

    public function handle(InterdepartmentalAgreement $agreement): int
    {
        $requesters = $this->recipients->holders(AcademicLoadPermission::AgreementRequest, Target::of(modality: $agreement->modality_id, campus: $this->place->campusIdOf($agreement), unit: $agreement->from_department_id));

        return $this->notifyHolders->handle($requesters, new AgreementAnswered($agreement))->count();
    }
}
