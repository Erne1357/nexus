<?php

namespace App\Modules\AcademicLoad\Database\Seeders;

use App\Modules\AcademicLoad\Actions\RequestForecastEvaluation;
use App\Modules\AcademicLoad\Jobs\RunForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Queries\TermKardexQuery;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo de la precisión de la proyección (tarea 5.10a): deja corridas, en el mismo proceso, las evaluaciones de los
 * periodos con kárdex de la modalidad que tienen un periodo anterior del mismo tipo con kárdex (con la demo salen
 * 20261, 20253 y 20263; se descubren con TermKardexQuery, no se fijan), los 3 más recientes y anteriores al periodo de la
 * demo. Para el más reciente corre además una por cada campus de la demo (solo en modalidades con salones, DM-28), para
 * que el filtro y el selector tengan variedad. Deja también una evaluación fallida y una en cola «antigua» (más de
 * `RunForecastEvaluation::STALE_AFTER` segundos sin moverse), del periodo más antiguo, para que la lista muestre todos
 * los estados; la antigua no bloquea: pedir otra de su alcance la da por perdida (DM-29). Las pide Subdirección (`subdir`;
 * sin esa cuenta, nadie).
 */
final class ForecastEvaluationDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const int LATEST = 3;

    /**
     * @param  list<string>  $campuses  códigos de campus de la demo
     */
    public function run(string $modality = 'PRES', array $campuses = ['C2'], string $term = '20271'): void
    {
        $this->refuseProduction();

        $modalityModel = Modality::query()->where('code', $modality)->first();

        if ($modalityModel === null) {
            return;
        }

        $by = User::query()->where('username', 'subdir')->first();
        $request = app(RequestForecastEvaluation::class);
        $terms = $this->evaluableTerms($modalityModel, $term);

        foreach ($terms as $index => $model) {
            $request->handle($model, $modalityModel, null, $by, sync: true);

            if ($index === 0 && $modalityModel->valid_profile?->usesRooms === true) {
                foreach (Campus::query()->whereIn('code', $campuses)->orderBy('code')->get() as $campus) {
                    if (app(TermKardexQuery::class)->exists($model->code, $modalityModel, $campus)) {
                        $request->handle($model, $modalityModel, $campus, $by, sync: true);
                    }
                }
            }
        }

        $oldest = $terms->last();

        if ($oldest === null) {
            return;
        }

        $this->failed($oldest, $modalityModel, $by);
        $this->staleQueued($oldest, $modalityModel, $by);

        $this->command?->info("Evaluaciones de la proyección: {$terms->count()} periodos de {$modalityModel->name}.");
    }

    /**
     * Los periodos con kárdex, anteriores al de la demo y con uno previo del mismo tipo con kárdex, del más reciente al
     * más antiguo, hasta tres.
     *
     * @return Collection<int, Term>
     */
    private function evaluableTerms(Modality $modality, string $demoTerm): Collection
    {
        $codes = collect(app(TermKardexQuery::class)->termCodes($modality))->filter(fn (string $code): bool => $code < $demoTerm);
        $withHistory = $codes->filter(fn (string $code): bool => $codes->contains(fn (string $other): bool => $other < $code && substr($other, -1) === substr($code, -1)))
            ->take(self::LATEST)
            ->values();

        return Term::query()->whereIn('code', $withHistory)->get()
            ->sortByDesc('code')
            ->values();
    }

    /**
     * Una fallida, del periodo más antiguo y con el mensaje de una corrida que se cortó (sin traza), de hace unas horas.
     */
    private function failed(Term $term, Modality $modality, ?User $by): void
    {
        ForecastEvaluation::factory()->failed('No terminó: se perdió la conexión con la base de datos.')->create([
            'term_id' => $term->getKey(),
            'modality_id' => $modality->getKey(),
            'requested_by' => $by?->getKey(),
            'started_at' => now()->subHours(3),
            'finished_at' => now()->subHours(3)->addMinutes(2),
            'progress' => 40,
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);
    }

    private function staleQueued(Term $term, Modality $modality, ?User $by): void
    {
        ForecastEvaluation::factory()->queued()->create([
            'term_id' => $term->getKey(),
            'modality_id' => $modality->getKey(),
            'requested_by' => $by?->getKey(),
            'created_at' => now()->subSeconds(RunForecastEvaluation::STALE_AFTER * 2),
            'updated_at' => now()->subSeconds(RunForecastEvaluation::STALE_AFTER * 2),
        ]);
    }
}
