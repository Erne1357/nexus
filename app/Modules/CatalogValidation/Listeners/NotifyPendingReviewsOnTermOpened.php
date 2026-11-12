<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Actions\NotifyPendingReviews;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Campus;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al abrir un periodo en una modalidad (M4) avisa a los jefes que tienen catálogos por validar (NotifyPendingReviews).
 * Va a la cola después del commit de OpenTerm, cuando ya existen las revisiones que creó
 * CreateReviewsOnTermOpened: si la apertura falla, nadie recibe el aviso, y avisar a todos los jefes no hace
 * esperar a quien abre. Volver a abrir la modalidad, o abrir el periodo en otra, vuelve a avisar a quien todavía
 * tenga pendientes (supuesto por confirmar).
 *
 * Puente mientras el aviso sea por campus: avisa una vez, con el primer campus activo (por clave) de la modalidad
 * (`campuses.modality_id`), porque el jefe de departamento es de todo el Tec y las revisiones no llevan campus; una
 * modalidad sin campus activos no avisa.
 */
final class NotifyPendingReviewsOnTermOpened implements ShouldQueue
{
    /** Se encola al confirmarse la transacción de OpenTerm, no antes. */
    public bool $afterCommit = true;

    public function __construct(private readonly NotifyPendingReviews $notifyPendingReviews) {}

    public function handle(TermOpened $event): void
    {
        $campus = Campus::query()->where('modality_id', $event->modality->getKey())->where('is_active', true)->orderBy('code')->first();

        if ($campus !== null) {
            $this->notifyPendingReviews->handle($event->term, $campus);
        }
    }
}
