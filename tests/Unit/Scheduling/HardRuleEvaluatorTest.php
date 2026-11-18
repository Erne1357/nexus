<?php

use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Rules\Constraints\CampusTravel;
use App\Modules\Scheduling\Rules\Evaluator\HardRuleEvaluator;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleStateBuilder;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\State\StateQuota;
use App\Modules\Scheduling\Validation\State\StateRoomBlock;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| El evaluador en memoria de las reglas duras (tarea 6.5): corre las mismas clases de HC-01 a HC-13 que el validador de
| la fase 3 sobre una foto armada sin base, para que la heurística y la pantalla de corridas comparen soluciones
| candidatas. Cada escenario es el de su regla en ScheduleValidatorEquivalenceTest, que lo compara contra la base.
*/

/**
 * Una foto con el plan activo y la carrera ofrecida, para que solo choque lo que cada escenario agrega.
 */
function evaluatorBuilder(): ScheduleStateBuilder
{
    return States::builder()->activePlan('P1')->offering('ISC', 'C2')->offering('ISC', 'C1');
}

/**
 * @return list<string>
 */
function evaluatorRules(iterable $violations): array
{
    $rules = array_values(array_unique(array_map(fn (Violation $violation): string => $violation->rule, [...$violations])));
    sort($rules);

    return $rules;
}

dataset('one scenario per hard rule', [
    'HC-01' => ['HC-01', fn () => evaluatorBuilder()
        ->group(States::group('A', teacher: 'T1'))->group(States::group('B', teacher: 'T1', semester: 2))
        ->teacher(States::teacher('T1'))
        ->session('A', 1, '07:00', '09:00')->session('B', 1, '08:00', '10:00')],
    'HC-02' => ['HC-02', fn () => evaluatorBuilder()
        ->group(States::group('A'))->group(States::group('B', semester: 2))->room(States::room('R1'))
        ->session('A', 1, '07:00', '09:00', 'R1')->session('B', 1, '08:00', '10:00', 'R1')],
    'HC-03' => ['HC-03', fn () => evaluatorBuilder()
        ->group(States::group('A'))->group(States::group('B'))
        ->session('A', 1, '07:00', '09:00')->session('B', 1, '08:00', '10:00')],
    'HC-04' => ['HC-04', fn () => evaluatorBuilder()
        ->group(States::group('A'))->profile('PRES', ModalityProfile::fromArray([]))
        ->session('A', 6, '07:00', '09:00')],
    'HC-05' => ['HC-05', fn () => evaluatorBuilder()
        ->group(States::group('A', capacity: 35))->room(States::room('R1', capacity: 20))
        ->session('A', 1, '07:00', '09:00', 'R1')],
    'HC-06' => ['HC-06', fn () => evaluatorBuilder()
        ->group(States::group('A', subject: 'LAB'))->subject(States::subject('LAB', theory: 0, practice: 2, lab: true))->room(States::room('R1'))
        ->session(new StateSession('a1', 'A', 1, Minutes::of('07:00'), Minutes::of('09:00'), 'R1', SessionKind::Practica))],
    'HC-07' => ['HC-07', fn () => evaluatorBuilder()
        ->group(States::group('A', capacity: 35))->quota(new StateQuota('A', 'ISC', 40, true))],
    'HC-08' => ['HC-08', fn () => evaluatorBuilder()
        ->group(States::group('A', teacher: 'T1', subject: 'S5'))->subject(States::subject('S5'))->teacher(States::teacher('T1', base: 2))],
    'HC-09' => ['HC-09', fn () => evaluatorBuilder()
        ->group(States::group('A', teacher: 'T1'))->teacher(States::teacher('T1', unavailable: [[1, 420, 540]]))
        ->session('A', 1, '08:00', '09:00')],
    'HC-10' => ['HC-10', fn () => evaluatorBuilder()
        ->group(States::group('A', department: 'D1'))->room(States::room('R1', department: 'D2'))
        ->session('A', 1, '07:00', '09:00', 'R1')],
    'HC-11' => ['HC-11', fn () => evaluatorBuilder()
        ->group(States::group('A'))->room(States::room('R1'))->block(new StateRoomBlock('R1', 1, 420, 480, 'Mantenimiento'))
        ->session('A', 1, '07:00', '09:00', 'R1')],
    'HC-12' => ['HC-12', fn () => evaluatorBuilder()
        ->group(States::group('A', subject: 'S5'))->subject(States::subject('S5'))
        ->session('A', 1, '07:00', '09:00')],
    'HC-13' => ['HC-13', fn () => evaluatorBuilder()
        ->group(States::group('A', subject: 'OLD'))->subject(States::subject('OLD', active: false))],
]);

