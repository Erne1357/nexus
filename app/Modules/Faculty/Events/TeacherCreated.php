<?php

namespace App\Modules\Faculty\Events;

use App\Modules\Faculty\Models\Teacher;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dio de alta un docente (CreateTeacher), ya con su adscripción principal. Sale después del commit.
 */
final class TeacherCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Teacher $teacher) {}
}
