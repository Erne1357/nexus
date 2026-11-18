<?php

use App\Modules\Scheduling\Rules\Soft\BuildingChanges;
use App\Modules\Scheduling\Rules\Soft\CampusTravelTime;
use App\Modules\Scheduling\Rules\Soft\ConsecutiveSemesters;
use App\Modules\Scheduling\Rules\Soft\DefaultRoom;
use App\Modules\Scheduling\Rules\Soft\GroupDaySpread;
use App\Modules\Scheduling\Rules\Soft\HonorariosAvailability;
use App\Modules\Scheduling\Rules\Soft\PreferredBlocks;
use App\Modules\Scheduling\Rules\Soft\SoftContext;
use App\Modules\Scheduling\Rules\Soft\StudentGaps;
use App\Modules\Scheduling\Rules\Soft\TeacherGaps;
use App\Modules\Scheduling\Validation\ValidationScope;
use Tests\Support\Scheduling\States;

/*
| Las reglas blandas del puntaje del motor (tarea 6.5; docs/dominio/reglas-de-negocio.md, «Restricciones blandas»). Cada
| una cuenta su penalización en su unidad, sin peso, sobre lo que toca al alcance. Los puntajes de estas pruebas están
| calculados a mano: el comentario de cada escenario dice de dónde sale cada unidad.
*/

it('SC-01: cuenta las horas de hueco del alumno tipo por cohorte y día; una fracción cuenta como hora', function () {
    $state = States::builder()
        ->group(States::group('A'))->group(States::group('B'))->group(States::group('C'))
        ->group(States::group('D', semester: 2))->group(States::group('E', semester: 2))->group(States::group('X', active: false))
        ->session('A', 1, '07:00', '08:00')->session('B', 1, '10:00', '11:00')   // lunes: 2 h de hueco
        ->session('C', 1, '08:00', '10:00')                                       // C no es de la cohorte: no tapa el hueco
        ->session('A', 2, '07:00', '08:00')->session('B', 2, '08:00', '09:00')   // martes: sin hueco
        ->session('A', 3, '07:00', '08:00')->session('B', 3, '08:30', '09:30')   // miércoles: 30 min cuentan 1
        ->session('X', 4, '07:00', '08:00')->session('B', 4, '10:00', '11:00')   // jueves: X está cancelado
        ->session('D', 1, '07:00', '08:00')->session('E', 1, '12:00', '13:00')   // otra cohorte, fuera del alcance
        ->build();
    $context = new SoftContext(cohorts: ['A' => ['plan:P1:1:C2'], 'B' => ['plan:P1:1:C2'], 'X' => ['plan:P1:1:C2'], 'D' => ['plan:P1:2:C2'], 'E' => ['plan:P1:2:C2']]);

    expect((new StudentGaps)->rule())->toBe('SC-01')
        ->and((new StudentGaps)->penalty($state, ValidationScope::groups('A'), $context))->toBe(3)
        ->and((new StudentGaps)->penalty($state, ValidationScope::groups('A', 'D'), $context))->toBe(7)
        ->and((new StudentGaps)->penalty($state, ValidationScope::groups('C'), $context))->toBe(0);
});

it('SC-01: un hueco entre dos grupos que no son del alcance no cuenta, aunque sean de la cohorte', function () {
    $state = States::builder()
        ->group(States::group('A'))->group(States::group('F1'))->group(States::group('F2'))
        ->session('F1', 1, '07:00', '08:00')->session('F2', 1, '11:00', '12:00')   // 3 h entre dos fijos
        ->session('A', 2, '07:00', '08:00')->session('F1', 2, '09:00', '10:00')    // 1 h junto a A
        ->build();
    $context = new SoftContext(cohorts: ['A' => ['K'], 'F1' => ['K'], 'F2' => ['K']]);

    expect((new StudentGaps)->penalty($state, ValidationScope::groups('A'), $context))->toBe(1);
});

it('SC-02: cuenta las horas de un grupo en un día que pasan del reparto parejo de sus horas en los días de su modalidad', function () {
    $state = States::builder()
        ->group(States::group('A', subject: 'S5'))->group(States::group('B', subject: 'S6'))
        ->subject(States::subject('S5', theory: 3, practice: 2))->subject(States::subject('S6', theory: 4, practice: 2))
        ->session('A', 1, '07:00', '09:00')->session('A', 2, '07:00', '08:00')->session('A', 3, '07:00', '09:00') // 5 h en 5 días: 1 por día; sobran 1 + 1
        ->session('B', 1, '07:00', '10:00')->session('B', 1, '12:00', '13:00')                                  // 6 h en 5 días: 2 por día; el lunes tiene 4
        ->build();

    expect((new GroupDaySpread)->rule())->toBe('SC-02')
        ->and((new GroupDaySpread)->penalty($state, ValidationScope::groups('A'), new SoftContext))->toBe(2)
        ->and((new GroupDaySpread)->penalty($state, ValidationScope::groups('B'), new SoftContext))->toBe(2)
        ->and((new GroupDaySpread)->penalty($state, ValidationScope::groups('A', 'B'), new SoftContext))->toBe(4);
});

