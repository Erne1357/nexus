<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Validation\ValidationResult;

/**
 * Las marcas que el validador de Scheduling (checkTeachers) le pone a un candidato del ranking docente, por id de regla
 * (reglas-de-negocio.md, «Ranking docente (desempate)»): choque (HC-01) y no disponible (HC-09) lo bajan al bloque
 * final («no pueden tomarlo»); con el grupo pasar su banda de horas frente a grupo (HC-08, aviso desde la tarea 1.50:
 * se puede asignar, con el oficio de conformidad pendiente) lo baja a su propio bloque, «Pasan su banda», debajo de los
 * demás y antes del final (decisión del coordinador del riel, 26 de noviembre de 2026); la clave `sin_horas` se conserva
 * por los desgloses guardados, y `banda_aceptada` dice que su casilla (SEL-12) ya cubre esas horas. El aviso de traslado
 * justo entre campus (SC-09, blando) solo lo marca, sin moverlo de lugar. Una regla que no está aquí no marca. Puro.
 */
final class CandidateMarks
{
    /** Las reglas que bajan al docente al bloque final («Un solo bloque final»), con su marca. */
    public const array FINAL_TIER = ['HC-01' => 'choque', 'HC-09' => 'no_disponible'];

    /** Las reglas que bajan al docente al bloque «Pasan su banda» (asignable), con su marca. */
    public const array BAND_TIER = ['HC-08' => 'sin_horas'];

    /** La marca de que la casilla del docente ya cubre las horas con las que pasa su banda (SEL-12). */
    public const string BAND_ACCEPTED = 'banda_aceptada';

    /** Los avisos que solo marcan al docente, con su marca; cuentan si el validador los da como aviso (blandos). */
    public const array NOTICES = ['SC-09' => 'traslado_justo'];

    /**
     * Las marcas del candidato en el orden de FINAL_TIER, BAND_TIER (con BAND_ACCEPTED detrás si todos sus avisos de
     * HC-08 están aceptados) y NOTICES, sin repetir.
     *
     * @return list<string>
     */
    public static function of(ValidationResult $result): array
    {
        $found = [];
        $accepted = null;

        foreach ($result->violations() as $violation) {
            if (isset(self::FINAL_TIER[$violation->rule])) {
                $found[self::FINAL_TIER[$violation->rule]] = true;
            } elseif (isset(self::BAND_TIER[$violation->rule])) {
                $found[self::BAND_TIER[$violation->rule]] = true;
                $accepted = ($accepted ?? true) && ($violation->related['accepted'] ?? false) === true;
            } elseif (isset(self::NOTICES[$violation->rule]) && $violation->severity === ConflictSeverity::Soft) {
                $found[self::NOTICES[$violation->rule]] = true;
            }
        }

        if ($accepted === true) {
            $found[self::BAND_ACCEPTED] = true;
        }

        return array_values(array_filter(
            [...array_values(self::FINAL_TIER), ...array_values(self::BAND_TIER), self::BAND_ACCEPTED, ...array_values(self::NOTICES)],
            fn (string $mark): bool => isset($found[$mark]),
        ));
    }
}
