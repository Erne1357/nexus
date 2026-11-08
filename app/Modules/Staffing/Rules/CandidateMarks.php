<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Validation\ValidationResult;

/**
 * Las marcas que el validador de Scheduling (checkTeachers) le pone a un candidato del ranking docente, por id de regla
 * (reglas-de-negocio.md, «Ranking docente (desempate)»): choque (HC-01), sin horas (HC-08) y no disponible (HC-09) lo
 * bajan al bloque final; el aviso de traslado justo entre campus (SC-09, blando) solo lo marca, sin moverlo de lugar.
 * Una regla que no está aquí no marca. Puro.
 */
final class CandidateMarks
{
    /** Las reglas que bajan al docente al bloque final («Un solo bloque final»), con su marca. */
    public const array FINAL_TIER = ['HC-01' => 'choque', 'HC-08' => 'sin_horas', 'HC-09' => 'no_disponible'];

    /** Los avisos que solo marcan al docente, con su marca; cuentan si el validador los da como aviso (blandos). */
    public const array NOTICES = ['SC-09' => 'traslado_justo'];

    /**
     * Las marcas del candidato en el orden de FINAL_TIER y NOTICES, sin repetir.
     *
     * @return list<string>
     */
    public static function of(ValidationResult $result): array
    {
        $found = [];

        foreach ($result->violations() as $violation) {
            if (isset(self::FINAL_TIER[$violation->rule])) {
                $found[self::FINAL_TIER[$violation->rule]] = true;
            } elseif (isset(self::NOTICES[$violation->rule]) && $violation->severity === ConflictSeverity::Soft) {
                $found[self::NOTICES[$violation->rule]] = true;
            }
        }

        return array_values(array_filter(
            [...array_values(self::FINAL_TIER), ...array_values(self::NOTICES)],
            fn (string $mark): bool => isset($found[$mark]),
        ));
    }
}
