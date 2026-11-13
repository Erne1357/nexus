<?php

namespace App\Modules\AcademicLoad\Policies;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién arma, ve, corrige y envía la propuesta de una carrera, según docs/dominio/reglas-de-negocio.md,
 * "Aprobaciones y permisos" (filas "Ver la propuesta de carrera" y "Armar, corregir y enviar la propuesta de
 * carrera"):
 *
 * - Arma (StartCareerProposal) y envía (SubmitCareerProposal) cualquier coordinador de la carrera en esa modalidad
 *   (M6); corrige (UpdateProposalItem) una fila, que es de un campus, y un coordinador con campus solo cambia las de
 *   su campus (M7, AZ-S17, MS6).
 * - La ve también la subdirección, en cualquier campus y solo lectura (supuesto por confirmar).
 *
 * El admin pasa por la concesión implícita del registro. Los jefes deciden la parte de su departamento desde su bandeja, con
 * ProposalReviewPolicy.
 */
final class CareerProposalPolicy
{
    use AuthorizesActor;

    public function create(User $user, Modality $modality, Career $career): bool
    {
        return $this->actor($user)->allows(AcademicLoadPermission::ProposalCreate, Target::of(modality: $modality->getKey(), career: $career->getKey()));
    }

    public function view(User $user, CareerProposal $proposal): bool
    {
        return $this->can($user, AcademicLoadPermission::ProposalView, $proposal);
    }

    /**
     * Corregir se pregunta sobre la fila (`$item`), que es de un campus (M7, MS6): un coordinador con campus solo
     * cambia las de su campus y uno sin campus, las de todos. La fila sin campus de una modalidad sin salones (MS5) solo
     * la cubre un coordinador sin campus. No hay permiso de corregir «la propuesta» sin fila: cada columna se decide
     * por separado.
     */
    public function update(User $user, CareerProposal $proposal, ProposalItem $item): bool
    {
        return $item->career_proposal_id === $proposal->getKey()
            && $this->actor($user)->allows(AcademicLoadPermission::ProposalUpdate, Target::of(modality: $proposal->modality_id, campus: $item->campus_id, career: $proposal->career_id));
    }

    public function submit(User $user, CareerProposal $proposal): bool
    {
        return $this->can($user, AcademicLoadPermission::ProposalSubmit, $proposal);
    }

    private function can(User $user, AcademicLoadPermission $permission, CareerProposal $proposal): bool
    {
        return $this->actor($user)->allows($permission, Target::of(modality: $proposal->modality_id, career: $proposal->career_id));
    }
}
