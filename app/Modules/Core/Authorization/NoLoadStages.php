<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Models\Term;

/**
 * Sin el módulo de la carga no hay cargas: nadie trabaja una ni tiene etapa, así que el jefe y el JPD siguen el interruptor
 * como los demás (MN-05).
 */
final class NoLoadStages implements LoadStages
{
    public function worksLoad(Actor $context): bool
    {
        return false;
    }

    public function of(Actor $context, ?Term $term = null): array
    {
        return [];
    }
}
