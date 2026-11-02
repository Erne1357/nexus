<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Queries\TermCalendarQuery;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Periodos del demo: Ene-Jun y Ago-Dic 2026, ya cursados o en curso, y Ene-Jun 2027, el que se planea y es el
 * actual para capturar carga. Las fechas son aproximadas al calendario del ITCJ.
 *
 * Ene-Jun 2027 trae además un calendario general de sus 8 etapas (planeación en nov-dic 2026 y re-análisis en ene
 * 2027), solo si todavía no tiene ventanas.
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

    /**
     * @var array<string, array{0: string, 1: string}> etapa => [inicio, fin], todas de lunes a viernes
     */
    private const array CALENDAR_20271 = [
        'catalog_validation' => ['2026-11-02', '2026-11-06'],
        'proposal' => ['2026-11-09', '2026-11-20'],
        'scheduling' => ['2026-11-23', '2026-12-04'],
        'staffing' => ['2026-11-30', '2026-12-11'],
        'rooms' => ['2026-12-07', '2026-12-11'],
        'closing' => ['2026-12-14', '2026-12-18'],
        'publication' => ['2026-12-21', '2026-12-23'],
        'reanalysis' => ['2027-01-04', '2027-01-15'],
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

        $this->seedCalendar();
    }

    private function seedCalendar(): void
    {
        $term = Term::query()->where('code', '20271')->first();

        if ($term === null || TermStage::query()->where('term_id', $term->getKey())->exists()) {
            return;
        }

        foreach (self::CALENDAR_20271 as $stage => [$startsOn, $endsOn]) {
            TermStage::query()->create([
                'term_id' => $term->getKey(),
                'campus_id' => null,
                'stage' => Stage::from($stage),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ]);
        }

        Cache::forget(TermCalendarQuery::key($term->getKey()));
    }
}
