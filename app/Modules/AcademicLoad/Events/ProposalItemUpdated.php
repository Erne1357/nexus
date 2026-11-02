<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Models\ProposalItem;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El coordinador corrigió una fila de su propuesta (UpdateProposalItem). Sale después del commit y solo si
 * algo cambió.
 */
final class ProposalItemUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changed  columnas que cambiaron (`expected_students`, `requested_groups`, `notes`)
     */
    public function __construct(
        public ProposalItem $item,
        public array $changed,
    ) {}
}
