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
use Illuminate\Support\Str;

/**
 * «Ciencias Básicas regresó tu propuesta» (tareas 2.8 y 2.9): a cada coordinador de la carrera en la modalidad, de
 * cualquier campus (MS18, supuesto por confirmar), con el comentario del jefe. Lleva a la propuesta con las materias de
 * ese departamento resaltadas, en el periodo y la modalidad de la propuesta (M6, MS14). Deja de poder abrirse si la
 * propuesta ya no existe o el usuario ya no coordina esa carrera en la modalidad.
 */
final class ProposalReturned extends NexusNotification
{
    use LocatesProposal;

    public static function typeKey(): string
    {
        return 'proposal-returned';
    }

    public static function typeLabel(): string
    {
        return 'Propuesta regresada';
    }

    public function __construct(
        private readonly CareerProposal $proposal,
        private readonly Department $department,
        private readonly string $comment,
    ) {}

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: "{$this->department->name} regresó tu propuesta",
            body: Str::limit($this->comment, 160),
            icon: 'o-arrow-uturn-left',
            route: 'academic-load.proposal',
            parameters: ['carrera' => $this->proposal->career_id, 'depto' => $this->department->code],
            termId: $this->proposal->term_id,
            tone: 'warning',
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
