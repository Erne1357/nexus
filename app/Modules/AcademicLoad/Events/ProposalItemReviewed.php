<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Models\ProposalItem;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El coordinador marcó revisada una fila de su propuesta en «Revisar proyección» (MarkProposalItemReviewed, tarea 5.6).
 * Sale después del commit y solo si la fila no estaba revisada.
 */
final class ProposalItemReviewed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public ProposalItem $item) {}
}
