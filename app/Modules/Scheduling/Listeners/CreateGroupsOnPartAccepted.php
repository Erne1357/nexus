<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\AcademicLoad\Events\ProposalReviewAccepted;
use App\Modules\Scheduling\Actions\CreateGroupsFromProposal;

/**
 * Cuando un departamento acepta su parte de una propuesta de carrera nacen los grupos de sus materias
 * (CreateGroupsFromProposal; tarea 3.3). La bitácora lleva a quien aceptó, que puede faltar.
 */
final class CreateGroupsOnPartAccepted
{
    public function __construct(private readonly CreateGroupsFromProposal $createGroups) {}

    public function handle(ProposalReviewAccepted $event): void
    {
        $this->createGroups->handle($event->review, $event->review->decider);
    }
}
