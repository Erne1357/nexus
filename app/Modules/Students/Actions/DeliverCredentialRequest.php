<?php

namespace App\Modules\Students\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Students\Enums\CredentialRequestStatus as Status;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Notifications\CredentialRequestUpdated;
use App\Modules\Students\Rules\CredentialRequestRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CO-08: entrega la solicitud, desde lista o, si es de solo foto, desde citada. CO-S8 (supuesto por confirmar): si
 * incluye foto, exige `photo_updated_at`, que pone TouchOpenPhotoRequests cuando se sube una foto del alumno con la
 * solicitud abierta. Avisa (CO-09).
 */
final class DeliverCredentialRequest
{
    public function handle(CredentialRequest $request, User $by): void
    {
        DB::transaction(function () use ($request, $by): void {
            $locked = CredentialRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            CredentialRequestRules::assertStatus($locked, $locked->wants_reposition ? [Status::Lista] : [Status::Lista, Status::Citada], 'entregar');

            if ($locked->wants_photo && $locked->photo_updated_at === null) {
                throw ValidationException::withMessages(['request' => 'Sube la foto del alumno antes de entregar.']);
            }

            $locked->forceFill(['status' => Status::Entregada, 'delivered_at' => now(), 'handled_by' => $by->getKey()])->save();
            activity()->performedOn($locked)->causedBy($by)->event('credential_request_delivered')->log('Entregó una solicitud de credencial');

            $request->setRawAttributes($locked->getAttributes(), true);

            CredentialRequestUpdated::send($locked, CredentialRequestUpdated::DELIVERED);
        });
    }
}
