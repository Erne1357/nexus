<?php

namespace App\Modules\Faculty\Events;

use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió una adscripción de un docente: se quitó con «Quitar de mi departamento» (RemoveAdscription; la que llega está
 * borrada, y si era la principal, la principal pasó a otra). Sale después del commit.
 */
final class AdscriptionChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public TeacherDepartment $adscription) {}
}
