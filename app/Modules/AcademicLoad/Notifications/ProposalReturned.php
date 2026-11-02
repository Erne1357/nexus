<?php

namespace App\Modules\AcademicLoad\Notifications;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Support\Str;

/**
 * «Ciencias Básicas regresó tu propuesta» (tareas 2.8 y 2.9): al coordinador de la carrera en el campus, con el
 * comentario del jefe. Lleva a la propuesta con las materias de ese departamento resaltadas, en el periodo y el
 * campus de la propuesta. Deja de poder abrirse si la propuesta ya no existe o el usuario ya no coordina esa
 * carrera en ese campus.
 */
final class ProposalReturned extends NexusNotification
{
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
            campusId: $this->proposal->campus_id,
            tone: 'warning',
        );
    }

    public static function unavailableReason(User $user, NotificationMessage $message): ?string
    {
        $career = KeyLookup::find(Career::query(), $message->parameters['carrera'] ?? null);
        $exists = $career !== null && $message->termId !== null && $message->campusId !== null && CareerProposal::query()
            ->where('term_id', $message->termId)
            ->where('campus_id', $message->campusId)
            ->where('career_id', $career->getKey())
            ->exists();

        if (! $exists) {
            return 'Esa propuesta ya no existe.';
        }

        if (! app(ScopedContext::class)->forUser($user)->covers(Role::Coordinador, $message->campusId, career: $career->getKey())) {
            return "Ya no coordinas {$career->name} en ese campus.";
        }

        return parent::unavailableReason($user, $message);
    }
}
