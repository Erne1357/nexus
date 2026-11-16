<?php

namespace App\Modules\Demand\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use Illuminate\Support\Collection;

/**
 * Qué proyecciones se vuelven a calcular cuando cambia algo que las afecta (kárdex nuevo, un parámetro de demanda): los
 * periodos abiertos en una modalidad activa (`term_openings`, M4) que no son anteriores al periodo actual. Abrir uno no
 * cierra el anterior (confirmado el 1 de noviembre de 2026), pero solo se planea del actual en adelante; sin periodo
 * actual, todos los abiertos.
 */
final class ForecastTargetsQuery
{
    /**
     * @return Collection<int, TermOpening> con `term` y `modality` cargados, por periodo y modalidad
     */
    public function openings(): Collection
    {
        $current = Term::query()->where('is_current', true)->value('code');

        return TermOpening::query()
            ->with(['term', 'modality'])
            ->whereHas('modality', fn ($query) => $query->where('is_active', true))
            ->when($current !== null, fn ($query) => $query->whereHas('term', fn ($term) => $term->where('code', '>=', $current)))
            ->get()
            ->sortBy(fn (TermOpening $opening): string => $opening->term->code.$opening->modality->code)
            ->values();
    }
}
