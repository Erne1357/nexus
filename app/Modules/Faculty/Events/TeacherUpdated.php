<?php

namespace App\Modules\Faculty\Events;

use App\Modules\Faculty\Models\Teacher;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambiaron los datos de un docente (UpdateTeacher) o su estado (SetTeacherActive). Sale después del commit, así
 * que nadie se entera de un cambio que se revirtió.
 */
final class TeacherUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changed  columnas que cambiaron (p. ej. `['name', 'is_active']`)
     */
    public function __construct(
        public Teacher $teacher,
        public array $changed,
    ) {}
}
