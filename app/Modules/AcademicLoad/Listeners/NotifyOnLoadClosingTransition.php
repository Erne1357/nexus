<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Actions\Transitions\NotifyLoadClosing;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al cambiar de estado una carga, avisa en cola lo que toque del cierre (NotifyLoadClosing). El evento sale después del
 * commit.
 */
final class NotifyOnLoadClosingTransition implements ShouldQueue
{
    public function __construct(private readonly NotifyLoadClosing $notifyLoadClosing) {}

    public function handle(LoadTransitioned $event): void
    {
        $this->notifyLoadClosing->handle($event->load, $event->from, $event->to, $event->comment);
    }
}
