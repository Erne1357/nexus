<?php

namespace App\Modules\Demand\Database\Seeders;

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Actions\RefreshDemandForecasts;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Queries\ForecastScopeQuery;
use App\Modules\Demand\Queries\ForecastTargetsQuery;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Seeder;

/**
 * Los casos raros de la proyección para la demo (tarea 5.10b, #541), para que las pantallas de Demand muestren todos
 * los métodos desde el punto «propuesta»:
 *
 * 1. una materia nueva que nadie tiene en el kárdex: método `department_fallback` (DM-04);
 * 2. una materia con un solo periodo de historia (`history_terms_used = 1`);
 * 3. una materia de especialidad en un plan cuyos alumnos se reparten entre dos (`needs_review`);
 * 4. una carrera ofrecida por primera vez en un campus sin alumnos: filas con cero elegibles y cero de nuevo ingreso;
 * 5. primer semestre con nuevo ingreso (`first_semester`, DM-05).
 *
 * Medido el 17 de noviembre de 2026 con `demo:seed --hasta=propuesta`, la simulación de alumnos (StudentsDemoSeeder) ya
 * trae los casos 1, 2, 3 y 5 en los dos volúmenes (el 1 son materias de octavo semestre que nadie podía cursar en la
 * ventana de historia, sin elegibles propios): no se inventan datos para ellos y las revisiones de Demand vigilan que
 * sigan ahí. Falta el 4, y es lo único que agrega este seeder: ofrece una carrera activa en un campus activo donde no
 * tiene oferta (ni inactiva ni borrada) ni alumnos, de los campus de la demo primero y luego de los demás; con la demo
 * chica, que solo siembra Campus II, es Campus I, y con la masiva, una carrera de Campus I en Campus II. Si la
 * proyección ya trae el caso no hace nada. Al final recalcula la proyección de cada periodo abierto en cada modalidad
 * (`RefreshDemandForecasts`); es repetible.
 *
 * Lo llama el paso de la demo que lleva a «validación» y, en las demos mezcladas, `DemoSeeder` y `MassiveSeeder` justo
 * después de abrir el periodo, antes de que los jefes validen los catálogos y se armen las propuestas.
 */
final class DemandRareCasesDemoSeeder extends Seeder
{
    public const string CASE_NEW_SUBJECT = 'new_subject';

    public const string CASE_SINGLE_TERM = 'single_term';

    public const string CASE_SPECIALTY_REVIEW = 'specialty_review';

    public const string CASE_FIRST_OFFERING = 'first_offering';

    public const string CASE_FIRST_SEMESTER = 'first_semester';

    /**
     * @param  list<string>  $campuses  claves de los campus de la demo
     */
    public function run(string $term = '20271', array $campuses = ['C2']): void
    {
        $periodId = Term::query()->where('code', $term)->value('id');
        $offering = null;

        if ($periodId === null || self::count(self::CASE_FIRST_OFFERING, $periodId) === 0) {
            $offering = $this->offerCareerInNewCampus($campuses);
        }

        $rows = 0;

        foreach (app(ForecastTargetsQuery::class)->openings() as $opening) {
            $rows += app(RefreshDemandForecasts::class)->handle($opening->term, $opening->modality);
        }

        $this->command?->info(sprintf(
            'Casos raros de la proyección: oferta nueva %s; %d filas recalculadas.',
            $offering ?? 'ya había',
            $rows,
        ));
    }

    /**
     * Cuántas filas de la proyección del periodo traen un caso raro (la demo y sus revisiones lo cuentan igual).
     */
    public static function count(string $case, string $termId): int
    {
        $rows = DemandForecast::query()->where('term_id', $termId);

        return match ($case) {
            self::CASE_NEW_SUBJECT => $rows->where('method', ForecastMethod::DepartmentFallback)->count(),
            self::CASE_SINGLE_TERM => $rows->where('method', ForecastMethod::Formula)->where('history_terms_used', 1)->count(),
            self::CASE_SPECIALTY_REVIEW => $rows->where('needs_review', true)->count(),
            self::CASE_FIRST_SEMESTER => $rows->where('method', ForecastMethod::FirstSemester)->where('new_students', '>', 0)->count(),
            self::CASE_FIRST_OFFERING => $rows->whereNotNull('campus_id')
                ->groupBy('career_id', 'campus_id')
                ->havingRaw('sum(eligible_students) = 0 and coalesce(sum(new_students), 0) = 0')
                ->get(['career_id', 'campus_id'])
                ->count(),
        };
    }

    /**
     * La oferta de una carrera activa, ya ofrecida en otro campus, en un campus activo donde no tiene oferta (ni inactiva
     * ni borrada) ni alumnos. Devuelve «CARRERA en CAMPUS».
     *
     * @param  list<string>  $campuses
     */
    private function offerCareerInNewCampus(array $campuses): ?string
    {
        $careers = app(ForecastScopeQuery::class)->careers();
        $candidates = Campus::query()->where('is_active', true)->orderBy('code')->get()
            ->sortBy(fn (Campus $campus): int => in_array($campus->code, $campuses, true) ? 0 : 1)
            ->values();

        foreach ($candidates as $campus) {
            foreach ($careers as $career) {
                $exists = CareerCampus::withTrashed()->where('career_id', $career->getKey())->where('campus_id', $campus->getKey())->exists();
                $hasStudents = Student::query()->where('career_id', $career->getKey())->where('campus_id', $campus->getKey())->exists();

                if ($exists || $hasStudents) {
                    continue;
                }

                CareerCampus::query()->create(['career_id' => $career->getKey(), 'campus_id' => $campus->getKey(), 'is_active' => true]);

                return "{$career->code} en {$campus->code}";
            }
        }

        return null;
    }
}
