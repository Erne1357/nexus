<?php

namespace App\Modules\Scheduling\Rules\Reanalysis;

use App\Modules\Scheduling\Models\Group;

/**
 * Lo que dejó aplicar varias sugerencias de un bloque del re-análisis (ApplyReanalysisSuggestions): las que se aplicaron,
 * en el orden en que se aplicaron; las que no, con el motivo que dio su Action; y los grupos nuevos de «Abrir un grupo»,
 * por llave de la sugerencia.
 */
final readonly class SuggestionBatchResult
{
    /**
     * @param  list<ReanalysisSuggestion>  $applied
     * @param  list<array{suggestion: ReanalysisSuggestion, reason: string}>  $failed
     * @param  array<string, Group>  $opened
     */
    public function __construct(
        public array $applied,
        public array $failed,
        public array $opened,
    ) {}
}
