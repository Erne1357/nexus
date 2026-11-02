<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Enums\StageSource;
use App\Modules\Core\Enums\StageStatus;
use Carbon\CarbonImmutable;

/**
 * La ventana efectiva de una etapa para un campus (TermCalendarQuery), con su estado respecto a hoy.
 * `daysLeft`: en curso, días que faltan para `endsOn` (el último día vale 0); próxima, días que faltan
 * para `startsOn`; terminada o sin fecha, null.
 */
final readonly class StageWindow
{
    public function __construct(
        public Stage $stage,
        public ?CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
        public StageSource $source,
        public StageStatus $status,
        public ?int $daysLeft,
    ) {}
}
