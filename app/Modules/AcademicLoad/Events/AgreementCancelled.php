<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Acuerdo entre departamentos: quien pidió el acuerdo lo canceló (CancelAgreement). Sale después del commit: lo que se revierte no avisa.
 */
final class AgreementCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public InterdepartmentalAgreement $agreement) {}
}
