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

/**
 * «Ingeniería Industrial te pide LC-201» (tareas 3.6 y 3.7): al jefe del departamento al que va dirigido un acuerdo
 * propuesto, con lo que se pide. Lleva a la pantalla Acuerdos con el acuerdo abierto, en el periodo y campus del
 * acuerdo. Deja de poder abrirse si el acuerdo ya no existe, si ya no lo puede decidir o si ya no está propuesto.
 */
final class AgreementReceived extends NexusNotification
{
    public static function typeKey(): string
    {
        return 'agreement-received';
    }

    public static function typeLabel(): string
    {
        return 'Acuerdo por decidir';
    }

    public function __construct(private readonly InterdepartmentalAgreement $agreement) {}

    public function message(object $notifiable): NotificationMessage
    {
        $agreement = $this->agreement->loadMissing(['room' => fn ($room) => $room->withTrashed(), 'career' => fn ($career) => $career->withTrashed(), 'fromDepartment' => fn ($department) => $department->withTrashed()]);
        $from = $agreement->fromDepartment->name;

        return new NotificationMessage(
            title: $agreement->kind === AgreementKind::RoomLoan ? "{$from} te pide {$agreement->room?->code}" : "{$from} propone un grupo compartido",
            body: AgreementText::summary($agreement),
            icon: $agreement->kind->icon(),
            route: 'scheduling.agreements',
            parameters: ['acuerdo' => $agreement->getKey()],
            termId: $agreement->term_id,
            campusId: $agreement->campus_id,
            tone: 'warning',
        );
    }

    public static function unavailableReason(Actor $context, NotificationMessage $message): ?string
    {
        $agreement = KeyLookup::find(InterdepartmentalAgreement::query(), $message->parameters['acuerdo'] ?? null);

        if ($agreement === null) {
            return 'Ese acuerdo ya no existe.';
        }

        if (! $context->allows(AcademicLoadPermission::AgreementDecide, Target::of(modality: $agreement->modality_id, unit: $agreement->to_department_id))) {
            return 'Ya no decides acuerdos de ese departamento.';
        }

        if ($agreement->status !== AgreementStatus::Proposed) {
            return $agreement->status === AgreementStatus::Cancelled ? 'Este acuerdo se canceló.' : 'Este acuerdo ya se decidió.';
        }

        return parent::unavailableReason($context, $message);
    }
}
