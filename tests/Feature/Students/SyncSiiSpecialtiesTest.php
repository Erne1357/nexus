<?php

use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Students\Actions\SyncSiiSpecialties;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\DB;

/*
| 1.69: la especialidad que el SII le asignó al alumno (`students.specialty_id`) se llena de lo ya importado; se busca
| por su clave dentro del plan del alumno.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create(['code' => 'ISC-2010']);
    $this->specialty = Specialty::factory()->for($this->plan)->create(['code' => '26']);
    $this->student = Student::factory()->for($this->plan)->create(['control_number' => '20110001']);
});

it('asigna la especialidad del SII a un alumno ya importado', function () {
    $result = app(SyncSiiSpecialties::class)->handle([['control' => '20110001', 'code' => '26']]);

    expect($this->student->fresh()->specialty_id)->toBe($this->specialty->id)
        ->and($result)->toMatchArray(['set' => 1, 'unchanged' => 0, 'unmatched' => [], 'unknownStudents' => 0]);
});

it('es idempotente y con --dry-run no escribe', function () {
    app(SyncSiiSpecialties::class)->handle([['control' => '20110001', 'code' => '26']], dryRun: true);
    expect($this->student->fresh()->specialty_id)->toBeNull();

    app(SyncSiiSpecialties::class)->handle([['control' => '20110001', 'code' => '26']]);
    $again = app(SyncSiiSpecialties::class)->handle([['control' => '20110001', 'code' => '26']]);

    expect($again)->toMatchArray(['set' => 0, 'unchanged' => 1]);
});

it('una clave que el plan del alumno no tiene lo deja sin especialidad y se informa; un control desconocido se cuenta', function () {
    Specialty::factory()->for(StudyPlan::factory()->create())->create(['code' => '99']);

    $result = app(SyncSiiSpecialties::class)->handle([['control' => '20110001', 'code' => '99'], ['control' => '00000000', 'code' => '26']]);

    expect($this->student->fresh()->specialty_id)->toBeNull()
        ->and($result['unmatched'])->toBe(['ISC-2010/99' => 1])
        ->and($result['unknownStudents'])->toBe(1);
});

it('el comando lee sii_archivo.alumnos_todos', function () {
    DB::unprepared('create schema if not exists sii_archivo');
    DB::unprepared('drop table if exists sii_archivo.alumnos_todos');
    DB::unprepared('create table sii_archivo.alumnos_todos (_linea integer primary key, no_de_control text not null, especialidad text not null)');
    DB::table('sii_archivo.alumnos_todos')->insert([
        ['_linea' => 2, 'no_de_control' => '20110001', 'especialidad' => '26'],
        ['_linea' => 3, 'no_de_control' => '20110002', 'especialidad' => ''],
    ]);

    $this->artisan('students:sync-sii-specialties', ['--dry-run' => true])->expectsOutputToContain('Por asignar: 1')->assertSuccessful();
    expect($this->student->fresh()->specialty_id)->toBeNull();

    $this->artisan('students:sync-sii-specialties')->assertSuccessful();
    expect($this->student->fresh()->specialty_id)->toBe($this->specialty->id);
});
