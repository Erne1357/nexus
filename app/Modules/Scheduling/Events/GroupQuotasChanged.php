<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\Group;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cupos por carrera de un grupo: cambiaron sus lugares, se quitó o se agregó una carrera o se aceptó un acuerdo
 * (SetGroupQuotas, AcceptSharedGroup). Sale después del commit: lo que se revierte no avisa.
 */
final class GroupQuotasChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Group $group) {}
}
