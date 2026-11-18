<?php

namespace App\Modules\Solver\Rules;

use App\Modules\Solver\Contract\SolverInput;

/**
 * SV-02: la huella de la entrada de una corrida (tarea 6.8; docs/dominio/reglas-de-negocio.md, «Corridas del motor»).
 * Es el sha256 del JSON canónico de lo que define si lo que propuso el motor sigue vigente; LaunchSolverRun la guarda en
 * `solver_runs.input_hash` y ApplySolverRun (6.10) la compara con la de un armado nuevo antes de escribir.
 *
 * Entra todo lo que describe la carga y lo que la rodea, porque si cambia, lo propuesto puede chocar o sobrar:
 * - los grupos, movibles y fijos, con su campus, materia, cupo (`capacity`), horas, docente, cohortes, sesiones actuales
 *   y sesiones fijas, y los salones candidatos;
 * - los docentes con sus horas restantes, su disponibilidad (no disponible y preferida) y su ocupación fuera de la
 *   corrida (`busy`);
 * - los salones con tipo, capacidad, edificio, dueño, bloqueos y ocupación; los préstamos aceptados;
 * - la rejilla de cada campus, las reglas duras activas, el periodo, la modalidad y el alcance.
 *
 * No entra lo que solo ajusta cómo busca el motor o cómo puntúa, porque no cambia lo que hay en la carga: el
 * identificador de la corrida (`run_id`), el límite de tiempo, las opciones del motor, los pesos de las reglas blandas
 * (los fija Subdirección para todo el Tec) y los minutos de traslado de SC-09 (supuesto por confirmar). Los cupos por
 * carrera (`group_quotas`) no entran al contrato y tampoco a la huella: el motor no los usa.
 *
 * Canónico quiere decir que no depende del orden: las llaves van ordenadas y cada lista se ordena por el JSON de sus
 * elementos, así que dos armados de la misma carga dan la misma huella aunque entreguen grupos, docentes o sesiones en
 * otro orden. Puro: no lee la base.
 */
final class InputFingerprint
{
    /** @var list<string> Lo que no cuenta para la vigencia. */
    public const array IGNORED = ['run_id', 'time_limit_seconds', 'options', 'soft_weights', 'travel_minutes'];

    public static function of(SolverInput $input): string
    {
        return hash('sha256', self::canonical(array_diff_key($input->toArray(), array_flip(self::IGNORED))));
    }

    /**
     * El JSON canónico de un valor: cada elemento se codifica una sola vez, de abajo hacia arriba.
     */
    private static function canonical(mixed $value): string
    {
        if (! is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }

        if (array_is_list($value)) {
            $items = array_map(self::canonical(...), $value);
            sort($items, SORT_STRING);

            return '['.implode(',', $items).']';
        }

        ksort($value, SORT_STRING);
        $pairs = [];

        foreach ($value as $key => $item) {
            $pairs[] = self::canonical((string) $key).':'.self::canonical($item);
        }

        return '{'.implode(',', $pairs).'}';
    }
}
