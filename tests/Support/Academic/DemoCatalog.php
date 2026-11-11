<?php

namespace Tests\Support\Academic;

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Atajos de las pruebas del demo académico (tests/Feature/Academic/AcademicDemo*Test.php): sembrar el catálogo de
 * unos campus, contar lo sembrado, los usuarios de prueba y la cadena de seriación más larga de un plan.
 */
final class DemoCatalog
{
    /**
     * Los campus y el catálogo demo de `$campuses`, sin usuarios ni periodos.
     *
     * @param  list<string>  $campuses
     */
    public static function seed(array $campuses): void
    {
        $seeder = (new DatabaseSeeder)->setContainer(app());

        $seeder->call(CampusSeeder::class);
        $seeder->callWith(AcademicDemoSeeder::class, ['campuses' => $campuses]);
    }

    /**
     * Cuántos registros hay de lo que siembra el demo, contando los borrados.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        return [
            ...collect([Department::class, Career::class, CareerCampus::class, StudyPlan::class, Subject::class, Specialty::class, StudyPlanSubject::class, SubjectPrerequisite::class])
                ->mapWithKeys(fn (string $model): array => [$model => $model::withTrashed()->count()])
                ->all(),
            'users' => User::query()->count(),
            'role_assignments' => RoleAssignment::query()->count(),
            'terms' => Term::query()->count(),
        ];
    }

    /**
     * Los usuarios de prueba de DemoStaffSeeder: `subdir`, `jefe.<depto>`, `jpd.<depto>.<campus>` y
     * `coord.<carrera>.<campus>` (los docentes de FacultyDemoSeeder entran con su RFC).
     *
     * @return EloquentCollection<int, User>
     */
    public static function staff(): EloquentCollection
    {
        return User::query()
            ->where(fn ($query) => $query->where('username', 'subdir')
                ->orWhere('username', 'like', 'jefe.%')
                ->orWhere('username', 'like', 'jpd.%')
                ->orWhere('username', 'like', 'coord.%'))
            ->get();
    }

    /**
     * La cadena de seriación más larga (en materias) de un plan, o null si la seriación forma un ciclo.
     *
     * @param  Collection<int, SubjectPrerequisite>  $seriation
     */
    public static function longestSeriationChain(Collection $seriation): ?int
    {
        $requires = $seriation->groupBy('subject_id')->map(fn (Collection $rows): array => $rows->pluck('prerequisite_subject_id')->all())->all();
        $depth = [];
        $visiting = [];

        $walk = function (string $subject) use (&$walk, &$depth, &$visiting, $requires): ?int {
            if (isset($depth[$subject])) {
                return $depth[$subject];
            }

            if (isset($visiting[$subject])) {
                return null;
            }

            $visiting[$subject] = true;
            $longest = 0;

            foreach ($requires[$subject] ?? [] as $prerequisite) {
                $chain = $walk($prerequisite);

                if ($chain === null) {
                    return null;
                }

                $longest = max($longest, $chain);
            }

            unset($visiting[$subject]);

            return $depth[$subject] = $longest + 1;
        };

        $longest = 0;

        foreach (array_keys($requires) as $subject) {
            $chain = $walk($subject);

            if ($chain === null) {
                return null;
            }

            $longest = max($longest, $chain);
        }

        return $longest;
    }
}
