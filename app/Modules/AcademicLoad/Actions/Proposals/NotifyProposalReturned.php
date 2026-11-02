<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Notifications\ProposalReturned;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Queries\RecipientsQuery;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa que un departamento regresó su parte de una propuesta (tareas 2.8 y 2.9): al coordinador de la carrera
 * en el campus, con el comentario (ProposalReturned). Da a cuántos usuarios avisó.
 */
final class NotifyProposalReturned
{
    public function __construct(private readonly RecipientsQuery $recipients) {}

    public function handle(CareerProposal $proposal, Department $department, string $comment): int
    {
        $coordinators = $this->recipients->for(Role::Coordinador, $proposal->campus_id, career: $proposal->career_id);

        Notification::send($coordinators, new ProposalReturned($proposal, $department, $comment));

        return $coordinators->count();
    }
}
