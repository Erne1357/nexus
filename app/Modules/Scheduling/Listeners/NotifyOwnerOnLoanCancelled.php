<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\AcademicLoad\Events\AgreementCancelled;
use App\Modules\Scheduling\Actions\Agreements\NotifyLoanCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al cancelarse un acuerdo, avisa en cola al dueño del salón si era un préstamo (NotifyLoanCancelled), uno por acuerdo
 * cancelado: a mano (CancelRoomLoan) o solo (SyncSessionLoans). No avisa si se canceló para pedir en ese momento otro
 * del mismo grupo, salón y día (`replaced`): el aviso de pedir el nuevo basta. El evento sale después del commit.
 */
final class NotifyOwnerOnLoanCancelled implements ShouldQueue
{
    public function __construct(private readonly NotifyLoanCancelled $notify) {}

    public function handle(AgreementCancelled $event): void
    {
        if ($event->replaced) {
            return;
        }

        $this->notify->handle($event->agreement);
    }
}
