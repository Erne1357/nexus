<?php

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use Illuminate\Database\QueryException;

/*
| Esquema de la selección de carga (tarea 1.33): una selección viva por periodo, modalidad, departamento y campus, y un
| renglón por docente.
*/

it('la base rechaza dos selecciones vivas del mismo periodo, modalidad, departamento y campus', function () {
    $selection = Selection::factory()->create();

    expect(fn () => Selection::factory()->create($selection->only(['term_id', 'modality_id', 'department_id', 'campus_id'])))
        ->toThrow(QueryException::class);
});

it('una selección cancelada no impide crear otra igual', function () {
    $selection = Selection::factory()->create(['status' => SelectionStatus::Cancelled, 'cancelled_at' => now()]);

    $other = Selection::factory()->create($selection->only(['term_id', 'modality_id', 'department_id', 'campus_id']));

    expect($other->status)->toBe(SelectionStatus::Draft);
});

it('un docente aparece una sola vez en una selección', function () {
    $selection = Selection::factory()->create();
    $teacher = Teacher::factory()->create();
    SelectionEntry::factory()->for($selection)->create(['teacher_id' => $teacher->id]);

    expect(fn () => SelectionEntry::factory()->for($selection)->create(['teacher_id' => $teacher->id]))
        ->toThrow(QueryException::class);
});

it('varios renglones sin empatar caben en la misma selección', function () {
    $selection = Selection::factory()->create();

    SelectionEntry::factory()->count(2)->for($selection)->create(['teacher_id' => null]);

    expect($selection->entries()->count())->toBe(2);
});

it('la selección guarda las cargas incluidas y la ventana por omisión', function () {
    $selection = Selection::factory()->create();
    $load = AcademicLoad::factory()->create();

    $selection->loads()->attach($load);

    expect($selection->loads()->pluck('academic_loads.id')->all())->toBe([$load->id])
        ->and($selection->fresh()->weekdays)->toBe([1, 2, 3, 4, 5])
        ->and($selection->fresh()->slot_minutes)->toBe(30)
        ->and($selection->fresh()->window_starts)->toStartWith('09:00')
        ->and($selection->fresh()->window_ends)->toStartWith('14:00');
});
