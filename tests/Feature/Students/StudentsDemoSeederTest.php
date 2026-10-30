<?php

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Students\Database\Seeders\StudentsDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/*
| Alumnos y kárdex demo (tarea 1.8, PR 3): se siembran sobre el catálogo demo de la base, con la trayectoria que
| simula TrajectorySimulator, y al final se recalcula student_progress. Los de cada carrera activa son `N`; las
| carreras inactivas dan unos pocos más, sin kárdex.
*/

/**
 * Siembra el catálogo de Campus II y `$perOffering` alumnos por carrera.
 *
 * @param  array<string, int>  $perOffering
 */
function seedDemoStudents(array $perOffering = ['C2' => 5], int $seed = 2027): void
{
    $seeder = (new DatabaseSeeder)->setContainer(app());

    $seeder->call(CampusSeeder::class);
    $seeder->call(DemoTermSeeder::class);
    $seeder->callWith(AcademicDemoSeeder::class, ['campuses' => ['C2']]);
    $seeder->callWith(StudentsDemoSeeder::class, ['studentsPerOffering' => $perOffering, 'seed' => $seed]);
}

it('siembra los alumnos de cada carrera activa con el plan de su carrera y su campus', function () {
    seedDemoStudents();

    $students = DB::table('students')->get();

    expect($students->where('is_active', true)->count() + $students->where('is_active', false)->count())->toBe($students->count())
        ->and(DB::table('students')->whereIn('career_id', DB::table('careers')->where('is_active', true)->select('id'))->count())->toBe(30)
        ->and($students->count())->toBe(33);

    $mismatched = DB::table('students')
        ->join('study_plans', 'study_plans.id', '=', 'students.study_plan_id')
        ->whereColumn('study_plans.career_id', '!=', 'students.career_id')
        ->count();
    $otherCampus = DB::table('students')
        ->leftJoin('career_campus', fn ($join) => $join->on('career_campus.career_id', '=', 'students.career_id')->on('career_campus.campus_id', '=', 'students.campus_id'))
        ->whereNull('career_campus.id')
        ->count();

    expect($mismatched)->toBe(0)->and($otherCampus)->toBe(0);
});

it('hay alumnos de cada estatus, sin estatus, desactivados y en el plan viejo', function () {
    seedDemoStudents();

    $byStatus = DB::table('students')->pluck('status')->countBy()->all();

    expect($byStatus)->toHaveKeys(['activo', 'baja_temporal', 'baja_definitiva', 'egresado', ''])
        ->and(DB::table('students')->where('is_active', false)->count())->toBeGreaterThan(0)
        ->and(DB::table('students')->join('study_plans', 'study_plans.id', '=', 'students.study_plan_id')->where('study_plans.is_active', false)->whereExists(
            fn ($query) => $query->selectRaw('1')->from('study_plan_subjects')->whereColumn('study_plan_subjects.study_plan_id', 'study_plans.id'),
        )->count())->toBeGreaterThan(0);
});

it('las carreras que ya no se ofrecen dan alumnos desactivados en el plan sin retícula y sin kárdex', function () {
    seedDemoStudents();

    $students = DB::table('students')
        ->join('careers', 'careers.id', '=', 'students.career_id')
        ->where('careers.is_active', false)
        ->get(['students.id', 'students.is_active', 'students.study_plan_id']);

    expect($students)->toHaveCount(3)
        ->and($students->where('is_active', true))->toBeEmpty()
        ->and(DB::table('kardex_records')->whereIn('student_id', $students->pluck('id'))->count())->toBe(0)
        ->and(DB::table('student_progress')->whereIn('student_id', $students->pluck('id'))->count())->toBe(0);
});

it('el kárdex no se repite por alumno, materia y periodo y respeta las oportunidades', function () {
    seedDemoStudents(['C2' => 10]);

    $duplicates = DB::table('kardex_records')->selectRaw('student_id, subject_id, term_code, count(*) as n')->groupBy('student_id', 'subject_id', 'term_code')->havingRaw('count(*) > 1')->count();
    $terms = DB::table('kardex_records')->distinct()->pluck('term_code');

    expect(DB::table('kardex_records')->count())->toBeGreaterThan(1000)
        ->and($duplicates)->toBe(0)
        ->and($terms->every(fn (string $term) => preg_match('/^\d{4}[123]$/', $term) === 1 && $term <= StudentsDemoSeeder::CURRENT_TERM))->toBeTrue()
        ->and(DB::table('kardex_records')->where('result', 'cursando')->where('term_code', StudentsDemoSeeder::CURRENT_TERM)->count())->toBeGreaterThan(0)
        ->and(DB::table('kardex_records')->whereNotIn('result', ['aprobada', 'reprobada', 'cursando', 'baja'])->count())->toBe(0)
        ->and(DB::table('kardex_records')->where('attempt', '>', 3)->count())->toBe(0);
});

it('un egresado tiene acreditada toda su retícula menos las otras especialidades', function () {
    seedDemoStudents(['C2' => 10]);

    $graduates = DB::table('students')->where('status', 'egresado')->where('is_active', true)->get(['id', 'study_plan_id']);
    $incomplete = DB::table('student_progress')->whereIn('student_id', $graduates->pluck('id'))->whereRaw('jsonb_array_length(subjects_pending) > 0')->count();

    expect($graduates->count())->toBeGreaterThan(5)
        ->and($incomplete)->toBe(0);
});

it('student_progress tiene fila para los alumnos con plan con retícula', function () {
    seedDemoStudents();

    $withGrid = DB::table('students')->whereExists(
        fn ($query) => $query->selectRaw('1')->from('study_plan_subjects')->whereColumn('study_plan_subjects.study_plan_id', 'students.study_plan_id')->whereNull('study_plan_subjects.deleted_at'),
    )->count();

    expect($withGrid)->toBe(30)
        ->and(DB::table('student_progress')->count())->toBe($withGrid);
});

it('la misma semilla da los mismos números de control, nombres y kárdex', function () {
    seedDemoStudents();
    $first = [DB::table('students')->orderBy('control_number')->get(['control_number', 'name', 'status', 'current_semester'])->map(fn (object $row): array => (array) $row)->all(), DB::table('kardex_records')->count()];

    DB::table('student_progress')->delete();
    DB::table('kardex_records')->delete();
    DB::table('students')->delete();

    seedDemoStudents();
    $second = [DB::table('students')->orderBy('control_number')->get(['control_number', 'name', 'status', 'current_semester'])->map(fn (object $row): array => (array) $row)->all(), DB::table('kardex_records')->count()];

    expect($second)->toBe($first)
        ->and($first[0][0]['control_number'])->toMatch('/^\d{2}1[12]\d{4}$/');
});

it('volver a sembrar no duplica alumnos ni kárdex', function () {
    seedDemoStudents();
    $counts = [DB::table('students')->count(), DB::table('kardex_records')->count()];

    seedDemoStudents();

    expect([DB::table('students')->count(), DB::table('kardex_records')->count()])->toBe($counts);
});

it('se niega a sembrar en producción', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => seedDemoStudents())->toThrow(RuntimeException::class);
});
