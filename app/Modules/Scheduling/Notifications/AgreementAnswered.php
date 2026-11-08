<?php

namespace App\Modules\Scheduling\Notifications;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Scheduling\Actions\Agreements\RejectRoomLoan;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * «Ciencias Básicas aceptó tu préstamo» o «rechazó» (tareas 3.6 y 3.7): a quien pidió el acuerdo, el departamento
 * `from`. Un rechazo lleva el motivo y, en un préstamo, que la sesión se quedó sin salón. Lleva a la pantalla Acuerdos
 * con el acuerdo abierto. Deja de poder abrirse si el acuerdo ya no existe o ya no ve acuerdos de ese departamento.
 */
final class AgreementAnswered extends NexusNotification
{
    public static function typeKey(): string
    {
        return 'agreement-answered';
    }

    public static function typeLabel(): string
    {
        return 'Acuerdo respondido';
    }

    public function __construct(private readonly InterdepartmentalAgreement $agreement) {}

    public function message(object $notifiable): NotificationMessage
    {
        $agreement = $this->agreement->loadMissing(['room', 'toDepartment']);
        $rejected = $agreement->status === AgreementStatus::Rejected;
        $what = $agreement->kind === AgreementKind::RoomLoan ? 'préstamo' : 'grupo compartido';
        $body = AgreementText::summary($agreement);

        if ($rejected) {
            $body = Str::limit("Motivo: {$agreement->comment}", 160).($agreement->kind === AgreementKind::RoomLoan && $this->roomWasCleared($agreement) ? ' La sesión se quedó sin salón.' : '');
        }

        return new NotificationMessage(
            title: "{$agreement->toDepartment->name} ".($rejected ? 'rechazó' : 'aceptó')." tu {$what}",
            body: $body,
            icon: $rejected ? 'o-x-circle' : 'o-check-circle',
            route: 'scheduling.agreements',
            parameters: ['acuerdo' => $agreement->getKey()],
            termId: $agreement->term_id,
            campusId: $agreement->campus_id,
            tone: $rejected ? 'warning' : 'success',
        );
    }

    /**
     * Si el rechazo quitó el salón de alguna sesión (la bitácora del grupo lo dice).
     */
    private function roomWasCleared(InterdepartmentalAgreement $agreement): bool
    {
        return Activity::query()->where('event', RejectRoomLoan::EVENT)->where('properties->agreement_id', $agreement->getKey())->exists();
    }

    public static function unavailableReason(Actor $context, NotificationMessage $message): ?string
    {
        $agreement = KeyLookup::find(InterdepartmentalAgreement::query(), $message->parameters['acuerdo'] ?? null);

        if ($agreement === null) {
            return 'Ese acuerdo ya no existe.';
        }

        if (! $context->allows(AcademicLoadPermission::AgreementView, Target::of(campus: $agreement->campus_id, unit: $agreement->from_department_id))) {
            return 'Ya no ves los acuerdos de ese departamento.';
        }

        return parent::unavailableReason($context, $message);
    }
}
