<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Acuerdo entre departamentos: quien pidió el acuerdo lo canceló (CancelAgreement). Sale después del commit: lo que se revierte no avisa.
 * `replaced`: se canceló para pedir en ese mismo momento otro del mismo grupo, salón y día (SyncSessionLoans, al alargar
 * o mover una sesión), así que el aviso del nuevo basta y no hace falta avisar que se canceló.
 */
final class AgreementCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public InterdepartmentalAgreement $agreement, public bool $replaced = false) {}
}
