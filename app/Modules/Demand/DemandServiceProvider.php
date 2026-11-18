<?php

namespace App\Modules\Demand;

use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\SettingsUpdated;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Demand\Database\Seeders\DemandRareCasesDemoSeeder;
use App\Modules\Demand\Enums\DemandPermission;
use App\Modules\Demand\Listeners\QueueForecastsOnProgressRefreshed;
use App\Modules\Demand\Listeners\QueueForecastsOnTermOpened;
use App\Modules\Demand\Listeners\RecalculateForecastsOnSettingsUpdated;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\DemandSettings;
use App\Modules\Students\Events\StudentProgressRefreshed;
use Illuminate\Support\Facades\Event;

/**
 * Módulo Demand (fase 5, R2): la proyección de cuántos alumnos van a necesitar cada materia el siguiente periodo y
 * cuántos grupos conviene abrir (docs/plan/fase-5-demanda.md). Depende de Students y de lo que está a su izquierda;
 * AcademicLoad lo lee para precargar la propuesta y armar el tablero del departamento. Demand calcula; lo que compara
 * con las propuestas o con lo publicado vive en AcademicLoad.
 *
 * Escucha a Core (TermOpened) y a Students (StudentProgressRefreshed) para encolar la proyección (tarea 5.4).
 */
final class DemandServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(TermOpened::class, QueueForecastsOnTermOpened::class);
        Event::listen(StudentProgressRefreshed::class, QueueForecastsOnProgressRefreshed::class);
        Event::listen(SettingsUpdated::class, RecalculateForecastsOnSettingsUpdated::class);
    }

    public function permissions(): array
    {
        return [DemandPermission::class];
    }

    /**
     * Subdirección cambia los parámetros de la proyección (confirmado el 16 de noviembre de 2026); el admin los cambia
     * por `core.setting.update`.
     */
    public function grants(): array
    {
        return [
            Role::Subdireccion->value => [DemandPermission::SettingUpdate],
        ];
    }

    /**
     * Los parámetros de la proyección, que también leen la propuesta de carrera y la bandeja (CF-S7).
     *
     * @return list<SettingDefinition>
     */
    public function settings(): array
    {
        return DemandSettings::definitions();
    }

    /**
     * Demo por puntos: los casos raros de la proyección (tarea 5.10b) llevan a «validación», después de que Core abre el
     * periodo y la proyección nace (el orden de los providers), y antes de que los jefes validen los catálogos.
     */
    public function demoSteps(): array
    {
        return [
            new DemoStep(DemoCheckpoint::CatalogValidation, 'Casos raros de la proyección de demanda', fn (DemoContext $demo) => $demo->runSeeder(DemandRareCasesDemoSeeder::class, [
                'term' => $demo->termCode,
                'campuses' => $demo->campuses,
            ])),
        ];
    }

    /**
     * Desde «propuesta», cada modalidad abierta en el periodo de la demo tiene su proyección: la calcula el job que
     * encola la apertura del periodo (con la cola síncrona de `demo:seed`), antes de que nazcan las propuestas.
     */
    public function demoChecks(): array
    {
        return [
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Proposal), 'Cada modalidad abierta tiene su proyección de demanda', function (DemoContext $demo): true|string {
                $missing = TermOpening::query()
                    ->where('term_id', $demo->term()->getKey())
                    ->whereIn('modality_id', Modality::query()->whereIn('code', $demo->volume->modalities())->select('id'))
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from((new DemandForecast)->getTable())
                        ->whereColumn('demand_forecasts.term_id', 'term_openings.term_id')
                        ->whereColumn('demand_forecasts.modality_id', 'term_openings.modality_id'))
                    ->count();

                return $missing === 0 ? true : "{$missing} modalidades abiertas sin proyección";
            }),
            ...$this->rareCaseChecks(),
        ];
    }

    /**
     * Una revisión por caso raro de la proyección (tarea 5.10b): al menos una fila del periodo de la demo.
     *
     * @return list<DemoCheck>
     */
    private function rareCaseChecks(): array
    {
        $cases = [
            DemandRareCasesDemoSeeder::CASE_NEW_SUBJECT => 'La proyección trae una materia nueva sin historia (promedio del departamento)',
            DemandRareCasesDemoSeeder::CASE_SINGLE_TERM => 'La proyección trae una materia con un solo periodo de historia',
            DemandRareCasesDemoSeeder::CASE_SPECIALTY_REVIEW => 'La proyección trae una materia de especialidad por revisar',
            DemandRareCasesDemoSeeder::CASE_FIRST_OFFERING => 'La proyección trae una carrera ofrecida por primera vez en un campus sin alumnos',
            DemandRareCasesDemoSeeder::CASE_FIRST_SEMESTER => 'La proyección trae primer semestre con nuevo ingreso',
        ];

        return array_map(
            fn (string $case, string $label): DemoCheck => new DemoCheck(
                DemoCheckpoint::since(DemoCheckpoint::Proposal),
                $label,
                fn (DemoContext $demo): true|string => DemandRareCasesDemoSeeder::count($case, $demo->term()->getKey()) > 0
                    ? true
                    : 'Ninguna fila de la proyección de '.$demo->termCode.' trae el caso.',
            ),
            array_keys($cases),
            array_values($cases),
        );
    }
}
