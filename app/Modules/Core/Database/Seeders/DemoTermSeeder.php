<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Term;
use Illuminate\Database\Seeder;

/**
 * Periodos del demo: Ene-Jun y Ago-Dic 2026, ya cursados o en curso, y Ene-Jun 2027, el que se planea y es el
 * actual para capturar carga. Las fechas son aproximadas al calendario del ITCJ.
 *
 * Solo crea los que faltan, y Ene-Jun 2027 queda como actual solo si no hay otro actual: volver a sembrar no
 * pisa lo que se cambió a mano. No corre en producción.
 */
class DemoTermSeeder extends Seeder
{
    use RefusesProduction;

    /**
     * @var list<array{code: string, name: string, starts_on: string, ends_on: string, is_current: bool}>
     */
    private const array TERMS = [
        ['code' => '20261', 'name' => 'Ene-Jun 2026', 'starts_on' => '2026-01-26', 'ends_on' => '2026-06-12', 'is_current' => false],
        ['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11', 'is_current' => false],
        ['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11', 'is_current' => true],
    ];

    public function run(): void
    {
        $this->refuseProduction();

        $hasCurrent = Term::query()->current()->exists();

        foreach (self::TERMS as $term) {
            Term::query()->firstOrCreate(['code' => $term['code']], [
                'name' => $term['name'],
                'starts_on' => $term['starts_on'],
                'ends_on' => $term['ends_on'],
                'is_current' => $term['is_current'] && ! $hasCurrent,
            ]);
        }
    }
}
