<?php

namespace App\Modules\Staffing\Database\Seeders;

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Rules\SuggestedGroups;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Actions\AddPosition;
use App\Modules\Faculty\Actions\CreateTeacher;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Queries\TeacherBaseHoursQuery;
use App\Modules\Students\Queries\EligibleStudentsQuery;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Plantilla suficiente para la asignación docente (demo por puntos, lleva a «validación», docs/arquitectura/testing.md):
 * en cada departamento cuya capacidad de horas no llega a `COVERAGE` veces la demanda esperada de Ene-Jun 2027, da de
 * alta docentes de asignatura de `HIRE_HOURS` horas con adscripción principal ahí, con CreateTeacher y AddPosition,
 * como las altas que el jefe registra durante la validación. Corre antes de validar 20271, así que entran al catálogo
 * validado y en «salones» todo grupo tiene un docente que pasa HC-01, HC-08 y HC-09.
 *
 * - **Demanda esperada:** la que pedirán las propuestas de carrera. Por cada carrera que se ofrece en un campus de
 *   `$campuses`, cada materia de la retícula de sus planes activos que abre grupos (como StartCareerProposal: sin
 *   residencia, servicio social ni complementarias, y con horas de clase) pide los grupos sugeridos para sus alumnos
 *   elegibles (EligibleStudentsQuery y SuggestedGroups) por sus horas semanales, para el departamento de la materia.
 * - **Capacidad:** de cada docente activo con adscripción principal al departamento, su tope de horas (sus horas base, o
 *   `HONORARIOS_HOURS` si es de honorarios, como el parámetro que pone el punto 5) menos sus horas fuera del ITCJ.
 *
 * Determinista: los nombres salen de Faker con `$seed` y los RFC del consecutivo. Idempotente: con la capacidad ya
 * cubierta no da de alta a nadie. No corre en producción.
 */
final class StaffingSupplyDemoSeeder extends Seeder
{
    use RefusesProduction;

    /**
     * Cuántas veces la demanda esperada debe cubrir la capacidad: con HC-01 y HC-09, las horas libres de un docente no
     * siempre caen donde hacen falta (medido en el masivo el 9 de noviembre de 2026).
     */
    public const float COVERAGE = 1.35;

    /** Horas de la plaza de asignatura de cada alta. */
    public const int HIRE_HOURS = 20;

    /** El tope de un docente de honorarios: `staffing.honorarios_max_hours`, que pone el punto 5. */
    private const int HONORARIOS_HOURS = 20;

    private const array OUTSIDE_PROPOSAL = [SubjectKind::Residencia->value, SubjectKind::ServicioSocial->value, SubjectKind::Complementaria->value];

    /**
     * @param  list<string>  $campuses  claves de campus
     * @param  string  $modality  la clave de la modalidad de la carga de la demo (Presencial)
     *
     * @throws RuntimeException en producción o si el periodo no existe
     */
    public function run(array $campuses = ['C2'], string $term = '20271', int $seed = 2027, string $modality = 'PRES'): void
    {
        $this->refuseProduction();
        $started = hrtime(true);

        $termModel = Term::query()->where('code', $term)->first() ?? throw new RuntimeException("El periodo {$term} no existe.");
        $demand = $this->demand(Campus::query()->whereIn('code', $campuses)->orderBy('code')->get(), Modality::query()->where('code', $modality)->firstOrFail());
        $capacity = $this->capacity($termModel);
        $faker = fake('es_MX');
        $faker->seed($seed);
        $hired = [];
        $next = 0;

        foreach (Department::query()->whereIn('id', array_keys($demand))->orderBy('code')->get() as $department) {
            $missing = self::COVERAGE * $demand[$department->id] - ($capacity[$department->id] ?? 0);

            for ($n = 1; $n <= (int) ceil(max(0, $missing) / self::HIRE_HOURS); $n++) {
                do {
                    $rfc = $this->rfc($next++);
                } while (Teacher::withTrashed()->where('rfc', $rfc)->exists());

                $teacher = app(CreateTeacher::class)->handle([
                    'rfc' => $rfc,
                    'name' => $faker->firstName().' '.$faker->lastName().' '.$faker->lastName(),
                    'email' => strtolower($rfc).'@example.test',
                    'hired_on' => $termModel->starts_on->copy()->subMonth()->startOfMonth()->toDateString(),
                ], $department);
                app(AddPosition::class)->handle($teacher, [
                    'kind' => PositionKind::Asignatura->value,
                    'base_hours' => self::HIRE_HOURS,
                    'valid_from' => $termModel->starts_on->copy()->subMonth()->startOfMonth()->toDateString(),
                ]);
                $hired[$department->code] = ($hired[$department->code] ?? 0) + 1;
            }
        }

        $this->command?->info(sprintf('Plantilla para la asignación: %s, %.1f s.',
            $hired === [] ? 'no faltaban docentes' : 'altas de asignatura en '.implode(', ', array_map(fn (string $code, int $total): string => "{$code} {$total}", array_keys($hired), $hired)),
            (hrtime(true) - $started) / 1e9));
    }

