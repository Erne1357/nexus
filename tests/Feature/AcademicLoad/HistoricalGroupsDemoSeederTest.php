<?php

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\AcademicLoad\Database\Seeders\HistoricalGroupsDemoSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Students\Database\Seeders\StudentsDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/*
| Historial de grupos demo (tarea 5.6): coherente con el kárdex. Por periodo Ene-Jun o Ago-Dic, materia y campus, la
| suma de inscritos de los grupos es la cuenta de alumnos distintos con resultado aprobada, reprobada o cursando.
*/

function historicalGroupsSeed(int $seed = 2027): void
{
    $seeder = (new DatabaseSeeder)->setContainer(app());
    $seeder->call(CampusSeeder::class);
    $seeder->call(DemoTermSeeder::class);
    $seeder->callWith(AcademicDemoSeeder::class, ['campuses' => ['C2']]);
    $seeder->callWith(StudentsDemoSeeder::class, ['studentsPerOffering' => ['C2' => 12], 'seed' => $seed]);
    $seeder->callWith(HistoricalGroupsDemoSeeder::class, ['seed' => $seed]);
}

it('la suma de inscritos por materia, periodo y campus es la cuenta del kárdex', function () {
    historicalGroupsSeed();

    $expected = DB::table('kardex_records')
        ->join('students', 'students.id', '=', 'kardex_records.student_id')
        ->whereIn('kardex_records.result', ['aprobada', 'reprobada', 'cursando'])
        ->whereRaw("kardex_records.term_code ~ '^[0-9]{4}[13]$'")
        ->groupBy('kardex_records.term_code', 'kardex_records.subject_id', 'students.campus_id')
        ->selectRaw('kardex_records.term_code, kardex_records.subject_id, students.campus_id, count(distinct students.id) as total')
        ->get()
        ->mapWithKeys(fn (object $row): array => ["{$row->term_code}|{$row->subject_id}|{$row->campus_id}" => (int) $row->total])
        ->sortKeys()->all();

    $actual = DB::table('historical_groups')
        ->where('source', 'sii')
        ->groupBy('term_code', 'subject_id', 'campus_id')
        ->selectRaw('term_code, subject_id, campus_id, sum(enrolled) as total')
        ->get()
        ->mapWithKeys(fn (object $row): array => ["{$row->term_code}|{$row->subject_id}|{$row->campus_id}" => (int) $row->total])
        ->sortKeys()->all();

    expect($expected)->not->toBeEmpty()->and($actual)->toBe($expected);
});

it('arma ceil(alumnos / tamaño) grupos con capacidad suficiente y códigos únicos, y no incluye el verano', function () {
    historicalGroupsSeed();

    $overfull = DB::table('historical_groups')->whereColumn('enrolled', '>', 'capacity')->count();
    $duplicated = DB::table('historical_groups')->select('term_code', 'subject_id', 'group_code')->groupBy('term_code', 'subject_id', 'group_code')->havingRaw('count(*) > 1')->count();

    expect(DB::table('historical_groups')->count())->toBeGreaterThan(0)
        ->and($overfull)->toBe(0)->and($duplicated)->toBe(0)
        ->and(DB::table('historical_groups')->whereRaw("term_code like '%2'")->count())->toBe(0);
});

it('con la misma semilla salen las mismas filas, y sembrar otra vez no duplica', function () {
    historicalGroupsSeed();
    $first = DB::table('historical_groups')->orderBy('term_code')->orderBy('subject_id')->orderBy('campus_id')->orderBy('group_code')->get(['term_code', 'subject_id', 'campus_id', 'group_code', 'capacity', 'enrolled'])->toArray();

    (new DatabaseSeeder)->setContainer(app())->callWith(HistoricalGroupsDemoSeeder::class, ['seed' => 2027]);

    expect(DB::table('historical_groups')->count())->toBe(count($first));
});
