<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Actions\NotifyFlagResolved;
use App\Modules\CatalogValidation\Events\CatalogReviewFlagResolved;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al resolverse una observación (ResolveFlag), avisa en cola al jefe que la puso (NotifyFlagResolved, CV10). El evento
 * sale después del commit.
 */
final class NotifyFlaggerOnFlagResolved implements ShouldQueue
{
    public function __construct(private readonly NotifyFlagResolved $notify) {}

    public function handle(CatalogReviewFlagResolved $event): void
    {
        $this->notify->handle($event->flag);
    }
}
