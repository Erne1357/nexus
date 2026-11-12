<?php

namespace App\Modules\Scheduling\Actions\Agreements;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Scheduling\Notifications\LoanCancelled;

/**
 * Avisa que quien pidió un préstamo de salón lo canceló (reglas-de-negocio.md, "Acuerdos entre departamentos";
 * confirmado el 8 de noviembre de 2026): a los mismos que al pedirlo, los titulares de `academic_load.agreement.decide`
 * del departamento dueño del salón, en el campus (LoanCancelled). Solo se cancela un acuerdo vivo (CancelAgreement lo
 * exige), así que un rechazo no pasa por aquí: a quien pidió ya se le avisó. Un grupo compartido retirado no avisa (el
 * usuario solo lo decidió para préstamos). Da a cuántos usuarios avisó.
 */
final class NotifyLoanCancelled
{
    public function __construct(private readonly RecipientsQuery $recipients, private readonly NotifyHolders $notifyHolders) {}

    public function handle(InterdepartmentalAgreement $agreement): int
    {
        if ($agreement->kind !== AgreementKind::RoomLoan || $agreement->status !== AgreementStatus::Cancelled) {
            return 0;
        }

        $owners = $this->recipients->holders(AcademicLoadPermission::AgreementDecide, Target::of(modality: $agreement->modality_id, unit: $agreement->to_department_id));

        return $this->notifyHolders->handle($owners, new LoanCancelled($agreement))->count();
    }
}
