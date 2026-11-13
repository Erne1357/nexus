<?php

namespace App\Modules\AcademicLoad\Notifications;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Notifications\Concerns\LocatesProposal;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\KeyLookup;

/**
 * «Ingeniería en Sistemas Computacionales envió su propuesta» (tareas 2.7 y 2.9): al jefe de un departamento
 * cuya parte de la propuesta quedó pendiente, con la modalidad y cuántas materias y grupos le pide la carrera en todos
 * sus campus (sin repetir su departamento: el jefe ya sabe cuál es). Lleva a la bandeja de su departamento con esa
 * carrera elegida (tarea 2.8), en el periodo y la modalidad de la propuesta (M6, MS14). Deja de poder abrirse si la
 * propuesta o el departamento ya no existen, o el usuario ya no es su jefe.
 */
final class ProposalSubmitted extends NexusNotification
{
    use LocatesProposal;

    public static function typeKey(): string
    {
        return 'proposal-submitted';
    }

    public static function typeLabel(): string
    {
        return 'Propuesta enviada';
    }

    public function __construct(
        private readonly CareerProposal $proposal,
        private readonly Department $department,
        private readonly int $subjects,
        private readonly int $groups,
        private readonly bool $resubmitted,
    ) {}

    public function message(object $notifiable): NotificationMessage
    {
        $career = $this->proposal->career->name;
        $subjects = $this->subjects === 1 ? '1 materia' : "{$this->subjects} materias";
        $groups = $this->groups === 1 ? '1 grupo' : "{$this->groups} grupos";

        return new NotificationMessage(
            title: $this->resubmitted ? "{$career} reenvió su propuesta" : "{$career} envió su propuesta",
            body: "{$this->proposal->modality->name} · {$subjects}, {$groups}",
            icon: 'o-paper-airplane',
            route: 'academic-load.inbox',
            parameters: ['depto' => $this->department->code, 'carrera' => $this->proposal->career_id],
            termId: $this->proposal->term_id,
            modalityId: $this->proposal->modality_id,
        );
    }

    public static function unavailableReason(Actor $context, NotificationMessage $message): ?string
    {
        $career = $message->parameters['carrera'] ?? null;
        $proposal = self::proposalOf($message, is_string($career) ? KeyLookup::find(Career::query()->withTrashed(), $career) : null);
        $department = Department::query()->where('code', $message->parameters['depto'] ?? null)->first();

        if ($proposal === null || $department === null) {
            return 'Esa propuesta ya no existe.';
        }

        if (! $context->allows(AcademicLoadPermission::ProposalReviewDecide, Target::of(modality: $proposal->modality_id, unit: $department->getKey()))) {
            return "Ya no eres jefe de {$department->name}.";
        }

        return parent::unavailableReason($context, $message);
    }
}
