<?php

namespace App\Modules\Solver\Engines\CpSat;

use RuntimeException;

/**
 * El servicio del motor rechazó la entrada (422, tarea 7.9): no cumple el contrato tal como lo valida pydantic, por
 * ejemplo una rejilla cuyo bloque no divide la jornada (`campuses[0].day_end`). El mensaje lista los primeros problemas
 * con la ruta de cada campo, en la misma forma que ContractException, para que la corrida falle con algo que se lea.
 */
final class SolverInputRejected extends RuntimeException
{
    /** Cuántos problemas entran al mensaje; los demás se cuentan. */
    private const int SHOWN = 3;

    /**
     * @param  list<array{path: string, message: string}>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(self::describe($problems));
    }

    /**
     * El `detail` del 422: la lista `[{path, message}]` del servicio o, si no trae esa forma, un texto suelto.
     */
    public static function fromDetail(mixed $detail): self
    {
        $problems = [];

        if (is_string($detail) && trim($detail) !== '') {
            $problems[] = ['path' => '', 'message' => trim($detail)];
        }

        if (is_array($detail)) {
            foreach ($detail as $problem) {
                if (is_array($problem) && is_string($problem['message'] ?? null)) {
                    $problems[] = ['path' => is_string($problem['path'] ?? null) ? $problem['path'] : '', 'message' => $problem['message']];
                }
            }
        }

        return new self($problems);
    }

    /**
     * @param  list<array{path: string, message: string}>  $problems
     */
    private static function describe(array $problems): string
    {
        if ($problems === []) {
            return 'El servicio del motor rechazó la entrada sin decir por qué.';
        }

        $shown = array_map(
            fn (array $problem): string => $problem['path'] === '' ? $problem['message'] : "{$problem['path']}: {$problem['message']}",
            array_slice($problems, 0, self::SHOWN),
        );
        $more = count($problems) - count($shown);

        return 'El servicio del motor rechazó la entrada: '.rtrim(implode('; ', $shown), '.').($more > 0 ? " (y {$more} más)" : '').'.';
    }
}
