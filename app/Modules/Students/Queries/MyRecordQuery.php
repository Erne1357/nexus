<?php

namespace App\Modules\Students\Queries;

use App\Modules\Core\Models\User;
use App\Modules\Students\Models\Student;

/**
 * «Mis datos» del alumno (AC-15): el alumno ligado a `$user` (`students.user_id`), con su carrera, su plan y su campus,
 * aunque estén borrados; nulo si no tiene.
 */
final class MyRecordQuery
{
    public function for(User $user): ?Student
    {
        return Student::query()
            ->where('user_id', $user->getKey())
            ->with([
                'career' => fn ($query) => $query->withTrashed(),
                'studyPlan' => fn ($query) => $query->withTrashed(),
                'campus' => fn ($query) => $query->withTrashed(),
            ])
            ->first();
    }
}
