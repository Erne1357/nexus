<?php

namespace App\Modules\AcademicLoad\Database\Seeders\Concerns;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use Illuminate\Support\Collection;

/**
 * Quién hace cada cosa en los seeders demo de la propuesta, como en el uso normal (RecipientsQuery con el permiso real): el
 * coordinador que arma y envía, el que corrige una fila o una columna de su campus (M7) y el jefe que decide la parte de su
 * departamento.
 */
trait FindsProposalActors
{
    /**
     * Quien arma y envía la propuesta: el primer coordinador de la carrera en la modalidad que corrige las filas de un
     * campus de la demo; si no hay, cualquiera de la carrera en la modalidad (M6).
     *
     * @param  Collection<int, Campus>  $campuses
     */
    protected function coordinatorOf(Modality $modality, Career $career, Collection $campuses): ?User
    {
        $recipients = app(RecipientsQuery::class);

        foreach ($campuses as $campus) {
            $holder = $recipients->holders(AcademicLoadPermission::ProposalUpdate, Target::of(modality: $modality->getKey(), campus: $campus->getKey(), career: $career->getKey()))[0] ?? null;

            if ($holder !== null) {
                return $holder['user'];
            }
        }

        return $recipients->holders(AcademicLoadPermission::ProposalSubmit, Target::of(modality: $modality->getKey(), career: $career->getKey()))[0]['user'] ?? null;
    }

    /**
     * El primer coordinador que corrige la fila (ProposalUpdate sobre su campus, M7), o false si nadie la cubre.
     */
    protected function rowHolder(CareerProposal $proposal, ProposalItem $item): User|false
    {
        return $this->columnHolder($proposal, $item->campus_id);
    }

    /**
     * El primer coordinador que corrige una columna de la propuesta (su campus, o la columna sin campus), o false si nadie
     * la cubre.
     */
    protected function columnHolder(CareerProposal $proposal, ?string $campusId): User|false
    {
        return app(RecipientsQuery::class)->holders(AcademicLoadPermission::ProposalUpdate, Target::of(modality: $proposal->modality_id, campus: $campusId, career: $proposal->career_id))[0]['user'] ?? false;
    }

    protected function headOf(Modality $modality, Department $department): ?User
    {
        return app(RecipientsQuery::class)->holders(AcademicLoadPermission::ProposalReviewDecide, Target::of(modality: $modality->getKey(), unit: $department->getKey()))[0]['user'] ?? null;
    }
}
