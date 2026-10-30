<?php

namespace App\Modules\Faculty\Events;

use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió una adscripción de un docente: se agregó o se restauró una que se había quitado (AddAdscription), se quitó
 * (RemoveAdscription) o cambió la principal (SetPrimaryAdscription). Sale
 * después del commit.
 */
final class AdscriptionChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public TeacherDepartment $adscription) {}
}
