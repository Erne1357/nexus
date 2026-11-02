<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Queries\TermCalendarQuery;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Calendarios para ver las pantallas con volumen: Ago-Dic 2026 (`20263`) con un calendario general de 8 etapas ya
 * pasado (de mayo a agosto de 2026) y Ene-Jun 2027 (`20271`) con ventanas propias de Campus I (planeación, plazas y
 * cierre, a fin de 2026), si Campus I está activo. Una ventana solo se crea si el periodo (o el periodo y el
 * campus) no tiene ya una de esa etapa: nunca pisa lo existente. Requiere los periodos (DemoTermSeeder) y los
 * campus. No corre en producción.
 */
class TermCalendarDemoSeeder extends Seeder
{
    use RefusesProduction;

    /**
     * @var array<string, array{0: string, 1: string}> etapa => [inicio, fin]
     */
    private const array GENERAL_20263 = [
        'catalog_validation' => ['2026-05-11', '2026-05-22'],
        'proposal' => ['2026-05-18', '2026-05-29'],
        'scheduling' => ['2026-05-25', '2026-06-12'],
        'staffing' => ['2026-06-01', '2026-06-12'],
        'rooms' => ['2026-06-08', '2026-06-12'],
        'closing' => ['2026-06-08', '2026-06-19'],
        'publication' => ['2026-06-22', '2026-06-26'],
        'reanalysis' => ['2026-08-03', '2026-08-14'],
    ];

    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private const array CAMPUS_20271 = [
        'scheduling' => ['2026-11-23', '2026-12-11'],
        'staffing' => ['2026-11-30', '2026-12-11'],
        'closing' => ['2026-12-07', '2026-12-16'],
    ];

    public function run(): void
    {
        $this->refuseProduction();

        $general = Term::query()->where('code', '20263')->first();

        if ($general !== null) {
            $this->seedWindows($general, null, self::GENERAL_20263);
        }

        $term = Term::query()->where('code', '20271')->first();
        $campus = Campus::query()->where('code', 'C1')->where('is_active', true)->first();

        if ($term !== null && $campus !== null) {
            $this->seedWindows($term, $campus, self::CAMPUS_20271);
        }
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $windows
     */
    private function seedWindows(Term $term, ?Campus $campus, array $windows): void
    {
        foreach ($windows as $stage => [$startsOn, $endsOn]) {
            $exists = TermStage::query()
                ->where('term_id', $term->getKey())
                ->where('campus_id', $campus?->getKey())
                ->where('stage', $stage)
                ->exists();

            if ($exists) {
                continue;
            }

            TermStage::query()->create([
                'term_id' => $term->getKey(),
                'campus_id' => $campus?->getKey(),
                'stage' => Stage::from($stage),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ]);
        }

        Cache::forget(TermCalendarQuery::key($term->getKey()));
    }
}
