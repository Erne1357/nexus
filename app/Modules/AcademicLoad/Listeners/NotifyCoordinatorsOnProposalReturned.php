<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Actions\Proposals\NotifyProposalReturned;
use App\Modules\AcademicLoad\Events\CareerProposalReturned;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al regresar un departamento su parte de una propuesta, avisa en cola al coordinador (NotifyProposalReturned).
 * El evento sale después del commit.
 */
final class NotifyCoordinatorsOnProposalReturned implements ShouldQueue
{
    public function __construct(private readonly NotifyProposalReturned $notifyProposalReturned) {}

    public function handle(CareerProposalReturned $event): void
    {
        $this->notifyProposalReturned->handle($event->proposal, $event->department, $event->comment);
    }
}
