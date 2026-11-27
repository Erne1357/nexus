<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Models\CareerProposal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Nexus puso al día `$count` filas sin tocar de una propuesta con la proyección recalculada (SyncProposalSuggestions).
 * Sale después del commit y solo si alguna fila cambió, por si la pantalla quiere refrescarse.
 */
final class ProposalSuggestionsSynced implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public CareerProposal $proposal, public int $count) {}
}
