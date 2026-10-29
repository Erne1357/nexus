<?php

namespace App\Modules\Academic\Database\Seeders;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Catálogo académico demo para probar las pantallas y el avance de los alumnos con datos realistas:
 * departamentos, carreras con su oferta por campus, planes con su retícula, especialidades y seriación. Los
 * datos viven en `data/` (claves y nombres al estilo de las retículas públicas del TecNM, aproximados).
 *
 * Siembra las carreras que se ofrecen en `$campuses` (Campus II por omisión), sus planes y las materias de esos
 * planes. Los departamentos y las materias que no están en ningún plan van siempre, igual que los casos extremos
 * de esas carreras (plan sin retícula, materia inactiva en una retícula, registros borrados, un plan de 10
 * semestres). Escribe por clave natural (`upsert`): volver a sembrar deja los datos como en los archivos sin
 * duplicar, y no restaura ni borra lo que se borró después. Requiere los campus (CampusSeeder). No corre en
 * producción.
 */
class AcademicDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const int CHUNK = 500;

    /**
     * Los datos de `data/<nombre>.php`.
     *
     * @return array<string, array<int|string, mixed>>
     */
    public static function data(string $name): array
    {
        return require __DIR__."/data/{$name}.php";
    }

    /**
     * @param  list<string>  $campuses  claves de los campus cuyas carreras se siembran
     *
     * @throws RuntimeException en producción o si falta alguno de los campus
     * @throws InvalidArgumentException si los datos citan una materia que no existe o que no está en la retícula
     */
    public function run(array $campuses = ['C2']): void
    {
        $this->refuseProduction();

        $campusIds = Campus::query()->whereIn('code', $campuses)->pluck('id', 'code')->all();

        if (count($campusIds) !== count(array_unique($campuses))) {
            throw new RuntimeException('Faltan campus de '.implode(', ', $campuses).': corre antes CampusSeeder.');
        }

        $careers = array_filter(self::data('careers'), fn (array $career): bool => array_intersect($career['campuses'], $campuses) !== []);
        $plans = array_filter(self::data('plans'), fn (array $plan): bool => isset($careers[$plan['career']]));
        $subjects = $this->subjectsFor($plans);
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($careers, $plans, $subjects, $campuses, $campusIds, $now): void {
            $departmentIds = $this->seedDepartments($now);
            $careerIds = $this->seedCareers($careers, $departmentIds);
            $this->seedOfferings($careers, $careerIds, $campuses, $campusIds);
            $planIds = $this->seedPlans($plans, $careers, $careerIds);
            $subjectIds = $this->seedSubjects($subjects, $departmentIds, $now);
            $specialtyIds = $this->seedSpecialties($plans, $planIds, $now);
            $this->seedPlacements($plans, $planIds, $subjectIds, $specialtyIds, $now);
            $this->seedPrerequisites($plans, $planIds, $subjectIds, $now);
        });
    }

    /**
     * Las materias de los planes que se siembran y las que no están en ningún plan, con su tipo.
     *
     * @param  array<string, array<string, mixed>>  $plans
     * @return array<string, array{kind: ?string, data: array<int|string, mixed>}>
     */
    private function subjectsFor(array $plans): array
    {
        $catalog = [];

        foreach (self::data('subjects') as $kind => $group) {
            foreach ($group as $code => $subject) {
                $catalog[$code] = ['kind' => $kind === 'sin_capturar' ? null : SubjectKind::from($kind)->value, 'data' => $subject];
            }
        }

        $inAnyPlan = array_merge(...array_map($this->subjectCodesOf(...), array_values(self::data('plans'))));
        $wanted = array_merge(...array_map($this->subjectCodesOf(...), array_values($plans)));

        foreach ($wanted as $code) {
            if (! isset($catalog[$code])) {
                throw new InvalidArgumentException("La materia {$code} de una retícula no está en data/subjects.php.");
            }
        }

        return array_filter($catalog, fn (string $code): bool => in_array($code, $wanted, true) || ! in_array($code, $inAnyPlan, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private function subjectCodesOf(array $plan): array
    {
        return array_keys($this->placementsOf($plan));
    }

    /**
     * Las filas de la retícula de un plan, por clave de materia: semestre, especialidad y si está quitada.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, array{semester: int, specialty: ?string, removed: bool}>
     */
    private function placementsOf(array $plan): array
    {
        $rows = [];
        $add = function (string $code, int $semester, ?string $specialty, bool $removed) use (&$rows): void {
            if (isset($rows[$code])) {
                throw new InvalidArgumentException("La materia {$code} está dos veces en la retícula.");
            }

            $rows[$code] = ['semester' => $semester, 'specialty' => $specialty, 'removed' => $removed];
        };

        foreach ($plan['grid'] as $semester => $codes) {
            foreach ($codes as $code) {
                $add($code, $semester, null, false);
            }
        }

        foreach ($plan['specialties'] ?? [] as $specialty => $data) {
            foreach ($data['grid'] as $semester => $codes) {
                foreach ($codes as $code) {
                    $add($code, $semester, $specialty, false);
                }
            }
        }

        foreach ($plan['removed'] ?? [] as $code => $semester) {
            $add($code, $semester, null, true);
        }

        return $rows;
    }

    /**
     * @return array<string, string> id por clave
     */
    private function seedDepartments(CarbonImmutable $now): array
    {
        $rows = [];

        foreach (self::data('departments') as $code => $department) {
            $rows[] = [
                'code' => $code,
                'name' => $department['name'],
                'is_active' => $department['active'] ?? true,
                'deleted_at' => ($department['deleted'] ?? false) ? $now : null,
            ];
        }

        return $this->upsert(Department::class, $rows, ['code'], ['name', 'is_active']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $careers
     * @param  array<string, string>  $departmentIds
     * @return array<string, string> id por clave
     */
    private function seedCareers(array $careers, array $departmentIds): array
    {
        $rows = [];

        foreach ($careers as $code => $career) {
            $rows[] = [
                'code' => $code,
                'name' => $career['name'],
                'department_id' => $departmentIds[$career['department']],
                'is_active' => $career['active'] ?? true,
            ];
        }

        return $this->upsert(Career::class, $rows, ['code'], ['name', 'department_id', 'is_active']);
    }

    /**
     * La oferta de cada carrera en los campus que se siembran; la de una carrera inactiva, inactiva.
     *
     * @param  array<string, array<string, mixed>>  $careers
     * @param  array<string, string>  $careerIds
     * @param  list<string>  $campuses
     * @param  array<string, string>  $campusIds
     */
    private function seedOfferings(array $careers, array $careerIds, array $campuses, array $campusIds): void
    {
        $rows = [];

        foreach ($careers as $code => $career) {
            foreach (array_intersect($career['campuses'], $campuses) as $campus) {
                $rows[] = ['career_id' => $careerIds[$code], 'campus_id' => $campusIds[$campus], 'is_active' => $career['active'] ?? true];
            }
        }

        $this->upsert(CareerCampus::class, $rows, ['career_id', 'campus_id'], ['is_active'], key: null);
    }

    /**
     * Un plan no cambia de carrera al volver a sembrar: la llave compuesta de `students` cambiaría de carrera a
     * sus alumnos.
     *
     * @param  array<string, array<string, mixed>>  $plans
     * @param  array<string, array<string, mixed>>  $careers
     * @param  array<string, string>  $careerIds
     * @return array<string, string> id por clave
     */
    private function seedPlans(array $plans, array $careers, array $careerIds): array
    {
        $rows = [];

        foreach ($plans as $code => $plan) {
            $semesters = array_column($this->placementsOf($plan), 'semester');

            $rows[] = [
                'code' => $code,
                'career_id' => $careerIds[$plan['career']],
                'name' => "{$careers[$plan['career']]['name']} {$plan['version']}",
                'version' => $plan['version'],
                'semesters_count' => $plan['semesters'] ?? ($semesters === [] ? null : max($semesters)),
                'total_credits' => $plan['total_credits'] ?? null,
                'is_active' => $plan['active'] ?? true,
                'valid_from' => $plan['valid_from'] ?? null,
            ];
        }

        return $this->upsert(StudyPlan::class, $rows, ['code'], ['name', 'version', 'semesters_count', 'total_credits', 'is_active', 'valid_from']);
    }

    /**
     * @param  array<string, array{kind: ?string, data: array<int|string, mixed>}>  $subjects
     * @param  array<string, string>  $departmentIds
     * @return array<string, string> id por clave
     */
    private function seedSubjects(array $subjects, array $departmentIds, CarbonImmutable $now): array
    {
        $rows = [];

        foreach ($subjects as $code => ['kind' => $kind, 'data' => $subject]) {
            [$theory, $practice, $credits] = array_map(intval(...), explode('-', $subject[2]));

            $rows[] = [
                'code' => $code,
                'name' => $subject[0],
                'department_id' => $departmentIds[$subject[1]],
                'theory_hours' => $theory,
                'practice_hours' => $practice,
                'credits' => $credits,
                'kind' => $kind,
                'needs_lab' => $subject['lab'] ?? false,
                'is_active' => $subject['active'] ?? true,
                'deleted_at' => ($subject['deleted'] ?? false) ? $now : null,
            ];
        }

        return $this->upsert(Subject::class, $rows, ['code'], ['name', 'department_id', 'theory_hours', 'practice_hours', 'credits', 'kind', 'needs_lab', 'is_active']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $plans
     * @param  array<string, string>  $planIds
     * @return array<string, string> id por "plan|especialidad"
     */
    private function seedSpecialties(array $plans, array $planIds, CarbonImmutable $now): array
    {
        $rows = [];

        foreach ($plans as $planCode => $plan) {
            foreach ($plan['specialties'] ?? [] as $code => $specialty) {
                $rows[] = [
                    'study_plan_id' => $planIds[$planCode],
                    'code' => $code,
                    'name' => $specialty['name'],
                    'is_active' => $specialty['active'] ?? true,
                    'deleted_at' => ($specialty['deleted'] ?? false) ? $now : null,
                ];
            }
        }

        $this->upsert(Specialty::class, $rows, ['study_plan_id', 'code'], ['name', 'is_active'], key: null);

        $codes = array_flip($planIds);

        return Specialty::withTrashed()->whereIn('study_plan_id', $planIds)->get(['id', 'study_plan_id', 'code'])
            ->mapWithKeys(fn (Specialty $specialty): array => ["{$codes[$specialty->study_plan_id]}|{$specialty->code}" => $specialty->id])
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $plans
     * @param  array<string, string>  $planIds
     * @param  array<string, string>  $subjectIds
     * @param  array<string, string>  $specialtyIds
     */
    private function seedPlacements(array $plans, array $planIds, array $subjectIds, array $specialtyIds, CarbonImmutable $now): void
    {
        $rows = [];

        foreach ($plans as $planCode => $plan) {
            foreach ($this->placementsOf($plan) as $code => $placement) {
                $rows[] = [
                    'study_plan_id' => $planIds[$planCode],
                    'subject_id' => $subjectIds[$code],
                    'semester' => $placement['semester'],
                    'specialty_id' => $placement['specialty'] === null ? null : $specialtyIds["{$planCode}|{$placement['specialty']}"],
                    'is_required' => ! in_array($code, $plan['optional'] ?? [], true),
                    'deleted_at' => $placement['removed'] ? $now : null,
                ];
            }
        }

        $this->upsert(StudyPlanSubject::class, $rows, ['study_plan_id', 'subject_id'], ['semester', 'specialty_id', 'is_required'], key: null);
    }

    /**
     * Cada materia de una cadena pide la anterior; las dos tienen que estar en la retícula del plan, sin quitar.
     *
     * @param  array<string, array<string, mixed>>  $plans
     * @param  array<string, string>  $planIds
     * @param  array<string, string>  $subjectIds
     */
    private function seedPrerequisites(array $plans, array $planIds, array $subjectIds, CarbonImmutable $now): void
    {
        $rows = [];

        foreach ($plans as $planCode => $plan) {
            $placed = array_filter($this->placementsOf($plan), fn (array $placement): bool => ! $placement['removed']);
            $pairs = [];

            foreach ($plan['seriation'] ?? [] as $chain) {
                foreach (array_slice($chain, 1, preserve_keys: true) as $i => $subject) {
                    $pairs[] = [$subject, $chain[$i - 1], PrerequisiteKind::Seriacion, null];
                }
            }

            foreach ($plan['corequisites'] ?? [] as [$subject, $prerequisite]) {
                $pairs[] = [$subject, $prerequisite, PrerequisiteKind::Correquisito, null];
            }

            foreach ($plan['removed_seriation'] ?? [] as [$subject, $prerequisite]) {
                $pairs[] = [$subject, $prerequisite, PrerequisiteKind::Seriacion, $now];
            }

            foreach ($pairs as [$subject, $prerequisite, $kind, $deletedAt]) {
                if (! isset($placed[$subject], $placed[$prerequisite])) {
                    throw new InvalidArgumentException("La seriación {$subject} ← {$prerequisite} de {$planCode} no está en su retícula.");
                }

                $key = "{$planCode}|{$subject}|{$prerequisite}";

                if (isset($rows[$key])) {
                    throw new InvalidArgumentException("La seriación {$subject} ← {$prerequisite} de {$planCode} está dos veces.");
                }

                $rows[$key] = [
                    'study_plan_id' => $planIds[$planCode],
                    'subject_id' => $subjectIds[$subject],
                    'prerequisite_subject_id' => $subjectIds[$prerequisite],
                    'kind' => $kind->value,
                    'deleted_at' => $deletedAt,
                ];
            }
        }

        $this->upsert(SubjectPrerequisite::class, array_values($rows), ['study_plan_id', 'subject_id', 'prerequisite_subject_id'], ['kind'], key: null);
    }

    /**
     * Inserta o actualiza por clave natural, en bloques. `deleted_at` solo se escribe al insertar: volver a
     * sembrar no restaura ni borra.
     *
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $update
     * @return array<string, string> id por `$key` de lo sembrado, si se pide
     */
    private function upsert(string $model, array $rows, array $uniqueBy, array $update, ?string $key = 'code'): array
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $model::query()->upsert($chunk, $uniqueBy, $update);
        }

        if ($key === null) {
            return [];
        }

        return $model::withTrashed()->whereIn($key, array_column($rows, $key))->pluck('id', $key)->all();
    }
}
