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
use Closure;
use Illuminate\Support\Collection;

/**
 * Quién hace cada cosa en los seeders demo de la propuesta, como en el uso normal (RecipientsQuery con el permiso real): el
 * coordinador que arma y envía, el que corrige una fila o una columna de su campus (M7) y el jefe que decide la parte de su
 * departamento. Cada uno se busca una vez por corrida (modalidad, campus, carrera o departamento): los nombramientos no
 * cambian mientras siembra y RecipientsQuery recorre a todos los candidatos.
 */
trait FindsProposalActors
{
    /** @var array<string, mixed> */
    private array $actorCache = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    private function rememberActor(string $key, Closure $resolve): mixed
    {
        if (! array_key_exists($key, $this->actorCache)) {
            $this->actorCache[$key] = $resolve();
        }

        return $this->actorCache[$key];
    }

    /**
     * Quien arma y envía la propuesta: el primer coordinador de la carrera en la modalidad que corrige las filas de un
     * campus de la demo; si no hay, cualquiera de la carrera en la modalidad (M6).
     *
     * @param  Collection<int, Campus>  $campuses
     */
    protected function coordinatorOf(Modality $modality, Career $career, Collection $campuses): ?User
    {
        return $this->rememberActor("coordinator|{$modality->getKey()}|{$career->getKey()}|".implode(',', $campuses->modelKeys()), fn (): ?User => $this->findCoordinator($modality, $career, $campuses));
    }

    /**
     * @param  Collection<int, Campus>  $campuses
     */
    private function findCoordinator(Modality $modality, Career $career, Collection $campuses): ?User
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
        return $this->rememberActor("column|{$proposal->modality_id}|{$campusId}|{$proposal->career_id}", fn (): User|false => app(RecipientsQuery::class)
            ->holders(AcademicLoadPermission::ProposalUpdate, Target::of(modality: $proposal->modality_id, campus: $campusId, career: $proposal->career_id))[0]['user'] ?? false);
    }

    protected function headOf(Modality $modality, Department $department): ?User
    {
        return $this->rememberActor("head|{$modality->getKey()}|{$department->getKey()}", fn (): ?User => app(RecipientsQuery::class)
            ->holders(AcademicLoadPermission::ProposalReviewDecide, Target::of(modality: $modality->getKey(), unit: $department->getKey()))[0]['user'] ?? null);
    }
}