    /**
     * Las horas semanales que pedirán las propuestas de carrera de los campus, por departamento.
     *
     * @param  iterable<Campus>  $campuses
     * @return array<string, int>
     */
    private function demand(iterable $campuses, Modality $modality): array
    {
        $suggested = SuggestedGroups::fromSettings();
        $demand = [];

        foreach ($campuses as $campus) {
            $offered = DB::table('career_campus')->where('campus_id', $campus->id)->where('is_active', true)->whereNull('deleted_at')->pluck('career_id');

            foreach (Career::query()->where('is_active', true)->whereIn('id', $offered)->orderBy('code')->get() as $career) {
                $eligible = app(EligibleStudentsQuery::class)->countsFor($career, $modality, $campus);

                foreach ($this->placements($career) as $subject) {
                    $groups = $suggested->for($eligible[$subject->id] ?? 0, (bool) $subject->needs_lab);
                    $demand[$subject->department_id] = ($demand[$subject->department_id] ?? 0) + $groups * ((int) $subject->theory_hours + (int) $subject->practice_hours);
                }
            }
        }

        return $demand;
    }

    /**
     * Las materias de la retícula de los planes activos de la carrera que abren grupos, una vez cada una.
     *
     * @return iterable<object{id: string, department_id: string, needs_lab: bool, theory_hours: int, practice_hours: int}>
     */
    private function placements(Career $career): iterable
    {
        return DB::table('study_plan_subjects')
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->where('study_plans.career_id', $career->id)
            ->where('study_plans.is_active', true)
            ->whereNull('study_plans.deleted_at')
            ->whereNull('study_plan_subjects.deleted_at')
            ->where('subjects.is_active', true)
            ->whereNull('subjects.deleted_at')
            ->where(fn ($query) => $query->whereNull('subjects.kind')->orWhereNotIn('subjects.kind', self::OUTSIDE_PROPOSAL))
            ->whereRaw('subjects.theory_hours + subjects.practice_hours > 0')
            ->orderBy('subjects.code')
            ->get(['subjects.id', 'subjects.department_id', 'subjects.needs_lab', 'subjects.theory_hours', 'subjects.practice_hours'])
            ->unique('id');
    }

    /**
     * Las horas que les quedan a los docentes activos de cada departamento (por su adscripción principal), sin contar
     * grupos.
     *
     * @return array<string, int>
     */
    private function capacity(Term $term): array
    {
        $primary = DB::table('teacher_departments')
            ->join('teachers', 'teachers.id', '=', 'teacher_departments.teacher_id')
            ->where('teacher_departments.is_primary', true)->whereNull('teacher_departments.deleted_at')
            ->where('teachers.is_active', true)->whereNull('teachers.deleted_at')
            ->pluck('teacher_departments.department_id', 'teacher_departments.teacher_id');
        $base = app(TeacherBaseHoursQuery::class)->forTerm($term, $primary->keys()->all());
        $external = DB::table('teacher_external_loads')->where('term_id', $term->id)->whereNull('deleted_at')
            ->groupBy('teacher_id')->selectRaw('teacher_id, sum(hours) as hours')->pluck('hours', 'teacher_id');
        $honorarios = TeacherPosition::query()->currentOn($term->starts_on)->where('kind', PositionKind::Honorarios)
            ->distinct()->pluck('teacher_id')->flip();
        $capacity = [];

        foreach ($primary as $teacherId => $departmentId) {
            $limit = isset($honorarios[$teacherId]) ? self::HONORARIOS_HOURS : (int) ($base[$teacherId] ?? 0);
            $capacity[$departmentId] = ($capacity[$departmentId] ?? 0) + max(0, $limit - (int) ($external[$teacherId] ?? 0));
        }

        return $capacity;
    }

    /**
     * Un RFC sintético con la forma de uno de persona física, del consecutivo: empieza con Z, así que no choca con los
     * de FacultyDemoSeeder ni con los de una persona real.
     */
    private function rfc(int $n): string
    {
        $letters = 'Z'.chr(65 + intdiv($n, 676) % 26).chr(65 + intdiv($n, 26) % 26).chr(65 + $n % 26);

        return $letters.sprintf('%02d%02d%02d', 70 + $n % 30, 1 + $n % 12, 1 + $n % 28).strtoupper(substr(dechex(crc32("supply{$n}")), 0, 3));
    }
}
