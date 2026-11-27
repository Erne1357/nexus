<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\AcademicLoad\Events\AgreementAccepted;
use App\Modules\AcademicLoad\Events\AgreementRejected;
use App\Modules\Scheduling\Actions\Agreements\NotifyAgreementAnswered;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al aceptarse o rechazarse un acuerdo, avisa en cola al departamento que lo pidió (NotifyAgreementAnswered). El evento
 * sale después del commit.
 */
final class NotifyRequesterOnAgreementDecided implements ShouldQueue
{
    public function __construct(private readonly NotifyAgreementAnswered $notify) {}

    public function handle(AgreementAccepted|AgreementRejected $event): void
    {
        $this->notify->handle($event->agreement);
    }
}
