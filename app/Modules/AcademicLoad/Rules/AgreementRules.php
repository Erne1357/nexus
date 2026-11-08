<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Models\Room;
use Illuminate\Validation\ValidationException;

/**
 * Cuándo se pide, decide o cancela un acuerdo entre departamentos y qué lleva cada uno (reglas-de-negocio.md,
 * "Acuerdos entre departamentos"; HC-07 y HC-10): solo se decide un acuerdo propuesto; solo se cancela uno vivo
 * (propuesto o aceptado); el préstamo es de un salón del campus con dueño distinto de quien lo pide; el cupo es de
 * una carrera de otro departamento. El motivo de un rechazo es obligatorio, recortado y de hasta 1000 caracteres
 * (supuesto por confirmar, como el de ProposalReviewRules). Las páginas pueden usar los topes para su validación
 * de forma.
 */
final class AgreementRules
{
    public const int MAX_COMMENT_LENGTH = 1000;

    public const int MAX_SEATS = 999;

    /**
     * El comentario recortado, o una ValidationException sobre `comment` si queda vacío o es muy largo.
     *
     * @throws ValidationException
     */
    public function normalizeComment(string $comment): string
    {
        $comment = trim($comment);

        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => 'Escribe por qué rechazas el acuerdo.']);
        }

        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw ValidationException::withMessages(['comment' => 'El comentario lleva hasta '.self::MAX_COMMENT_LENGTH.' caracteres.']);
        }

        return $comment;
    }

    /**
     * El acuerdo se puede aceptar o rechazar solo si sigue propuesto (estado ya leído con bloqueo).
     *
     * @throws ValidationException
     */
    public function ensureProposed(InterdepartmentalAgreement $agreement): void
    {
        if ($agreement->status !== AgreementStatus::Proposed) {
            throw ValidationException::withMessages(['status' => 'Este acuerdo ya se decidió.']);
        }
    }

    /**
     * El acuerdo se puede cancelar solo si está vivo: propuesto o aceptado (estado ya leído con bloqueo).
     *
     * @throws ValidationException
     */
    public function ensureLive(InterdepartmentalAgreement $agreement): void
    {
        if (! $agreement->status->isLive()) {
            throw ValidationException::withMessages(['status' => 'Este acuerdo ya no está vivo.']);
        }
    }

    /**
     * HC-10: el salón es del campus, tiene dueño y el dueño no es quien lo pide.
     *
     * @throws ValidationException
     */
    public function ensureRoomNeedsLoan(Room $room, Campus $campus, Department $from): void
    {
        if ($room->campus_id !== $campus->getKey()) {
            throw ValidationException::withMessages(['room_id' => 'El salón es de otro campus.']);
        }

        if ($room->department_id === null || $room->department_id === $from->getKey()) {
            throw ValidationException::withMessages(['room_id' => 'El salón es de tu departamento o no tiene dueño: no necesita préstamo.']);
        }
    }

    /**
     * El día es de 1 (lunes) a 6 (sábado), las horas son válidas (ClockTime) y la de fin es posterior a la de inicio.
     *
     * @throws ValidationException
     */
    public function ensureSlot(int $day, string $startsAt, string $endsAt): void
    {
        $errors = [];

        if ($day < 1 || $day > 6) {
            $errors['day'] = 'El día va de lunes a sábado.';
        }

        if (! ClockTime::isValid($startsAt)) {
            $errors['starts_at'] = 'La hora de inicio no es válida.';
        }

        if (! ClockTime::isValid($endsAt)) {
            $errors['ends_at'] = 'La hora de fin no es válida.';
        }

        if ($errors === [] && ClockTime::toSeconds($endsAt) <= ClockTime::toSeconds($startsAt)) {
            $errors['starts_at'] = 'La hora de fin debe ser posterior a la de inicio.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Antes de aceptar: el departamento al que va dirigido (`to`) sigue siendo el dueño del salón (préstamo) o de la
     * carrera (grupo compartido), con la lectura bloqueada en compartido para que no cambie durante la aceptación.
     *
     * @throws ValidationException
     */
    public function ensureRecipientStillOwns(InterdepartmentalAgreement $agreement): void
    {
        if ($agreement->kind === AgreementKind::RoomLoan) {
            $owner = Room::query()->whereKey($agreement->room_id)->sharedLock()->value('department_id');

            if ($owner !== $agreement->to_department_id) {
                throw ValidationException::withMessages(['room_id' => 'El salón ya no es de tu departamento.']);
            }

            return;
        }

        $owner = Career::query()->withTrashed()->whereKey($agreement->career_id)->sharedLock()->value('department_id');

        if ($owner !== $agreement->to_department_id) {
            throw ValidationException::withMessages(['career_id' => 'La carrera ya no es de tu departamento.']);
        }
    }

    /**
     * HC-07: la carrera es de otro departamento; la de quien propone no necesita acuerdo.
     *
     * @throws ValidationException
     */
    public function ensureCareerNeedsAgreement(Career $career, Department $from): void
    {
        if ($career->department_id === $from->getKey()) {
            throw ValidationException::withMessages(['career_id' => 'La carrera es de tu departamento: su cupo no necesita acuerdo.']);
        }
    }

    /**
     * @throws ValidationException
     */
    public function ensureSeats(int $seats): void
    {
        if ($seats < 1 || $seats > self::MAX_SEATS) {
            throw ValidationException::withMessages(['max_seats' => 'Los lugares van de 1 a '.self::MAX_SEATS.'.']);
        }
    }
}