it('SC-03: cuenta las horas en un salón que no es el por defecto ni sugerido de la materia en su campus', function () {
    $state = States::builder()
        ->group(States::group('A', subject: 'CAL'))->group(States::group('B', subject: 'QUI'))->group(States::group('C', subject: 'CAL', campus: 'C1'))
        ->session('A', 1, '07:00', '09:00', 'R1')   // por defecto
        ->session('A', 2, '07:00', '08:00', 'R2')   // sugerido
        ->session('A', 3, '07:00', '09:00', 'R3')   // otro: 2 h
        ->session('A', 4, '07:00', '08:00')         // sin salón
        ->session('B', 1, '09:00', '11:00', 'R3')   // su materia no tiene salón por defecto
        ->session('C', 1, '09:00', '11:00', 'R3')   // en C1 la materia no tiene salón por defecto
        ->build();
    $context = new SoftContext(roomDefaults: ['CAL|C2' => ['R1' => 1, 'R2' => 2]]);

    expect((new DefaultRoom)->rule())->toBe('SC-03')
        ->and((new DefaultRoom)->penalty($state, ValidationScope::groups('A', 'B', 'C'), $context))->toBe(2);
});

it('SC-04: cuenta las horas fuera de los bloques preferidos del docente, solo si declaró alguno', function () {
    $state = States::builder()
        ->group(States::group('A', teacher: 'T1'))->group(States::group('B', teacher: 'T2', semester: 2))
        ->teacher(States::teacher('T1'))->teacher(States::teacher('T2'))
        ->session('A', 1, '08:00', '10:00')   // de 9:00 a 10:00 fuera
        ->session('A', 2, '09:00', '11:00')   // dentro
        ->session('A', 3, '07:00', '08:00')   // fuera
        ->session('B', 3, '07:00', '08:00')   // T2 no declaró preferidos
        ->build();
    $context = new SoftContext(preferred: ['T1' => [[1, 420, 540], [2, 420, 720]]]);

    expect((new PreferredBlocks)->rule())->toBe('SC-04')
        ->and((new PreferredBlocks)->penalty($state, ValidationScope::groups('A', 'B'), $context))->toBe(2);
});

it('SC-05: cuenta las horas de hueco del docente por día entre sesiones que tocan el alcance', function () {
    $state = States::builder()
        ->group(States::group('A', teacher: 'T1'))->group(States::group('B', teacher: 'T1', semester: 2))->group(States::group('D', teacher: 'T1', semester: 3))
        ->teacher(States::teacher('T1'))
        ->session('A', 1, '07:00', '08:00')->session('B', 1, '10:00', '11:00')->session('B', 1, '11:00', '12:00') // lunes: 2 h
        ->session('A', 2, '07:00', '08:00')->session('B', 2, '08:30', '09:30')                                   // martes: 1 (30 min)
        ->session('D', 4, '07:00', '08:00')->session('D', 4, '09:00', '10:00')                                   // jueves: hueco de D, no toca A
        ->build();

    expect((new TeacherGaps)->rule())->toBe('SC-05')
        ->and((new TeacherGaps)->penalty($state, ValidationScope::groups('A'), new SoftContext))->toBe(3)
        ->and((new TeacherGaps)->penalty($state, ValidationScope::groups('A', 'D'), new SoftContext))->toBe(4)
        ->and((new TeacherGaps)->penalty($state, ValidationScope::teacher('T1'), new SoftContext))->toBe(4);
});

it('SC-06: cuenta los cambios de edificio entre dos sesiones seguidas, sin hueco, del docente', function () {
    $state = States::builder()
        ->group(States::group('A', teacher: 'T1'))->group(States::group('B', teacher: 'T1', semester: 2))
        ->teacher(States::teacher('T1'))
        ->session('A', 1, '07:00', '08:00', 'R1')->session('B', 1, '08:00', '09:00', 'R3')->session('A', 1, '09:00', '10:00', 'R2') // lunes: B1 → B2 → B1
        ->session('A', 2, '07:00', '08:00', 'R1')->session('B', 2, '09:00', '10:00', 'R3')                                     // martes: con hueco
        ->session('A', 3, '07:00', '08:00', 'R1')->session('B', 3, '08:00', '09:00', 'R2')                                     // miércoles: mismo edificio
        ->session('A', 4, '07:00', '08:00', 'R1')->session('B', 4, '08:00', '09:00')                                           // jueves: sin salón
        ->build();
    $context = new SoftContext(buildings: ['R1' => 'E1', 'R2' => 'E1', 'R3' => 'E2']);

    expect((new BuildingChanges)->rule())->toBe('SC-06')
        ->and((new BuildingChanges)->penalty($state, ValidationScope::groups('A'), $context))->toBe(2);
});

