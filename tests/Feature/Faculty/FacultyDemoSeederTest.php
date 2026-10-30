<?php

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Database\Seeders\RoleSeeder;
use App\Modules\Faculty\Database\Seeders\FacultyDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
| Docentes demo y masivos (tarea 7b): se siembran sobre el catálogo demo con sus adscripciones, plazas,
| disponibilidad, horas fuera del ITCJ, historial, perfil, evaluaciones y usuarios. Los casos están en
| docs/arquitectura/testing.md ("Datos de prueba").
*/

/**
 * Siembra el catálogo y `$perCampus` docentes por campus.
 *
 * @param  array<string, int>  $perCampus
 */
function seedDemoTeachers(array $perCampus = ['C2' => 40], int $seed = 2027): void
{
    $seeder = (new DatabaseSeeder)->setContainer(app());

    $seeder->call([RoleSeeder::class, CampusSeeder::class, AdminUserSeeder::class, DemoTermSeeder::class]);
    $seeder->callWith(AcademicDemoSeeder::class, ['campuses' => array_keys($perCampus)]);
    $seeder->callWith(FacultyDemoSeeder::class, ['teachersPerCampus' => $perCampus, 'seed' => $seed]);
}

it('siembra los docentes pedidos de cada campus, con la adscripción principal en su campus', function () {
    seedDemoTeachers(['C1' => 22, 'C2' => 25]);

    $campusOf = DB::table('teacher_departments')
        ->join('campuses', 'campuses.id', '=', 'teacher_departments.campus_id')
        ->where('is_primary', true)
        ->join('teachers', 'teachers.id', '=', 'teacher_departments.teacher_id')
        ->selectRaw('campuses.code, count(*) as total')
        ->groupBy('campuses.code')
        ->pluck('total', 'code')
        ->all();

    expect(DB::table('teachers')->count())->toBe(47)
        ->and($campusOf)->toEqual(['C1' => 22, 'C2' => 25]);
});

it('hay docentes de los cinco tipos de plaza, con varias plazas, vencidas, futuras y sin plaza', function () {
    seedDemoTeachers();

    $kinds = DB::table('teacher_positions')->distinct()->pluck('kind')->sort()->values()->all();
    $perTeacher = DB::table('teacher_positions')->selectRaw('teacher_id, count(*) as total')->groupBy('teacher_id')->pluck('total');

    expect($kinds)->toBe(['asignatura', 'honorarios', 'medio_tiempo', 'tiempo_completo', 'tres_cuartos'])
        ->and($perTeacher->max())->toBeGreaterThanOrEqual(3)
        ->and(DB::table('teacher_positions')->whereNotNull('valid_to')->exists())->toBeTrue()
        ->and(DB::table('teacher_positions')->where('valid_from', '>', '2027-01-01')->exists())->toBeTrue()
        ->and(DB::table('teachers')->whereNotIn('id', DB::table('teacher_positions')->select('teacher_id'))->exists())->toBeTrue();
});

it('cada docente tiene una sola adscripción principal; hay con varias y con una en el otro campus', function () {
    seedDemoTeachers();

    $primaries = DB::table('teacher_departments')->whereNull('deleted_at')->where('is_primary', true)->selectRaw('teacher_id, count(*) as total')->groupBy('teacher_id')->pluck('total');
    $campuses = DB::table('teacher_departments')->selectRaw('teacher_id, count(distinct campus_id) as total')->groupBy('teacher_id')->pluck('total');

    expect($primaries->count())->toBe(40)->and($primaries->unique()->all())->toBe([1])
        ->and($campuses->max())->toBe(2)
        ->and(DB::table('teacher_departments')->selectRaw('teacher_id')->groupBy('teacher_id')->havingRaw('count(*) > 1')->exists())->toBeTrue();
});

