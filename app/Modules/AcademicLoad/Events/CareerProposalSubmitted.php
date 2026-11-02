<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Models\CareerProposal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El coordinador envió (o reenvió) su propuesta (SubmitCareerProposal). Sale después del commit. Lleva los
 * departamentos cuya parte quedó pendiente, a los que se avisa (la notificación llega con la tarea 2.9); va
 * vacía si todas las partes ya estaban aceptadas y la propuesta quedó aceptada al reenviarla.
 */
final class CareerProposalSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $pendingDepartmentIds  ids de los departamentos con su parte pendiente, por clave
     * @param  bool  $resubmitted  si se reenvió después de que un departamento la regresó
     */
    public function __construct(
        public CareerProposal $proposal,
        public array $pendingDepartmentIds,
        public bool $resubmitted = false,
    ) {}
}
