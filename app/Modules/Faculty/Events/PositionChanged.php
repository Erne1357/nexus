<?php

namespace App\Modules\Faculty\Events;

use App\Modules\Faculty\Models\TeacherPosition;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió una plaza de un docente: se agregó, se cambió o se quitó (AddPosition, UpdatePosition, RemovePosition).
 * Cambia sus horas base. Sale después del commit.
 */
final class PositionChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public TeacherPosition $position) {}
}
