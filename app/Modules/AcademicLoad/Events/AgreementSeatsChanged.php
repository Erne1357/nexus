<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Acuerdo entre departamentos: un grupo compartido aceptado tomó los lugares vigentes de su cupo (ChangeAgreementSeats), de
 * `from` a su `max_seats` de ahora. Sale después del commit.
 */
final class AgreementSeatsChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public InterdepartmentalAgreement $agreement, public int $from) {}
}
