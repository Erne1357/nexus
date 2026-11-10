<?php

use App\Modules\AcademicLoad\Enums\ExportState;

/*
| ExportState: en qué va un exporte de lo publicado, con el texto que ve quien lo pidió.
*/

it('nombra cada estado del exporte', function () {
    expect(array_map(fn (ExportState $state): string => $state->label(), ExportState::cases()))
        ->toBe(['Sin generar', 'Preparando', 'Listo', 'No se pudo generar']);
});
