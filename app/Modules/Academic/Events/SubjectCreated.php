<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\Subject;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dio de alta una materia (CreateSubject). Sale después del commit, así que nadie se entera de un alta que se revirtió.
 */
final class SubjectCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subject $subject) {}
}
