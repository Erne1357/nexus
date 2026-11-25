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
 * CO-08: la credencial física está lista para recoger. Desde citada y solo si la solicitud incluye reposición (una de
 * solo foto no tiene qué recoger). Avisa (CO-09).
 */
final class MarkCredentialRequestReady
{
    public function handle(CredentialRequest $request, User $by): void
    {
        DB::transaction(function () use ($request, $by): void {
            $locked = CredentialRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            CredentialRequestRules::assertStatus($locked, [Status::Citada], 'marcar como lista');

            if (! $locked->wants_reposition) {
                throw ValidationException::withMessages(['request' => 'Una solicitud de solo foto no tiene credencial que recoger.']);
            }

            $locked->forceFill(['status' => Status::Lista, 'ready_at' => now(), 'handled_by' => $by->getKey()])->save();
            activity()->performedOn($locked)->causedBy($by)->event('credential_request_ready')->log('Marcó lista una credencial para recoger');

            $request->setRawAttributes($locked->getAttributes(), true);

            CredentialRequestUpdated::send($locked, CredentialRequestUpdated::READY);
        });
    }
}
