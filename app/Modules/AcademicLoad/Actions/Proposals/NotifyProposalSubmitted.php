<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Notifications\ProposalSubmitted;
use App\Modules\AcademicLoad\Notifications\ProposalSubmittedByPeer;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;

/**
 * Avisa que una carrera envió (o reenvió) su propuesta (tareas 2.7 y 2.9; M6, MS18):
 *
 * - al jefe de cada departamento cuya parte quedó pendiente, con cuántas materias y grupos le pide en todos los campus
 *   (ProposalSubmitted). Los departamentos que ya la habían aceptado no reciben nada: su parte no cambia;
 * - a los demás coordinadores vigentes de la carrera en la modalidad, de cualquier campus, con cuántas materias y grupos
 *   pide en total (ProposalSubmittedByPeer): la propuesta es una para todos y la envió uno (MS18, supuesto por
 *   confirmar). Quien la envió (`submitted_by`) no recibe nada.
 *
 * Da a cuántos usuarios avisó.
 */
final class NotifyProposalSubmitted
{
    public function __construct(private readonly RecipientsQuery $recipients, private readonly NotifyHolders $notifyHolders) {}

    /**
     * @param  list<string>  $departmentIds
     */
    public function handle(CareerProposal $proposal, array $departmentIds, bool $resubmitted = false): int
    {
        $proposal->loadMissing([
            'career' => fn ($query) => $query->withTrashed(),
            'modality' => fn ($query) => $query->withTrashed(),
            'submitter',
        ]);

        return $this->notifyDepartments($proposal, $departmentIds, $resubmitted) + $this->notifyPeers($proposal, $resubmitted);
    }

    /**
     * @param  list<string>  $departmentIds
     */
    private function notifyDepartments(CareerProposal $proposal, array $departmentIds, bool $resubmitted): int
    {
        if ($departmentIds === []) {
            return 0;
        }

        $requested = ProposalItem::query()
            ->requested()
            ->where('proposal_items.career_proposal_id', $proposal->getKey())
            ->join('subjects', 'subjects.id', '=', 'proposal_items.subject_id')
            ->whereIn('subjects.department_id', $departmentIds)
            ->toBase()
            ->groupBy('subjects.department_id')
            ->selectRaw('subjects.department_id, count(distinct proposal_items.subject_id) as subjects, sum(proposal_items.requested_groups) as groups')
            ->get()
            ->keyBy('department_id');

        $notified = 0;

        foreach (Department::query()->whereKey($departmentIds)->orderBy('code')->get() as $department) {
            $row = $requested->get($department->getKey());

            if ($row === null) {
                continue;
            }

            $heads = $this->recipients->holders(AcademicLoadPermission::ProposalReviewDecide, Target::of(modality: $proposal->modality_id, unit: $department->getKey()));

            $notified += $this->notifyHolders->handle($heads, new ProposalSubmitted($proposal, $department, (int) $row->subjects, (int) $row->groups, $resubmitted))->count();
        }

        return $notified;
    }

    /**
     * Los coordinadores son los que pueden enviar la propuesta: el permiso no mira el campus, así que cuenta a los de
     * todos los campus y a los que no tienen uno.
     */
    private function notifyPeers(CareerProposal $proposal, bool $resubmitted): int
    {
        if (! $proposal->submitter instanceof User) {
            return 0;
        }

        $peers = array_values(array_filter(
            $this->recipients->holders(AcademicLoadPermission::ProposalSubmit, Target::of(modality: $proposal->modality_id, career: $proposal->career_id)),
            fn (array $holder): bool => ! $holder['user']->is($proposal->submitter),
        ));

        if ($peers === []) {
            return 0;
        }

        $requested = ProposalItem::query()->requested()->where('career_proposal_id', $proposal->getKey());
        $subjects = (clone $requested)->distinct()->count('subject_id');
        $groups = (int) $requested->sum('requested_groups');

        return $this->notifyHolders->handle($peers, new ProposalSubmittedByPeer($proposal, $proposal->submitter, $subjects, $groups, $resubmitted))->count();
    }
}
