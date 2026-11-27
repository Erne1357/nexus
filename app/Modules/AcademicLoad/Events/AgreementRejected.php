<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Acuerdo entre departamentos: el departamento rechazó un acuerdo con motivo (RejectAgreement). Sale después del commit: lo que se revierte no avisa.
 */
final class AgreementRejected implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public InterdepartmentalAgreement $agreement) {}
}
