<?php

namespace App\Modules\Faculty\Database\Seeders;

use App\Modules\Academic\Actions\AppointDepartmentHead;
use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\ProvisionTeacherUsers;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Docentes demo y masivos para probar las pantallas del carril de Faculty: por cada campus de `$teachersPerCampus`,
 * ese número de docentes ficticios (TeacherRosterGenerator) con sus adscripciones, plazas, disponibilidad del
 * periodo actual, horas fuera del ITCJ, perfil, historial de 8 periodos y evaluaciones. Hay docentes de cada
 * departamento que da materias en los planes del campus, de los cinco tipos de plaza (varias a la vez, vencidas y
 * futuras), sin plaza, con dos o tres departamentos, inactivos, borrados, sin correo ni grado y con nombres muy
 * largos. La adscripción es solo al departamento: el campus del docente es solo el de su número de empleado y su
 * historial.
 *
 * Al final ProvisionTeacherUsers les da usuario (contraseña por defecto, cambio obligatorio) a los activos, y unos
 * cuantos por departamento se nombran jefe de proyecto docente con AppointDepartmentHead (el departamento puede
 * tener varios; los jefes ya los puso DemoStaffSeeder). Requiere el catálogo (AcademicDemoSeeder) y los periodos.
 * Sin NEXUS_DEFAULT_PASSWORD, la provisión falla.
 *
 * Inserta con `DB::table()` en bloques de 1 000, todo un campus en una transacción. Cada campus usa su propia semilla (`$seed` más una constante del
 * campus): los docentes de Campus II son los mismos solo o junto con Campus I. Volver a sembrar no duplica: un
 * docente cuyo número de empleado ya existe, incluso borrado, no se toca. No corre en producción.
 */
class FacultyDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const int CHUNK = 1000;

    /** Docentes por departamento y campus que se nombran jefe de proyecto docente. */
    private const int HEADS_PER_DEPARTMENT = 2;

    /**
     * @param  array<string, int>  $teachersPerCampus  docentes por clave de campus
     * @param  int  $seed  semilla: la misma da los mismos docentes
     *
     * @throws RuntimeException en producción, si falta un campus o el usuario administrador, o la contraseña por defecto
     */
    public function run(array $teachersPerCampus = ['C2' => 120], int $seed = 2027): void
    {
        $this->refuseProduction();

        $campuses = Campus::query()->orderBy('code')->pluck('id', 'code')->all();

        foreach (array_keys($teachersPerCampus) as $code) {
            if (! isset($campuses[$code])) {
                throw new RuntimeException("Falta el campus {$code}: corre antes CampusSeeder.");
            }
        }

        $admin = User::query()->where('username', 'admin')->value('id')
            ?? throw new RuntimeException('Falta el usuario '.AdminUserSeeder::class.': corre antes AdminUserSeeder.');
        $terms = DB::table('terms')->pluck('id', 'code')->all();
        $started = hrtime(true);
        $totals = [];
        $heads = [];

        foreach ($teachersPerCampus as $code => $count) {
            $catalog = $this->catalog($campuses[$code]);
            $roster = (new TeacherRosterGenerator(new Randomizer(new Mt19937($seed + crc32($code)))))
                ->generate($code, $count, array_map(fn (array $subjects): int => count($subjects), array_column($catalog, 'subjects')));

            $heads[$code] = DB::transaction(fn (): array => $this->insert($roster, $catalog, $campuses[$code], $terms, $admin, $totals));
        }

        $inserted = (hrtime(true) - $started) / 1e9;
        $started = hrtime(true);
        $access = app(ProvisionTeacherUsers::class)->handle();
        $provisioned = (hrtime(true) - $started) / 1e9;

        $started = hrtime(true);
        $appointed = $this->appointHeads($heads, $campuses);
        $headsTime = (hrtime(true) - $started) / 1e9;

        $this->command?->info(sprintf(
            'Docentes: %d (inserción de todo %.1f s). Usuarios: %d creados, %d ligados (%.1f s). Jefes de proyecto docente: %d (%.1f s).',
            $totals['teachers'] ?? 0, $inserted, $access['created'], $access['linked'], $provisioned, $appointed, $headsTime,
        ));
    }

    /**
     * Los departamentos activos que coordinan una carrera del campus o dan una materia de sus planes, por código,
     * cada uno con las materias activas que da en esos planes, por código.
     *
     * @return list<array{id: string, subjects: list<string>}>
     */
    private function catalog(string $campusId): array
    {
        $careers = DB::table('career_campus')->where('campus_id', $campusId)->where('is_active', true)->whereNull('deleted_at')->pluck('career_id');

        $subjects = DB::table('subjects')
            ->join('study_plan_subjects', 'study_plan_subjects.subject_id', '=', 'subjects.id')
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
            ->whereIn('study_plans.career_id', $careers)
            ->whereNull('subjects.deleted_at')
            ->whereNull('study_plan_subjects.deleted_at')
            ->where('subjects.is_active', true)
            ->distinct()
            ->orderBy('subjects.code')
            ->get(['subjects.id', 'subjects.code', 'subjects.department_id'])
            ->groupBy('department_id')
            ->map(fn ($rows): array => $rows->pluck('id')->all());

        $coordinating = DB::table('careers')->whereIn('id', $careers)->pluck('department_id');

        return DB::table('departments')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereIn('id', $subjects->keys())->orWhereIn('id', $coordinating))
            ->orderBy('code')
            ->pluck('id')
            ->map(fn (string $id): array => ['id' => $id, 'subjects' => $subjects->get($id, [])])
            ->all();
    }

    /**
     * Inserta los docentes nuevos del campus con todo lo suyo y devuelve, por departamento, los números de empleado
     * de quienes pueden ser jefe de proyecto docente.
     *
     * @param  list<array<string, mixed>>  $roster
     * @param  list<array{id: string, subjects: list<string>}>  $catalog
     * @param  array<string, string>  $terms
     * @param  array<string, int>  $totals
     * @return array<string, list<string>>
     */
    private function insert(array $roster, array $catalog, string $campusId, array $terms, string $admin, array &$totals): array
    {
        $now = now();
        $existing = DB::table('teachers')->whereIn('employee_number', array_column($roster, 'employee_number'))->pluck('employee_number')->flip();
        $buffers = [];
        $candidates = [];

        $flush = function (string $table) use (&$buffers): void {
            if (($buffers[$table] ?? []) !== []) {
                DB::table($table)->insert($buffers[$table]);
                $buffers[$table] = [];
            }
        };
        $push = function (string $table, array $row) use (&$buffers, $flush): void {
            $buffers[$table][] = $row;

            if (count($buffers[$table]) >= self::CHUNK) {
                $flush('teachers');
                $flush($table);
            }
        };
        $stamps = ['created_at' => $now, 'updated_at' => $now, 'deleted_at' => null];

        foreach ($roster as $teacher) {
            $primary = $catalog[$teacher['adscriptions'][0]['dept']]['id'];

            if ($teacher['is_active'] && ! $teacher['deleted'] && array_filter($teacher['positions'], fn (array $position): bool => $position['kind'] === 'tiempo_completo' || $position['kind'] === 'medio_tiempo')) {
                $candidates[$primary][] = $teacher['employee_number'];
            }

            if (isset($existing[$teacher['employee_number']])) {
                continue;
            }

            $id = Str::uuid7()->toString();
            $totals['teachers'] = ($totals['teachers'] ?? 0) + 1;

            $push('teachers', [
                'id' => $id,
                'employee_number' => $teacher['employee_number'],
                'user_id' => null,
                'name' => $teacher['name'],
                'email' => $teacher['email'],
                'degree' => $teacher['degree'],
                'hired_on' => $teacher['hired_on'],
                'is_active' => $teacher['is_active'],
                ...$stamps,
                'deleted_at' => $teacher['deleted'] ? $now : null,
            ]);

            foreach ($teacher['adscriptions'] as $adscription) {
                $push('teacher_departments', ['teacher_id' => $id, 'department_id' => $catalog[$adscription['dept']]['id'], 'is_primary' => $adscription['primary'], ...$stamps]);
            }

            foreach ($teacher['positions'] as $position) {
                $push('teacher_positions', ['teacher_id' => $id, 'kind' => $position['kind'], 'base_hours' => $position['hours'], 'valid_from' => $position['from'], 'valid_to' => $position['to'], ...$stamps]);
            }

            foreach ($teacher['availability'] as $block) {
                $push('teacher_availability', ['teacher_id' => $id, 'term_id' => $terms[TeacherRosterGenerator::CURRENT_TERM], 'day' => $block['day'], 'starts_at' => $block['starts_at'], 'ends_at' => $block['ends_at'], 'kind' => $block['kind'], ...$stamps]);
            }

            foreach ($teacher['external'] as $load) {
                $push('teacher_external_loads', ['teacher_id' => $id, 'term_id' => $terms[$load['term']], 'source' => $load['source'], 'hours' => $load['hours'], 'notes' => $load['notes'], ...$stamps]);
            }

            $subjects = $catalog[$teacher['adscriptions'][0]['dept']]['subjects'];

            foreach ($teacher['profile'] as $entry) {
                $push('teacher_subject_qualifications', ['teacher_id' => $id, 'subject_id' => $subjects[$entry['subject']], 'level' => $entry['level'], 'qualified_by' => $admin, 'notes' => null, ...$stamps]);
            }

            foreach ($teacher['history'] as $row) {
                $push('teacher_subject_history', ['teacher_id' => $id, 'subject_id' => $subjects[$row['subject']], 'term_code' => $row['term'], 'campus_id' => $campusId, 'group_code' => $row['group'], 'source' => $row['source'], ...$stamps]);
            }

            foreach ($teacher['evaluations'] as $row) {
                $push('teacher_evaluations', ['teacher_id' => $id, 'term_code' => $row['term'], 'kind' => $row['kind'], 'score' => $row['score'], 'source' => 'SII', ...$stamps]);
            }
        }

        foreach (array_keys($buffers) as $table) {
            $flush($table);
        }

        return array_map(fn (array $numbers): array => array_slice($numbers, 0, self::HEADS_PER_DEPARTMENT), $candidates);
    }

    /**
     * Nombra jefe de proyecto docente a los docentes elegidos de cada departamento, salvo que ya lo sean.
     *
     * @param  array<string, array<string, list<string>>>  $heads  por campus y departamento, números de empleado
     * @param  array<string, string>  $campuses
     */
    private function appointHeads(array $heads, array $campuses): int
    {
        $appointed = 0;

        foreach ($heads as $code => $byDepartment) {
            $campus = Campus::query()->findOrFail($campuses[$code]);

            foreach ($byDepartment as $departmentId => $numbers) {
                $department = Department::query()->findOrFail($departmentId);

                foreach (User::query()->whereIn('id', DB::table('teachers')->whereIn('employee_number', $numbers)->whereNotNull('user_id')->select('user_id'))->orderBy('username')->get() as $user) {
                    $already = DB::table('department_heads')->where(['user_id' => $user->id, 'department_id' => $departmentId, 'campus_id' => $campus->id, 'kind' => DepartmentHeadKind::JefeProyectoDocente->value])->exists();

                    if (! $already) {
                        app(AppointDepartmentHead::class)->handle($department, $campus, $user, DepartmentHeadKind::JefeProyectoDocente, today()->toImmutable());
                        $appointed++;
                    }
                }
            }
        }

        return $appointed;
    }
}
