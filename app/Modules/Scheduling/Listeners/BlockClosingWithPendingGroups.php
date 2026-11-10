<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\Scheduling\Queries\ClosingBlockersQuery;

/**
 * Guarda de Scheduling para cerrar una carga (`staffing` → `closed`) y para publicarla (`closed` o `approved` →
 * `published`): HC-15, con la cuenta de ClosingBlockersQuery (confirmado el 10 de noviembre de 2026). Publicar vuelve a
 * revisar porque, después del cierre, Facilities todavía puede bloquear o desactivar un salón; entonces la publicación
 * se veta con su motivo y hay que reabrir. Veta con un motivo por cosa que falta (evento con veto, LoadTransitioning).
 */
final class BlockClosingWithPendingGroups
{
    public function __construct(private readonly ClosingBlockersQuery $blockers) {}

    public function handle(LoadTransitioning $event): void
    {
        $closing = $event->from === LoadStatus::Staffing && $event->to === LoadStatus::Closed;
        $publishing = in_array($event->from, [LoadStatus::Closed, LoadStatus::Approved], true) && $event->to === LoadStatus::Published;

        if (! $closing && ! $publishing) {
            return;
        }

        foreach ($this->blockers->of($event->load)->reasons() as $reason) {
            $event->block($reason);
        }
    }
}
