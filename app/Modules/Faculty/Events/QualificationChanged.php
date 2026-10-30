<?php

namespace App\Modules\Faculty\Events;

use App\Modules\Faculty\Models\TeacherSubjectQualification;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió el perfil de un docente: se declaró una materia, cambió su nivel o se quitó (SetQualification,
 * RemoveQualification). Sale después del commit.
 */
final class QualificationChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public TeacherSubjectQualification $qualification) {}
}
