<?php

use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\DB;

/*
| imports:backfill-kardex-groups (MP-04): rellena el grupo del SII del kárdex ya migrado desde `sii_archivo`. Las pruebas
| arman un `sii_archivo` mínimo dentro de la transacción de la prueba.
*/

beforeEach(function () {
    DB::unprepared('create schema if not exists sii_archivo');
    DB::unprepared('drop table if exists sii_archivo.seleccion_materias');
    DB::unprepared('drop table if exists sii_archivo.historia_alumno');
    DB::unprepared("create table sii_archivo.seleccion_materias (_linea serial primary key, periodo text not null, no_de_control text not null, materia text not null, grupo text not null, status_seleccion text not null default 'I')");
    DB::unprepared('create table sii_archivo.historia_alumno (_linea serial primary key, periodo text not null, no_de_control text not null, materia text not null, grupo text not null)');
    $this->student = Student::factory()->create(['control_number' => 'B1']);
});

function kardexGroupRow(Student $student, string $term, string $sii): KardexRecord
{
    return KardexRecord::factory()->create(['student_id' => $student->id, 'term_code' => $term, 'sii_subject_code' => $sii, 'sii_group_code' => null]);
}

it('MP-04: rellena el grupo desde la selección, y desde la historia si no está ahí', function () {
    $current = kardexGroupRow($this->student, '20263', 'QU1');
    $closed = kardexGroupRow($this->student, '20261', 'CD1');
    $none = kardexGroupRow($this->student, '20261', 'ZZ9');
    DB::table('sii_archivo.seleccion_materias')->insert([
        ['periodo' => '20263', 'no_de_control' => 'B1', 'materia' => 'QU1', 'grupo' => 'x', 'status_seleccion' => 'B'],
        ['periodo' => '20263', 'no_de_control' => 'B1', 'materia' => 'QU1', 'grupo' => ' k ', 'status_seleccion' => 'I'],
    ]);
    DB::table('sii_archivo.historia_alumno')->insert(['periodo' => '20261', 'no_de_control' => 'B1', 'materia' => 'CD1', 'grupo' => 'b2']);

    $this->artisan('imports:backfill-kardex-groups')->assertSuccessful();

    expect($current->fresh()->sii_group_code)->toBe('K')
        ->and($closed->fresh()->sii_group_code)->toBe('B2')
        ->and($none->fresh()->sii_group_code)->toBeNull();
});

it('con --dry-run no escribe, y repetirlo no cambia lo ya rellenado', function () {
    $row = kardexGroupRow($this->student, '20263', 'QU1');
    DB::table('sii_archivo.seleccion_materias')->insert(['periodo' => '20263', 'no_de_control' => 'B1', 'materia' => 'QU1', 'grupo' => 'K']);

    $this->artisan('imports:backfill-kardex-groups', ['--dry-run' => true])->assertSuccessful();
    expect($row->fresh()->sii_group_code)->toBeNull();

    $this->artisan('imports:backfill-kardex-groups')->assertSuccessful();
    DB::table('sii_archivo.seleccion_materias')->update(['grupo' => 'Z']);
    $this->artisan('imports:backfill-kardex-groups')->assertSuccessful();

    expect($row->fresh()->sii_group_code)->toBe('K');
});
