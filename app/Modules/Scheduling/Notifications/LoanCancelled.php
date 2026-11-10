<?php

namespace App\Modules\Scheduling\Notifications;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Support\WeekGrid;
use App\Modules\Scheduling\Validation\Minutes;

/**
 * «Ingeniería Industrial canceló el préstamo de LC-201 del lunes de 9:00 a 11:00» (reglas-de-negocio.md, "Acuerdos
 * entre departamentos"; confirmado el 8 de noviembre de 2026): al jefe del departamento dueño del salón, el `to`, cuando
 * quien lo pidió cancela un préstamo vivo, a mano o porque su sesión se movió o perdió el salón. Dice si ya no hay nada
 * que decidir o si el préstamo estaba aceptado. Lleva a la pantalla Acuerdos con el acuerdo (cancelado) abierto. Deja
 * de poder abrirse si el acuerdo ya no existe o ya no ve acuerdos de ese departamento.
 */
final class LoanCancelled extends NexusNotification
{
    public static function typeKey(): string
    {
        return 'loan-cancelled';
    }

    public static function typeLabel(): string
    {
        return 'Préstamo cancelado';
    }

    public function __construct(private readonly InterdepartmentalAgreement $agreement) {}

    public function message(object $notifiable): NotificationMessage
    {
        $agreement = $this->agreement->loadMissing(['room' => fn ($room) => $room->withTrashed(), 'fromDepartment' => fn ($department) => $department->withTrashed()]);

        return new NotificationMessage(
            title: "{$agreement->fromDepartment->name} canceló el préstamo de {$agreement->room?->code} del {$this->when($agreement)}",
            body: $agreement->decided_at === null ? 'Ya no tienes que decidirlo.' : 'Estaba aceptado: el salón ya no queda prestado a esa hora.',
            icon: $agreement->kind->icon(),
            route: 'scheduling.agreements',
            parameters: ['acuerdo' => $agreement->getKey()],
            termId: $agreement->term_id,
            campusId: $agreement->campus_id,
            tone: 'neutral',
        );
    }

    /**
     * «lunes de 9:00 a 11:00», con las horas como las pinta la semana (WeekGrid::clock, sin cero a la izquierda).
     */
    private function when(InterdepartmentalAgreement $agreement): string
    {
        return Minutes::dayName((int) $agreement->day).' de '.WeekGrid::clock($agreement->starts_at).' a '.WeekGrid::clock($agreement->ends_at);
    }

    public static function unavailableReason(Actor $context, NotificationMessage $message): ?string
    {
        $agreement = KeyLookup::find(InterdepartmentalAgreement::query(), $message->parameters['acuerdo'] ?? null);

        if ($agreement === null) {
            return 'Ese acuerdo ya no existe.';
        }

        if (! $context->allows(AcademicLoadPermission::AgreementView, Target::of(campus: $agreement->campus_id, unit: $agreement->to_department_id))) {
            return 'Ya no ves los acuerdos de ese departamento.';
        }

        return parent::unavailableReason($context, $message);
    }
}
