<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\AcademicLoad\Events\AgreementCancelled;
use App\Modules\Scheduling\Actions\Agreements\NotifyLoanCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al cancelarse un acuerdo, avisa en cola al dueño del salón si era un préstamo (NotifyLoanCancelled), uno por acuerdo
 * cancelado: a mano (CancelRoomLoan) o solo (SyncSessionLoans). El evento sale después del commit.
 */
final class NotifyOwnerOnLoanCancelled implements ShouldQueue
{
    public function __construct(private readonly NotifyLoanCancelled $notify) {}

    public function handle(AgreementCancelled $event): void
    {
        $this->notify->handle($event->agreement);
    }
}
