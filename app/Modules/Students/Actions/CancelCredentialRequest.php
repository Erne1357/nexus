<?php

namespace App\Modules\Students\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Students\Enums\CredentialRequestStatus;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Rules\CredentialRequestRules;
use Illuminate\Support\Facades\DB;

/**
 * CO-S2 (supuesto por confirmar): el alumno cancela su solicitud solo mientras está solicitada. No avisa a nadie (CO-09).
 * Quién puede lo decide CredentialRequestPolicy::cancel().
 */
final class CancelCredentialRequest
{
    public function handle(CredentialRequest $request, User $by): void
    {
        DB::transaction(function () use ($request, $by): void {
            $locked = CredentialRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            CredentialRequestRules::assertStatus($locked, [CredentialRequestStatus::Solicitada], 'cancelar');

            $locked->forceFill(['status' => CredentialRequestStatus::Cancelada, 'cancelled_at' => now()])->save();
            activity()->performedOn($locked)->causedBy($by)->event('credential_request_cancelled')->log('Canceló su solicitud de credencial');

            $request->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
