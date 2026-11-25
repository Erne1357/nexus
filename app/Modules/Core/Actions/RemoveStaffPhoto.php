<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Quita la foto vigente de una persona del personal (CO-03): `photo_path` y `photo_updated_at` quedan nulas y los
 * archivos se quedan en el disco. Deja bitácora, «Quitó la foto». Sin foto no hace nada.
 */
final class RemoveStaffPhoto
{
    public function handle(User $person, User $by): void
    {
        DB::transaction(function () use ($person, $by): void {
            if ($person->photo_path === null) {
                return;
            }

            $person->forceFill(['photo_path' => null, 'photo_updated_at' => null])->save();

            activity()->performedOn($person)->causedBy($by)->event(ActivityEvent::Updated)->log('Quitó la foto');
        });
    }
}
