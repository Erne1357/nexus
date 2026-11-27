<?php

use App\Modules\Scheduling\Validation\ValidationResult;
use App\Modules\Scheduling\Validation\Violation;
use App\Modules\Staffing\Rules\CandidateMarks;

/*
| Las marcas que el validador de Scheduling le pone a un candidato del ranking, por id de regla (reglas-de-negocio.md,
| «Ranking docente (desempate)»): HC-01 y HC-09 lo bajan al bloque final; los avisos HC-08 (desde la tarea 1.50) y SC-09
| solo lo marcan.
*/

function candidateViolation(string $rule, bool $hard = true): Violation
{
    return $hard
        ? Violation::hard($rule, "Rompe {$rule}.", 'teacher', 't', 'g')
        : Violation::soft($rule, "Avisa {$rule}.", 'teacher', 't', 'g');
}

it('HC-01 y HC-09 dan choque y no disponible; el aviso HC-08, «sin_horas» (pasa su banda) sin marca del bloque final', function () {
    $result = new ValidationResult([candidateViolation('HC-09'), candidateViolation('HC-01'), candidateViolation('HC-08', hard: false)]);
    $marks = CandidateMarks::of($result);

    expect($marks)->toBe(['choque', 'no_disponible', 'sin_horas'])
        ->and(array_values(CandidateMarks::FINAL_TIER))->not->toContain('sin_horas');
});

it('SC-09: el aviso de traslado justo entre campus da «traslado justo» sin marca del bloque final', function () {
    $marks = CandidateMarks::of(new ValidationResult([candidateViolation('SC-09', hard: false)]));

    expect($marks)->toBe(['traslado_justo'])
        ->and(array_intersect($marks, array_values(CandidateMarks::FINAL_TIER)))->toBe([]);
});

it('SC-09 solo cuenta como aviso y una regla que no conoce no marca', function () {
    expect(CandidateMarks::of(new ValidationResult([candidateViolation('SC-09'), candidateViolation('HC-05')])))->toBe([])
        ->and(CandidateMarks::of(new ValidationResult))->toBe([]);
});
