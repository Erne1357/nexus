<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/**
 * Lo que una plantilla sabe del lote que procesa: quién lo sube o lo aplica y, para las plantillas
 * de un campus (`requiresCampus()`), el campus del lote, que sale del campus activo de la topbar.
 */
final readonly class ImportContext
{
    public function __construct(
        public User $user,
        public ?Campus $campus = null,
    ) {}
}
