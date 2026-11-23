<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Rules\TermRules;
use Illuminate\Database\Seeder;

/**
 * Historial de periodos del SII para ver listas largas y filtros: crea todos los códigos desde `{$fromYear}1`
 * hasta `20273` que todavía no existen (1 Ene-Jun, 2 Verano, 3 Ago-Dic, con fechas aproximadas al calendario del
 * ITCJ), sin ser el actual. `20272` queda sin calendario a propósito (el caso vacío) y `20252` lleva un nombre
 * larguísimo para probar el desbordamiento.
 *
 * Solo crea los que faltan: no toca nombre, fechas ni `is_current` de uno existente. No corre en producción.
 */
class TermHistorySeeder extends Seeder
{
    use RefusesProduction;

    public const string LONG_NAME_CODE = '20252';

    public const string LONG_NAME = 'Verano intensivo 2025: cursos de nivelación, recuperación y especiales para alumnos de reingreso';

    public function run(int $fromYear = 2014): void
    {
        $this->refuseProduction();

        $rules = new TermRules;

        for ($year = $fromYear; $year <= 2027; $year++) {
            foreach (['1', '2', '3'] as $period) {
                $code = $year.$period;

                if ($code > '20273') {
                    break 2;
                }

                [$startsOn, $endsOn] = $rules->defaultDates($code);

                Term::query()->firstOrCreate(['code' => $code], [
                    'name' => $code === self::LONG_NAME_CODE ? self::LONG_NAME : $rules->suggestedName($code),
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'is_current' => false,
                ]);
            }
        }
    }
}