it('hay docentes de cada departamento con materias en el campus, inactivos, borrados, sin correo ni grado y con nombres largos', function () {
    seedDemoTeachers();

    $departments = DB::table('subjects')
        ->join('study_plan_subjects', 'study_plan_subjects.subject_id', '=', 'subjects.id')
        ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
        ->join('career_campus', 'career_campus.career_id', '=', 'study_plans.career_id')
        ->join('campuses', 'campuses.id', '=', 'career_campus.campus_id')
        ->where('campuses.code', 'C2')
        ->whereNull('subjects.deleted_at')
        ->distinct()
        ->pluck('subjects.department_id');
    $withTeachers = DB::table('teacher_departments')->whereIn('department_id', $departments)->distinct()->pluck('department_id');

    expect($departments->diff($withTeachers))->toBeEmpty()
        ->and(DB::table('teachers')->where('is_active', false)->exists())->toBeTrue()
        ->and(DB::table('teachers')->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and(DB::table('teachers')->whereNull('email')->exists())->toBeTrue()
        ->and(DB::table('teachers')->whereNull('degree')->exists())->toBeTrue()
        ->and(DB::table('teachers')->whereRaw('length(name) > 80')->exists())->toBeTrue();
});

it('la disponibilidad del periodo actual no se traslapa y cae entre las 7:00 y las 21:00', function () {
    seedDemoTeachers();

    $current = DB::table('terms')->where('code', '20271')->value('id');
    $rows = DB::table('teacher_availability')->get();

    expect($rows)->not->toBeEmpty()
        ->and($rows->pluck('term_id')->unique()->all())->toBe([$current])
        ->and($rows->min('starts_at'))->toBeGreaterThanOrEqual('07:00:00')
        ->and($rows->max('ends_at'))->toBeLessThanOrEqual('21:00:00')
        ->and($rows->max('day'))->toBeLessThanOrEqual(5)
        ->and(DB::table('teacher_availability')->select('teacher_id')->distinct()->count())->toBeGreaterThan(20);
});

it('hay horas fuera del ITCJ, perfil, historial de 8 periodos con una fila por grupo y evaluaciones de los dos tipos', function () {
    seedDemoTeachers();

    $terms = DB::table('teacher_subject_history')->distinct()->pluck('term_code');
    $perGroup = DB::table('teacher_subject_history')->selectRaw('teacher_id, subject_id, term_code, count(*) as total')->groupBy('teacher_id', 'subject_id', 'term_code')->pluck('total');

    expect(DB::table('teacher_external_loads')->exists())->toBeTrue()
        ->and(DB::table('teacher_subject_qualifications')->distinct()->pluck('level')->sort()->values()->all())->toBe(['apto', 'preferente'])
        ->and($terms)->toHaveCount(8)
        ->and($perGroup->max())->toBeGreaterThan(1)
        ->and(DB::table('teacher_evaluations')->distinct()->pluck('kind')->sort()->values()->all())->toBe(['departamental', 'estudiantil'])
        ->and((float) DB::table('teacher_evaluations')->min('score'))->toBeGreaterThanOrEqual(1.0)
        ->and((float) DB::table('teacher_evaluations')->max('score'))->toBeLessThanOrEqual(5.0);
});

it('todo docente activo tiene usuario con la contraseña por defecto y cambio obligatorio, y ningún inactivo o borrado', function () {
    seedDemoTeachers();

    $active = DB::table('teachers')->where('is_active', true)->whereNull('deleted_at');
    $linked = DB::table('teachers')->whereNotNull('user_id')->get();

    expect((clone $active)->whereNull('user_id')->count())->toBe(0)
        ->and(DB::table('teachers')->where(fn ($query) => $query->where('is_active', false)->orWhereNotNull('deleted_at'))->whereNotNull('user_id')->count())->toBe(0);

    $user = DB::table('users')->where('id', $linked->first()->user_id)->first();

    expect(Hash::check(config('auth.default_password'), $user->password))->toBeTrue()
        ->and((bool) $user->must_change_password)->toBeTrue();
});

it('algunos docentes son jefe de proyecto docente de su departamento, con su rol y su alcance', function () {
    seedDemoTeachers();

    $heads = DB::table('department_heads')
        ->join('teachers', 'teachers.user_id', '=', 'department_heads.user_id')
        ->where('kind', 'jefe_proyecto_docente')
        ->get();

    expect($heads)->not->toBeEmpty()
        ->and(DB::table('role_scopes')->whereIn('user_id', $heads->pluck('user_id'))->where('role', 'jefe_proyecto_docente')->count())->toBe($heads->count());
});

it('volver a sembrar no duplica nada', function () {
    seedDemoTeachers();

    $tables = ['teachers', 'teacher_departments', 'teacher_positions', 'teacher_availability', 'teacher_external_loads', 'teacher_subject_history', 'teacher_subject_qualifications', 'teacher_evaluations', 'users', 'department_heads', 'role_scopes'];
    $before = array_map(fn (string $table) => DB::table($table)->count(), $tables);

    seedDemoTeachers();

    expect(array_map(fn (string $table) => DB::table($table)->count(), $tables))->toBe($before);
});

it('los docentes de un campus son los mismos solo o junto con el otro', function () {
    DB::beginTransaction();
    seedDemoTeachers(['C2' => 20]);
    $alone = DB::table('teachers')->orderBy('employee_number')->pluck('name', 'employee_number')->all();
    DB::rollBack();

    expect(DB::table('teachers')->count())->toBe(0);

    seedDemoTeachers(['C1' => 20, 'C2' => 20]);
    $both = DB::table('teachers')->where('employee_number', 'like', 'D2%')->orderBy('employee_number')->pluck('name', 'employee_number')->all();

    expect($both)->toBe($alone)->and($alone)->toHaveCount(20);
});

it('se niega a sembrar en producción sin insertar nada', function () {
    seedDemoTeachers(['C2' => 1]);
    $before = DB::table('teachers')->count();

    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new DatabaseSeeder)->setContainer(app())->callWith(FacultyDemoSeeder::class, ['teachersPerCampus' => ['C2' => 5]]))
        ->toThrow(RuntimeException::class, 'producción')
        ->and(DB::table('teachers')->count())->toBe($before);
});

it('las plazas respetan las horas de su tipo y las vencidas y vigentes salen distintas', function () {
    seedDemoTeachers();

    $hours = ['tiempo_completo' => 40, 'tres_cuartos' => 30, 'medio_tiempo' => 20, 'honorarios' => 0];

    foreach (DB::table('teacher_positions')->get() as $position) {
        if ($position->kind === 'asignatura') {
            expect($position->base_hours)->toBeBetween(1, 40);
        } else {
            expect($position->base_hours)->toBe($hours[$position->kind]);
        }
    }

    expect(DB::table('teacher_positions')->where('valid_to', '<', '2026-10-30')->exists())->toBeTrue()
        ->and(DB::table('teacher_positions')->whereNull('valid_to')->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', '2026-10-30'))->exists())->toBeTrue();
});

it('ningún docente tiene bloques de disponibilidad traslapados en un día', function () {
    seedDemoTeachers();

    $overlaps = DB::table('teacher_availability as a')
        ->join('teacher_availability as b', fn ($join) => $join->on('a.teacher_id', '=', 'b.teacher_id')->on('a.term_id', '=', 'b.term_id')->on('a.day', '=', 'b.day')->whereColumn('a.id', '<', 'b.id'))
        ->whereColumn('a.starts_at', '<', 'b.ends_at')
        ->whereColumn('b.starts_at', '<', 'a.ends_at')
        ->count();

    expect($overlaps)->toBe(0);
});
