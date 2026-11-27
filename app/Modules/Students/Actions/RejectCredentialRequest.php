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
 * CO-08: rechaza con motivo desde solicitada, citada o lista. Avisa (CO-09).
 */
final class RejectCredentialRequest
{
    public function handle(CredentialRequest $request, string $reason, User $by): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Escribe el motivo del rechazo.']);
        }

        DB::transaction(function () use ($request, $reason, $by): void {
            $locked = CredentialRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            CredentialRequestRules::assertStatus($locked, Status::open(), 'rechazar');

            $locked->forceFill(['status' => Status::Rechazada, 'rejected_at' => now(), 'rejection_reason' => $reason, 'handled_by' => $by->getKey()])->save();
            activity()->performedOn($locked)->causedBy($by)->event('credential_request_rejected')->withProperties(['reason' => $reason])->log('Rechazó una solicitud de credencial');

            $request->setRawAttributes($locked->getAttributes(), true);

            CredentialRequestUpdated::send($locked, CredentialRequestUpdated::REJECTED);
        });
    }
}
