<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Models\CareerProposal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se armó la propuesta de una carrera en un campus y un periodo, con sus filas (StartCareerProposal). Sale
 * después del commit: una propuesta que se revierte no avisa. Solo la primera vez; volver a abrirla no avisa.
 */
final class CareerProposalStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public CareerProposal $proposal) {}
}
