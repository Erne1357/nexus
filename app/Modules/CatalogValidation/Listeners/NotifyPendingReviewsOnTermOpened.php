<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Actions\NotifyPendingReviews;
use App\Modules\Core\Events\TermOpened;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Al abrir un periodo en un campus avisa a los jefes que tienen catálogos por validar (NotifyPendingReviews).
 * Corre después del commit de OpenTerm, cuando ya existen las revisiones que creó CreateReviewsOnTermOpened:
 * si la apertura falla, nadie recibe el aviso. Volver a abrir el campus vuelve a avisar a quien todavía tenga
 * pendientes (supuesto por confirmar).
 */
final class NotifyPendingReviewsOnTermOpened implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly NotifyPendingReviews $notifyPendingReviews) {}

    public function handle(TermOpened $event): void
    {
        $this->notifyPendingReviews->handle($event->term, $event->campus);
    }
}
