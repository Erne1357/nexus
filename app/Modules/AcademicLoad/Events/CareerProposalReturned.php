<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\CareerProposal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un departamento regresó su parte y la propuesta volvió al coordinador (ReturnProposalReview). Sale después del
 * commit. Lleva el departamento que la regresó y su comentario; la notificación al coordinador llega con la tarea
 * 2.9.
 */
final class CareerProposalReturned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CareerProposal $proposal,
        public Department $department,
        public string $comment,
    ) {}
}
