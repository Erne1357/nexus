<?php

namespace App\Modules\AcademicLoad\Notifications;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Notifications\Concerns\LocatesProposal;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\KeyLookup;

/**
 * «Daniela Quintero envió la propuesta de Ingeniería en Sistemas Computacionales» (M6, MS18): a los demás
 * coordinadores vigentes de la carrera en la modalidad cuando uno de ellos la envía o la reenvía, porque la propuesta es
 * una para todos sus campus y se envía una vez. Dice cuántas materias y grupos pide en total y lleva a la propuesta,
 * en el periodo y la modalidad de esta (MS14). Deja de poder abrirse si la propuesta ya no existe o el usuario ya no
 * coordina esa carrera en la modalidad.
 */
final class ProposalSubmittedByPeer extends NexusNotification
{
    use LocatesProposal;

    public static function typeKey(): string
    {
        return 'proposal-submitted-by-peer';
    }

    public static function typeLabel(): string
    {
        return 'Propuesta enviada por otro coordinador';
    }

    public function __construct(
        private readonly CareerProposal $proposal,
        private readonly User $submitter,
        private readonly int $subjects,
        private readonly int $groups,
        private readonly bool $resubmitted,
    ) {}

    public function message(object $notifiable): NotificationMessage
    {
        $subjects = $this->subjects === 1 ? '1 materia' : "{$this->subjects} materias";
        $groups = $this->groups === 1 ? '1 grupo' : "{$this->groups} grupos";
        $verb = $this->resubmitted ? 'reenvió' : 'envió';

        return new NotificationMessage(
            title: "{$this->submitter->name} {$verb} la propuesta de {$this->proposal->career->name}",
            body: "{$this->proposal->modality->name} · {$subjects}, {$groups}",
            icon: 'o-paper-airplane',
            route: 'academic-load.proposal',
            parameters: ['carrera' => $this->proposal->career_id],
            termId: $this->proposal->term_id,
            modalityId: $this->proposal->modality_id,
        );
    }

    public static function unavailableReason(Actor $context, NotificationMessage $message): ?string
    {
        $career = KeyLookup::find(Career::query()->withTrashed(), $message->parameters['carrera'] ?? null);
        $proposal = self::proposalOf($message, $career);

        if ($proposal === null) {
            return 'Esa propuesta ya no existe.';
        }

        if (! $context->allows(AcademicLoadPermission::ProposalSubmit, Target::of(modality: $proposal->modality_id, career: $career->getKey()))) {
            return "Ya no coordinas {$career->name}.";
        }

        return parent::unavailableReason($context, $message);
    }
}
