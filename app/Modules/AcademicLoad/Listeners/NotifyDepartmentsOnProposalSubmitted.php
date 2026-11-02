<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Actions\Proposals\NotifyProposalSubmitted;
use App\Modules\AcademicLoad\Events\CareerProposalSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al enviar o reenviar una propuesta, avisa en cola a los jefes de los departamentos con su parte pendiente
 * (NotifyProposalSubmitted). El evento sale después del commit del envío.
 */
final class NotifyDepartmentsOnProposalSubmitted implements ShouldQueue
{
    public function __construct(private readonly NotifyProposalSubmitted $notifyProposalSubmitted) {}

    public function handle(CareerProposalSubmitted $event): void
    {
        $this->notifyProposalSubmitted->handle($event->proposal, $event->pendingDepartmentIds, $event->resubmitted);
    }
}