it('cada regla dura da su choque en memoria, sin base y sin contenedor', function (string $rule, Closure $scenario) {
    $state = $scenario()->build();

    expect(evaluatorRules((new HardRuleEvaluator)->evaluate($state, ValidationScope::groups('A'))->violations()))->toBe([$rule]);
})->with('one scenario per hard rule');

it('corre de HC-01 a HC-13 y deja fuera el aviso de traslado (SC-09), que es blando', function () {
    $state = evaluatorBuilder()
        ->group(States::group('A', teacher: 'T1', campus: 'C1'))->group(States::group('B', teacher: 'T1', semester: 2))
        ->teacher(States::teacher('T1'))
        ->session('A', 2, '07:00', '09:00')->session('B', 2, '09:00', '10:00')
        ->build();

    expect((new HardRuleEvaluator)->rules())->toBe(['HC-01', 'HC-02', 'HC-03', 'HC-04', 'HC-05', 'HC-06', 'HC-07', 'HC-08', 'HC-09', 'HC-10', 'HC-11', 'HC-12', 'HC-13'])
        ->and(evaluatorRules((new CampusTravel)->check($state, ValidationScope::groups('A', 'B'))))->toBe(['SC-09'])
        ->and((new HardRuleEvaluator)->evaluate($state, ValidationScope::groups('A', 'B'))->isEmpty())->toBeTrue();
});

it('corre solo las reglas que se le piden', function () {
    $state = evaluatorBuilder()
        ->group(States::group('A', teacher: 'T1'))->group(States::group('B', teacher: 'T1', semester: 2))
        ->teacher(States::teacher('T1'))->room(States::room('R1'))
        ->session('A', 1, '07:00', '09:00', 'R1')->session('B', 1, '08:00', '10:00', 'R1')
        ->build();

    $evaluator = new HardRuleEvaluator;

    expect(evaluatorRules($evaluator->evaluate($state, ValidationScope::groups('A'))->violations()))->toBe(['HC-01', 'HC-02'])
        ->and(evaluatorRules($evaluator->evaluate($state, ValidationScope::groups('A'), ['HC-02'])->violations()))->toBe(['HC-02'])
        ->and($evaluator->evaluate($state, ValidationScope::groups('A'), [])->isEmpty())->toBeTrue();
});

it('forGroup da solo los choques del grupo y sirve para preguntar «¿y si…?» con withSession', function () {
    $state = evaluatorBuilder()
        ->group(States::group('A', teacher: 'T1'))->group(States::group('B', teacher: 'T1', semester: 2))
        ->teacher(States::teacher('T1'))
        ->session('B', 1, '08:00', '10:00')
        ->build();

    $evaluator = new HardRuleEvaluator;
    $clash = $state->withSession('A', new SessionCandidate(1, '07:00', '09:00'));
    $free = $state->withSession('A', new SessionCandidate(1, '10:00', '12:00'));

    expect($evaluator->forGroup($clash, 'A')->violations())->toHaveCount(1)
        ->and($evaluator->forGroup($clash, 'A')->violations()[0]->groupId)->toBe('A')
        ->and($evaluator->forGroup($free, 'A')->isEmpty())->toBeTrue()
        ->and($evaluator->forGroup($state, 'A')->isEmpty())->toBeTrue();
});

it('un salón de laboratorio no choca con HC-06 y el escenario de HC-06 solo choca por el tipo', function () {
    $state = evaluatorBuilder()
        ->group(States::group('A', subject: 'LAB'))->subject(States::subject('LAB', theory: 0, practice: 2, lab: true))
        ->room(States::room('R1', type: RoomType::Laboratorio))
        ->session(new StateSession('a1', 'A', 1, Minutes::of('07:00'), Minutes::of('09:00'), 'R1', SessionKind::Practica))
        ->build();

    expect((new HardRuleEvaluator)->evaluate($state, ValidationScope::groups('A'))->isEmpty())->toBeTrue();
});
