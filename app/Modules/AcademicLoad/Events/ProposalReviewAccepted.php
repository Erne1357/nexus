<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Models\ProposalReview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un departamento aceptó su parte de una propuesta de carrera (AcceptProposalReview). Sale después del commit:
 * una aceptación que se revierte no avisa. Dice si con ella la propuesta quedó aceptada completa.
 */
final class ProposalReviewAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProposalReview $review,
        public bool $proposalAccepted,
    ) {}
}
