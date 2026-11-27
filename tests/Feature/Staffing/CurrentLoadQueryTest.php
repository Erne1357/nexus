<?php

use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Staffing\Queries\CurrentLoadQuery;
use App\Modules\Staffing\Rules\SessionChips;
use Tests\Support\Staffing\AssignmentWorld;

/*
| CurrentLoadQuery (tarea 1.57, SEL-18): los grupos del departamento por materia, con sus cuentas y filtros.
*/

beforeEach(function () {
    $this->world = AssignmentWorld::create();
    $isc = $this->world->career('ISC');
    $icbs = $this->world->career('ICBS');
    $this->poo = $this->world->subject('SCC-1013', 'Programación Orientada a Objetos');
    $this->redes = $this->world->subject('SCD-1021', 'Redes de Computadoras');
    $this->poo->update(['required_space' => RequiredSpace::Aula]);

    $this->g1 = $this->world->group($this->poo, $isc, 'G', 4, [[2, '13:00', '15:00']]);
    $this->g2 = $this->world->group($this->poo, $isc, 'D', 2, [[1, '10:00', '11:00']]);
    $this->g3 = $this->world->group($this->redes, $icbs, 'H', 1, []);
    $this->g1->update(['teacher_id' => $this->world->teacher('Ana Ruiz')->id]);
    ScheduleConflict::factory()->forGroup($this->g2)->create(['rule' => 'HC-01']);
    ScheduleConflict::factory()->forGroup($this->g1)->soft()->create();

    $this->board = fn (?string $campus = null) => app(CurrentLoadQuery::class)->board($this->world->term->id, modalityOf('PRES')->id, $this->world->department->id, $campus);
});

it('SEL-18: agrupa por materia ordenada por su semestre más bajo y sus grupos por semestre y clave', function () {
    $board = ($this->board)();

    expect(array_map(fn ($subject) => $subject->subject->code, $board->subjects))->toBe(['SCD-1021', 'SCC-1013'])
        ->and(array_map(fn ($row) => $row->group->code, $board->subjects[1]->groups))->toBe(['D', 'G'])
        ->and($board->total())->toBe(3)
        ->and($board->withTeacher())->toBe(1);
});

it('cuenta cada filtro rápido sobre los grupos de la carga; el choque suave no cuenta', function () {
    $board = ($this->board)();

    expect($board->count('todos'))->toBe(3)
        ->and($board->count('sin_docente'))->toBe(2)
        ->and($board->count('sin_salon'))->toBe(2)
        ->and($board->count('choque'))->toBe(1)
        ->and($board->count('sin_espacio'))->toBe(1);
});

it('filtra por estado, carrera y búsqueda (sin acentos) y omite las materias sin grupos a la vista', function () {
    $board = ($this->board)();
    $codes = fn (array $subjects) => array_map(fn ($subject) => array_map(fn ($row) => $row->group->code, $subject->groups), $subjects);

    expect($codes($board->filtered('choque', '', '')))->toBe([['D']])
        ->and($codes($board->filtered('todos', 'ICBS', '')))->toBe([['H']])
        ->and($codes($board->filtered('todos', '', 'programacion')))->toBe([['D', 'G']])
        ->and($codes($board->filtered('todos', '', 'ANA ruiz')))->toBe([['G']])
        ->and($board->filtered('choque', '', 'redes'))->toBe([]);
});

it('SEL-18: los choques duros resueltos no se listan y los grupos cancelados no cuentan', function () {
    ScheduleConflict::query()->where('group_id', $this->g2->id)->update(['resolved_at' => now()]);
    $this->g3->update(['status' => GroupStatus::Cancelled]);

    $board = ($this->board)();

    expect($board->count('choque'))->toBe(0)
        ->and($board->total())->toBe(2);
});

it('SessionChips junta días seguidos con las mismas horas y salón, y separa los distintos', function () {
    $session = fn (int $day, string $room = '703', bool $virtual = false) => ['day' => $day, 'starts_at' => '10:00:00', 'ends_at' => '11:00:00', 'room' => $room, 'virtual' => $virtual];

    expect(array_column(SessionChips::of([$session(1), $session(2), $session(3), $session(4), $session(5)]), 'label'))->toBe(['Lu–Vi 10–11'])
        ->and(array_column(SessionChips::of([$session(1), $session(3)]), 'label'))->toBe(['Lu/Mi 10–11'])
        ->and(SessionChips::of([$session(1), $session(2, '704')]))->toHaveCount(2);
});
