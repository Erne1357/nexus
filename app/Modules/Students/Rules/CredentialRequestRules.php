<?php

namespace App\Modules\Students\Rules;

use App\Modules\Students\Enums\CredentialRequestStatus;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * CO-07 y CO-08: qué puede pedir un alumno y qué transiciones tiene una solicitud. Puro salvo por las dos lecturas de
 * `availability()`.
 */
final class CredentialRequestRules
{
    public const string TIMEZONE = 'America/Ciudad_Juarez';

    public const string OPEN_MESSAGE = 'Ya tienes una abierta.';

    /**
     * CO-07: por tipo, null si el alumno puede pedirlo, o el motivo en español: «Ya tienes una abierta.» o «Podrás
     * pedirla desde el 12 de mayo de 2027.» (el plazo del tipo contado desde su última solicitud entregada; 0 es sin
     * límite).
     *
     * @return array{reposition: ?string, photo: ?string}
     */
    public static function availability(Student $student, CarbonImmutable $now, ?CredentialRequestSettings $settings = null): array
    {
        $settings ??= CredentialRequestSettings::fromSettings();

        return [
            'reposition' => self::reasonFor($student, 'wants_reposition', $settings->repositionDays, $now),
            'photo' => self::reasonFor($student, 'wants_photo', $settings->photoDays, $now),
        ];
    }

    /**
     * CO-08: lanza si la solicitud no está en uno de los estados que admite la acción.
     *
     * @param  list<CredentialRequestStatus>  $allowed
     *
     * @throws ValidationException
     */
    public static function assertStatus(CredentialRequest $request, array $allowed, string $action): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages(['request' => "No se puede {$action} una solicitud {$request->status->label()}."]);
        }
    }

    private static function reasonFor(Student $student, string $column, int $days, CarbonImmutable $now): ?string
    {
        $requests = CredentialRequest::query()->where('student_id', $student->getKey())->where($column, true);

        if ((clone $requests)->open()->exists()) {
            return self::OPEN_MESSAGE;
        }

        if ($days === 0) {
            return null;
        }

        $last = (clone $requests)->where('status', CredentialRequestStatus::Entregada)->max('delivered_at');

        if ($last === null) {
            return null;
        }

        // Por día calendario de Ciudad Juárez: se puede pedir desde las 00:00 de ese día, no desde la hora de la entrega.
        $from = CarbonImmutable::parse($last)->setTimezone(self::TIMEZONE)->startOfDay()->addDays($days);

        return $from->greaterThan($now)
            ? 'Podrás pedirla desde el '.$from->locale('es')->isoFormat('D [de] MMMM [de] YYYY').'.'
            : null;
    }
}
