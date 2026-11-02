<?php

namespace App\Modules\Faculty\Events;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió el perfil de un docente: se declaró una materia, cambió su nivel o se quitó (SetQualification,
 * RemoveQualification). Sale después del commit y trae a quien lo hizo (`actor`, null sin sesión) para quien lo escuche, aunque sea en un job.
 */
final class QualificationChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public TeacherSubjectQualification $qualification, public ?User $actor = null) {}
}
