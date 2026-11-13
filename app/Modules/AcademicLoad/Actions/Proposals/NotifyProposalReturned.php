<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Notifications\ProposalReturned;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Queries\RecipientsQuery;

/**
 * Avisa que un departamento regresó su parte de una propuesta (tareas 2.8 y 2.9): a todos los coordinadores vigentes de
 * la carrera en la modalidad, de cualquier campus y también a los que no tienen uno, con el comentario (ProposalReturned;
 * M6, MS18, supuesto por confirmar). Son los que pueden enviar la propuesta: ese permiso no mira el campus. Da a cuántos
 * usuarios avisó.
 */
final class NotifyProposalReturned
{
    public function __construct(private readonly RecipientsQuery $recipients, private readonly NotifyHolders $notifyHolders) {}

    public function handle(CareerProposal $proposal, Department $department, string $comment): int
    {
        $proposal->loadMissing(['career' => fn ($query) => $query->withTrashed(), 'modality' => fn ($query) => $query->withTrashed()]);
        $coordinators = $this->recipients->holders(AcademicLoadPermission::ProposalSubmit, Target::of(modality: $proposal->modality_id, career: $proposal->career_id));

        return $this->notifyHolders->handle($coordinators, new ProposalReturned($proposal, $department, $comment))->count();
    }
}