it('SC-07: cuenta las horas de empalme entre los grupos que son la única opción de su materia en semestres seguidos del mismo plan y campus', function () {
    $state = States::builder()
        ->group(States::group('A', semester: 1))->group(States::group('B', semester: 2))->group(States::group('C', semester: 3))
        ->group(States::group('D', semester: 2, plan: 'P2'))->group(States::group('E', semester: 2, campus: 'C1'))
        ->group(States::group('G', semester: 2, active: false))->group(States::group('H', semester: 2))
        ->session('A', 1, '07:00', '09:00')
        ->session('B', 1, '08:00', '10:00')   // 1 h con A
        ->session('C', 1, '07:00', '09:00')   // semestre 3: no es consecutivo de A; con B, 1 h
        ->session('D', 1, '07:00', '09:00')->session('E', 1, '07:00', '09:00')->session('G', 1, '07:00', '09:00')
        ->session('H', 1, '07:00', '09:00')   // su materia tiene otro grupo: no es cohorte
        ->build();
    $context = new SoftContext(cohorts: [
        'A' => ['plan:P1:1:C2'], 'B' => ['plan:P1:2:C2'], 'C' => ['plan:P1:3:C2', 'package:K:C2:1'], 'D' => ['plan:P2:2:C2'],
        'E' => ['plan:P1:2:C1'], 'G' => ['plan:P1:2:C2'], 'H' => [],
    ]);

    expect((new ConsecutiveSemesters)->rule())->toBe('SC-07')
        ->and((new ConsecutiveSemesters)->penalty($state, ValidationScope::groups('A'), $context))->toBe(1)
        ->and((new ConsecutiveSemesters)->penalty($state, ValidationScope::groups('A', 'B'), $context))->toBe(2)
        ->and((new ConsecutiveSemesters)->penalty($state, ValidationScope::groups('H'), $context))->toBe(0)
        ->and((new ConsecutiveSemesters)->penalty($state, ValidationScope::groups('A'), new SoftContext(cohorts: ['A' => ['plan:P1:1:C2', 'plan:P1:2:C2']])))->toBe(0);
});

it('SC-08: cuenta las horas de un grupo de docente de honorarios fuera de los bloques que declaró', function () {
    $state = States::builder()
        ->group(States::group('A', teacher: 'T1'))->group(States::group('B', teacher: 'T2', semester: 2))->group(States::group('C', teacher: 'T3', semester: 3))
        ->teacher(States::teacher('T1', honorarios: true))->teacher(States::teacher('T2'))->teacher(States::teacher('T3', honorarios: true))
        ->session('A', 1, '08:00', '10:00')->session('A', 2, '07:00', '08:00')   // 1 + 1
        ->session('B', 2, '07:00', '08:00')                                       // T2 no es de honorarios
        ->session('C', 2, '07:00', '08:00')                                       // T3 no declaró bloques
        ->build();
    $context = new SoftContext(preferred: ['T1' => [[1, 420, 540]], 'T2' => [[1, 420, 540]]]);

    expect((new HonorariosAvailability)->rule())->toBe('SC-08')
        ->and((new HonorariosAvailability)->penalty($state, ValidationScope::groups('A', 'B', 'C'), $context))->toBe(2)
        ->and((new PreferredBlocks)->penalty($state, ValidationScope::groups('A', 'B', 'C'), $context))->toBe(3);
});

it('SC-09: cuenta cada par de sesiones seguidas del docente en campus distintos con menos minutos que el traslado', function () {
    $state = States::builder()
        ->group(States::group('A', teacher: 'T1', campus: 'C1'))->group(States::group('B', teacher: 'T1', semester: 2))
        ->teacher(States::teacher('T1'))->setting('staffing.min_campus_travel_minutes', 60)
        ->session('A', 2, '07:00', '09:00')->session('B', 2, '09:00', '10:00')   // 0 min: cuenta
        ->session('A', 3, '07:00', '09:00')->session('B', 3, '10:00', '11:00')   // 60 min: alcanza
        ->session('B', 4, '07:00', '08:00')->session('A', 4, '08:30', '09:30')   // 30 min: cuenta
        ->build();

    expect((new CampusTravelTime)->rule())->toBe('SC-09')
        ->and((new CampusTravelTime)->penalty($state, ValidationScope::groups('A'), new SoftContext))->toBe(2)
        ->and((new CampusTravelTime)->penalty($state, ValidationScope::groups('A', 'B'), new SoftContext))->toBe(2);
});
