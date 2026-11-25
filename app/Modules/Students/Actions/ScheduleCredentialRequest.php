<?php

namespace App\Modules\Students\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Students\Enums\CredentialRequestStatus as Status;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Notifications\CredentialRequestUpdated;
use App\Modules\Students\Rules\CredentialRequestRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CO-08: cita al alumno (fecha, hora y lugar) desde solicitada, o reprograma desde citada. Avisa (CO-09).
 */
final class ScheduleCredentialRequest
{
    public function handle(CredentialRequest $request, CarbonImmutable $at, string $place, User $by): void
    {
        $place = trim($place);

        if ($place === '') {
            throw ValidationException::withMessages(['place' => 'Escribe el lugar de la cita.']);
        }

        if ($at->lt(now())) {
            throw ValidationException::withMessages(['scheduled_at' => 'La cita debe ser a partir de hoy.']);
        }

        $reschedule = DB::transaction(function () use ($request, $at, $place, $by): bool {
            $locked = CredentialRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            CredentialRequestRules::assertStatus($locked, [Status::Solicitada, Status::Citada], 'citar');

            $reschedule = $locked->status === Status::Citada;
            $locked->forceFill(['status' => Status::Citada, 'scheduled_at' => $at, 'place' => $place, 'handled_by' => $by->getKey()])->save();

            activity()->performedOn($locked)->causedBy($by)->event($reschedule ? 'credential_request_rescheduled' : 'credential_request_scheduled')
                ->withProperties(['scheduled_at' => $at->toIso8601String(), 'place' => $place])
                ->log($reschedule ? 'Reprogramó la cita de una solicitud de credencial' : 'Citó a un alumno por su solicitud de credencial');

            $request->setRawAttributes($locked->getAttributes(), true);

            CredentialRequestUpdated::send($locked, $reschedule ? CredentialRequestUpdated::RESCHEDULED : CredentialRequestUpdated::SCHEDULED);

            return $reschedule;
        });
    }
}
