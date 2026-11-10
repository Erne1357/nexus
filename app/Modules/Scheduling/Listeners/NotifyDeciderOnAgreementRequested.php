<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\AcademicLoad\Events\AgreementRequested;
use App\Modules\Scheduling\Actions\Agreements\NotifyAgreementRequested;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al pedirse un acuerdo, avisa en cola al jefe del departamento que lo decide (NotifyAgreementRequested). El evento sale
 * después del commit.
 */
final class NotifyDeciderOnAgreementRequested implements ShouldQueue
{
    public function __construct(private readonly NotifyAgreementRequested $notify) {}

    public function handle(AgreementRequested $event): void
    {
        $this->notify->handle($event->agreement);
    }
}
