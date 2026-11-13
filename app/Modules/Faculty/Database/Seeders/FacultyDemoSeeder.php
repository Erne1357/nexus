<?php

namespace App\Modules\Faculty\Database\Seeders;

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
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
 * largos. La adscripción es solo al departamento: el campus del docente es solo el de su RFC y su
 * historial.
 *
 * Al final ProvisionTeacherUsers les da usuario a los activos, que nace sin acceso (AC-02); la demo se lo da con la
 * contraseña por defecto y cambio obligatorio, para que se pueda entrar como docente (supuesto por confirmar), y unos
 * cuantos por departamento se nombran jefe de proyecto docente con AppointRole (el departamento puede
 * tener varios; los jefes ya los puso DemoStaffSeeder). Requiere el catálogo (AcademicDemoSeeder) y los periodos.
 * Sin NEXUS_DEFAULT_PASSWORD falla.
 *
 * Con `$forProcess` (modo proceso de la demo por puntos, docs/arquitectura/testing.md) siembra `PROCESS_ROSTER_FACTOR`
 * veces los docentes pedidos, reparte la adscripción principal por las horas que cada departamento da en los planes
 * del campus y deja la disponibilidad más holgada (TeacherRosterGenerator::forProcess), para que en el punto 6 todo
 * grupo tenga un docente que pase HC-01, HC-08 y HC-09. Sin él, todo queda como siempre.
 *
 * Inserta con `DB::table()` en bloques de 1 000, todo un campus en una transacción. Cada campus usa su propia semilla (`$seed` más una constante del
 * campus): los docentes de Campus II son los mismos solo o junto con Campus I. Volver a sembrar no duplica: un
 * docente cuyo RFC ya existe, incluso borrado, no se toca. No corre en producción.
 */
class FacultyDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const int CHUNK = 1000;

    /** Docentes por departamento y campus que se nombran jefe de proyecto docente. */
    private const int HEADS_PER_DEPARTMENT = 2;

    /**
     * En modo proceso, cuántas veces los docentes pedidos: la capacidad de horas de la plantilla de siempre apenas
     * iguala la demanda, y con HC-01 y HC-09 hace falta holgura (medido en el masivo el 9 de noviembre de 2026).
     */
    public const float PROCESS_ROSTER_FACTOR = 1.4;

    /**
     * @param  array<string, int>  $teachersPerCampus  docentes por clave de campus
     * @param  int  $seed  semilla: la misma da los mismos docentes
     * @param  bool  $forProcess  modo proceso de la demo por puntos (ver la docblock de la clase)
     *
     * @throws RuntimeException en producción, si falta un campus o el usuario administrador, o la contraseña por defecto
     */
    public function run(array $teachersPerCampus = ['C2' => 120], int $seed = 2027, bool $forProcess = false): void
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
        $rfcs = [];

        foreach ($teachersPerCampus as $code => $count) {
            $catalog = $this->catalog($campuses[$code]);
            $random = new Randomizer(new Mt19937($seed + crc32($code)));
            $subjectCounts = array_map(fn (array $subjects): int => count($subjects), array_column($catalog, 'subjects'));
            $roster = $forProcess
                ? TeacherRosterGenerator::forProcess($random)->generate($code, (int) round($count * self::PROCESS_ROSTER_FACTOR), $subjectCounts, array_column($catalog, 'hours'))
                : (new TeacherRosterGenerator($random))->generate($code, $count, $subjectCounts);
            $rfcs = [...$rfcs, ...array_column($roster, 'rfc')];

            $heads[$code] = DB::transaction(function () use ($roster, $catalog, $campuses, $code, $terms, $admin, &$totals): array {
                return $this->insert($roster, $catalog, $campuses[$code], $terms, $admin, $totals);
            });
        }

        $inserted = (hrtime(true) - $started) / 1e9;
        $started = hrtime(true);
        $access = app(ProvisionTeacherUsers::class)->handle();
        $this->grantDemoAccess($rfcs);
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
     * Da acceso a los usuarios de los docentes activos de esta plantilla (`$rfcs`, los que generó el seeder) que no lo
     * tienen, con la contraseña por defecto y cambio obligatorio, con un solo hash: en la demo se entra como docente con
     * su número (supuesto por confirmar); en producción lo da el admin o la invitación. Ningún otro usuario se toca: los
     * docentes que ya había en la base o que los seeders posteriores dan de alta con CreateTeacher siguen AC-02 y nacen
     * sin acceso, como en producción.
     *
     * @param  list<string>  $rfcs
     */
    private function grantDemoAccess(array $rfcs): void
    {
        DB::table('users')
            ->whereIn('id', DB::table('teachers')->whereIn('rfc', $rfcs)->whereNotNull('user_id')->where('is_active', true)->whereNull('deleted_at')->select('user_id'))
            ->whereNull('password')
            ->update(['password' => app(DefaultPassword::class)->hash(), 'password_origin' => PasswordOrigin::Default->value, 'must_change_password' => true, 'updated_at' => now()]);
    }

    /**
     * Los departamentos activos que coordinan una carrera del campus o dan una materia de sus planes, por código,
     * cada uno con las materias activas que da en esos planes, por código, y sus horas: las semanales (teoría más
     * práctica) de sus materias en la retícula de cada plan activo de las carreras del campus, sin las que no entran a
     * la propuesta (residencia, servicio social y complementarias), como mínimo 1. Las horas aproximan cuánto pedirán
     * las carreras al departamento.
     *
     * @return list<array{id: string, subjects: list<string>, hours: int}>
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

        $hours = DB::table('study_plan_subjects')
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->whereIn('study_plans.career_id', $careers)
            ->where('study_plans.is_active', true)
            ->whereNull('study_plans.deleted_at')
            ->whereNull('study_plan_subjects.deleted_at')
            ->where('subjects.is_active', true)
            ->whereNull('subjects.deleted_at')
            ->where(fn ($query) => $query->whereNull('subjects.kind')->orWhereNotIn('subjects.kind', [SubjectKind::Residencia->value, SubjectKind::ServicioSocial->value, SubjectKind::Complementaria->value]))
            ->groupBy('subjects.department_id')
            ->selectRaw('subjects.department_id, sum(subjects.theory_hours + subjects.practice_hours) as hours')
            ->pluck('hours', 'department_id');

        $coordinating = DB::table('careers')->whereIn('id', $careers)->pluck('department_id');

        return DB::table('departments')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereIn('id', $subjects->keys())->orWhereIn('id', $coordinating))
            ->orderBy('code')
            ->pluck('id')
            ->map(fn (string $id): array => ['id' => $id, 'subjects' => $subjects->get($id, []), 'hours' => max(1, (int) $hours->get($id, 0))])
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
        $existing = DB::table('teachers')->whereIn('rfc', array_column($roster, 'rfc'))->pluck('rfc')->flip();
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
                $candidates[$primary][] = $teacher['rfc'];
            }

            if (isset($existing[$teacher['rfc']])) {
                continue;
            }

            $id = Str::uuid7()->toString();
            $totals['teachers'] = ($totals['teachers'] ?? 0) + 1;

            $push('teachers', [
                'id' => $id,
                'rfc' => $teacher['rfc'],
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
     * Nombra jefe de proyecto docente a los docentes elegidos de cada departamento, en Presencial y en su campus
     * (AZ-S17), salvo que ya tengan cualquier nombramiento de ese puesto en ese departamento y campus, aunque ya haya
     * terminado: volver a sembrar no repone una jefatura que un admin terminó.
     *
     * @param  array<string, array<string, list<string>>>  $heads  por campus y departamento, números de empleado
     * @param  array<string, string>  $campuses
     */
    private function appointHeads(array $heads, array $campuses): int
    {
        $appointed = 0;
        $presencial = Modality::query()->where('code', 'PRES')->firstOrFail();

        foreach ($heads as $code => $byDepartment) {
            $campus = Campus::query()->findOrFail($campuses[$code]);

            foreach ($byDepartment as $departmentId => $numbers) {
                $department = OrgUnit::query()->findOrFail($departmentId);

                foreach (User::query()->whereIn('id', DB::table('teachers')->whereIn('rfc', $numbers)->whereNotNull('user_id')->select('user_id'))->orderBy('username')->get() as $user) {
                    $already = $user->roleAssignments()
                        ->where(['role' => Role::JefeProyectoDocente, 'org_unit_id' => $departmentId, 'campus_id' => $campus->id])
                        ->exists();

                    if (! $already) {
                        app(AppointRole::class)->handle($user, Role::JefeProyectoDocente, $campus, $department, null, today()->toImmutable(), modality: $presencial);
                        $appointed++;
                    }
                }
            }
        }

        return $appointed;
    }
}
