<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Actions\NotifyPendingReviews;
use App\Modules\Core\Events\TermOpened;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al abrir un periodo en un campus avisa a los jefes que tienen catálogos por validar (NotifyPendingReviews).
 * Va a la cola después del commit de OpenTerm, cuando ya existen las revisiones que creó
 * CreateReviewsOnTermOpened: si la apertura falla, nadie recibe el aviso, y avisar a todos los jefes no hace
 * esperar a quien abre. Volver a abrir el campus, o abrir el periodo en otro, vuelve a avisar a quien todavía
 * tenga pendientes (supuesto por confirmar).
 */
final class NotifyPendingReviewsOnTermOpened implements ShouldQueue
{
    /** Se encola al confirmarse la transacción de OpenTerm, no antes. */
    public bool $afterCommit = true;

    public function __construct(private readonly NotifyPendingReviews $notifyPendingReviews) {}

    public function handle(TermOpened $event): void
    {
        $this->notifyPendingReviews->handle($event->term, $event->campus);
    }
}
