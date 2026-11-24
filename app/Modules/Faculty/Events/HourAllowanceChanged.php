<?php

namespace App\Modules\Faculty\Events;

use App\Modules\Faculty\Models\TeacherHourAllowance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambiaron las horas autorizadas de un docente en un periodo (GrantHourAllowance, SEL-12): cambia el límite de HC-08.
 * Sale después del commit.
 */
final class HourAllowanceChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public TeacherHourAllowance $allowance) {}
}
