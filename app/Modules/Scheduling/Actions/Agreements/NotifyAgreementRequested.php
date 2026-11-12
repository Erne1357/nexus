<?php

namespace App\Modules\Scheduling\Actions\Agreements;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Scheduling\Notifications\AgreementReceived;

/**
 * Avisa que se pidió un acuerdo (reglas-de-negocio.md, "Acuerdos entre departamentos"; supuesto por confirmar): a los
 * titulares de `academic_load.agreement.decide` del departamento al que va dirigido, en el campus (AgreementReceived).
 * Da a cuántos usuarios avisó.
 */
final class NotifyAgreementRequested
{
    public function __construct(private readonly RecipientsQuery $recipients, private readonly NotifyHolders $notifyHolders) {}

    public function handle(InterdepartmentalAgreement $agreement): int
    {
        $deciders = $this->recipients->holders(AcademicLoadPermission::AgreementDecide, Target::of(modality: $agreement->modality_id, unit: $agreement->to_department_id));

        return $this->notifyHolders->handle($deciders, new AgreementReceived($agreement))->count();
    }
}
