<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Staffing\Actions\RecordPriorChoice;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\PriorChoice;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Staffing\SelectionWorld;

/*
| RecordPriorChoice (tarea 1.41, SEL-16): asigna al docente los grupos que ya trae escritos y deja su marca de «Elección
| previa». HC-01 duro, el grupo debe estar libre o ser suyo, idempotente y funciona con la carga en borrador.
*/

function priorGroups(array $groups): array
{
    return array_map(fn ($group): array => ['group' => $group], $groups);
}

beforeEach(function () {
    $this->world = SelectionWorld::create(SelectionStatus::Draft);
    $this->modality = $this->world->selection->modality;
    $this->teacher = $this->world->teacher(name: 'Docente Previo');
});

it('SEL-16: asigna sus grupos con la razón de elección previa, deja la marca y la bitácora, con la carga en borrador', function () {
    $this->world->load->update(['status' => LoadStatus::Draft]);
    $a = $this->world->group('A', [[1, '09:00', '10:00']]);
    $b = $this->world->group('B', [[2, '09:00', '10:00']]);

    $mark = app(RecordPriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a, $b]), $this->world->admin);

    expect($a->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and($b->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and(GroupTeacherAssignment::query()->where('teacher_id', $this->teacher->id)->pluck('reason')->unique()->all())->toBe(['Elección previa a la orden de selección'])
        ->and($mark)->recorded_by->toBe($this->world->admin->id)->teacher_id->toBe($this->teacher->id)
        ->and(Activity::query()->where('event', 'prior_choice_recorded')->count())->toBe(1);
});

it('SEL-16: repetir no duplica asignaciones, marca ni bitácora', function () {
    $a = $this->world->group('A');
    $action = app(RecordPriorChoice::class);

    $action->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a]), $this->world->admin);
    $assignments = GroupTeacherAssignment::query()->count();
    $logs = Activity::query()->count();
    $action->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a->fresh()]), $this->world->admin);

    expect(GroupTeacherAssignment::query()->count())->toBe($assignments)
        ->and(Activity::query()->count())->toBe($logs)
        ->and(PriorChoice::query()->count())->toBe(1);
});

it('HC-01: rechaza un grupo que se empalma con otro del docente y no deja nada a medias', function () {
    $a = $this->world->group('A', [[1, '09:00', '10:00']]);
    $b = $this->world->group('B', [[1, '09:30', '10:30']]);

    expect(fn () => app(RecordPriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a, $b]), $this->world->admin))
        ->toThrow(ValidationException::class, 'HC-01');

    expect($a->fresh()->teacher_id)->toBeNull()
        ->and(PriorChoice::query()->count())->toBe(0);
});

it('rechaza un grupo que ya es de otro docente', function () {
    $other = $this->world->teacher(name: 'Docente Otro', position: 2);
    $a = $this->world->group('A', [[1, '09:00', '10:00']], attributes: ['teacher_id' => $other->id]);

    expect(fn () => app(RecordPriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a]), $this->world->admin))
        ->toThrow(ValidationException::class, 'otro docente');

    expect($a->fresh()->teacher_id)->toBe($other->id);
});

it('rechaza un grupo de otro periodo', function () {
    $other = SelectionWorld::create(SelectionStatus::Draft);
    $foreign = $other->group('Z');

    expect(fn () => app(RecordPriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$foreign]), $this->world->admin))
        ->toThrow(ValidationException::class, 'periodo y modalidad');
});
