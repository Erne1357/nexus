<?php

namespace App\Modules\AcademicLoad\Actions\Proposals;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Notifications\ProposalSubmitted;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Queries\RecipientsQuery;

/**
 * Avisa que una carrera envió (o reenvió) su propuesta (tareas 2.7 y 2.9): al jefe de cada departamento cuya
 * parte quedó pendiente, con cuántas materias y grupos le pide (ProposalSubmitted). Los departamentos que ya la
 * habían aceptado no reciben nada: su parte no cambia. Da a cuántos usuarios avisó.
 */
final class NotifyProposalSubmitted
{
    public function __construct(private readonly RecipientsQuery $recipients, private readonly NotifyHolders $notifyHolders) {}

    /**
     * @param  list<string>  $departmentIds
     */
    public function handle(CareerProposal $proposal, array $departmentIds, bool $resubmitted = false): int
    {
        if ($departmentIds === []) {
            return 0;
        }

        $proposal->loadMissing(['career' => fn ($query) => $query->withTrashed(), 'campus' => fn ($query) => $query->withTrashed()]);

        $requested = ProposalItem::query()
            ->requested()
            ->where('proposal_items.career_proposal_id', $proposal->getKey())
            ->join('subjects', 'subjects.id', '=', 'proposal_items.subject_id')
            ->whereIn('subjects.department_id', $departmentIds)
            ->toBase()
            ->groupBy('subjects.department_id')
            ->selectRaw('subjects.department_id, count(*) as subjects, sum(proposal_items.requested_groups) as groups')
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
}
