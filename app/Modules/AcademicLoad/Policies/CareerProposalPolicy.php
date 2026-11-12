<?php

namespace App\Modules\AcademicLoad\Policies;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién arma, ve, corrige y envía la propuesta de una carrera, según docs/dominio/reglas-de-negocio.md,
 * "Aprobaciones y permisos" (filas "Ver la propuesta de carrera" y "Armar, corregir y enviar la propuesta de
 * carrera"):
 *
 * - Arma (StartCareerProposal), corrige (UpdateProposalItem) y envía (SubmitCareerProposal) el coordinador de
 *   la carrera en esa modalidad; la fila que corrige es de un campus y un coordinador con campus solo cambia las
 *   suyas (AZ-S17, MS6).
 * - La ve también la subdirección, en cualquier campus y solo lectura (supuesto por confirmar).
 *
 * El admin pasa por la concesión implícita del registro. Los jefes deciden la parte de su departamento desde su bandeja, con
 * ProposalReviewPolicy.
 */
final class CareerProposalPolicy
{
    use AuthorizesActor;

    public function create(User $user, Campus $campus, Career $career): bool
    {
        return $this->actor($user)->allows(AcademicLoadPermission::ProposalCreate, Target::of(modality: $campus->modality_id, career: $career->getKey()));
    }

    public function view(User $user, CareerProposal $proposal): bool
    {
        return $this->can($user, AcademicLoadPermission::ProposalView, $proposal);
    }

    /**
     * Corregir se pregunta sobre la fila (`$item`), que es de un campus (M7, MS6): un coordinador con campus solo
     * cambia las de su campus. Sin fila, sobre el campus de la propuesta.
     */
    public function update(User $user, CareerProposal $proposal, ?ProposalItem $item = null): bool
    {
        return $this->actor($user)->allows(AcademicLoadPermission::ProposalUpdate, Target::of(modality: $proposal->modality_id, campus: $item->campus_id ?? $proposal->campus_id, career: $proposal->career_id));
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
