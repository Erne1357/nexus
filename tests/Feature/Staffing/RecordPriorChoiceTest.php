<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\RecordPriorChoice;
use App\Modules\Staffing\Actions\RemovePriorChoice;
use App\Modules\Staffing\Actions\Selection\RecomputeSelectionOrder;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\PriorChoice;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\SelectionOrderQuery;
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

it('SEL-16: con la selección publicada o en curso rechaza y no escribe nada', function (SelectionStatus $status) {
    $this->world->selection->update(['status' => $status]);
    $a = $this->world->group('A', [[1, '09:00', '10:00']]);
    $logs = Activity::query()->count();

    expect(fn () => app(RecordPriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a]), $this->world->admin))
        ->toThrow(ValidationException::class, 'no se cambia una elección previa');

    expect($a->fresh()->teacher_id)->toBeNull()
        ->and(PriorChoice::query()->count())->toBe(0)
        ->and(GroupTeacherAssignment::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe($logs);
})->with([SelectionStatus::Published, SelectionStatus::Running, SelectionStatus::ExtraRound, SelectionStatus::Finished]);

it('SEL-16: una selección cancelada no estorba', function () {
    $this->world->selection->update(['status' => SelectionStatus::Cancelled]);
    $a = $this->world->group('A', [[1, '09:00', '10:00']]);

    app(RecordPriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a]), $this->world->admin);

    expect($a->fresh()->teacher_id)->toBe($this->teacher->id);
});

it('SEL-16: con la selección en borrador recalcula la cola y la vista previa ya lo muestra como elección previa', function () {
    $otro = $this->world->teacher(name: 'Docente Otro', position: 2);
    $a = $this->world->group('A', [[1, '09:00', '10:00']]);

    app(RecordPriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a]), $this->world->admin);

    expect($this->world->entryOf($this->teacher))->status->toBe(SelectionEntryStatus::Done)->prior_choice_at->not->toBeNull()
        ->and($this->world->entryOf($otro))->prior_choice_at->toBeNull();

    $rows = collect(app(SelectionOrderQuery::class)->forSelection($this->world->selection))->keyBy('name');

    expect($rows['Docente Previo'])->prior->toBeTrue()->status->toBe('prior_choice');
});

it('SEL-16: solo acepta cargas en borrador, propuesta, asignación, ajustes o re-análisis; sin carga o en otro estado rechaza', function (LoadStatus $status, bool $accepted) {
    $this->world->load->update(['status' => $status]);
    $a = $this->world->group('A', [[1, '09:00', '10:00']]);
    $action = fn () => app(RecordPriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a]), $this->world->admin);

    if ($accepted) {
        $action();
        expect($a->fresh()->teacher_id)->toBe($this->teacher->id);
    } else {
        expect($action)->toThrow(ValidationException::class, 'elección previa');
        expect($a->fresh()->teacher_id)->toBeNull();
    }
})->with([
    [LoadStatus::Draft, true], [LoadStatus::Proposed, true], [LoadStatus::Staffing, true], [LoadStatus::Adjusting, true], [LoadStatus::Reanalysis, true],
    [LoadStatus::Published, false],
]);

it('SEL-16: un grupo sin carga de su departamento se rechaza', function () {
    $department = Department::factory()->create();
    $a = Group::factory()->create([
        'term_id' => $this->world->term->id, 'campus_id' => $this->world->campus->id, 'modality_id' => $this->modality->id, 'code' => 'A',
        'subject_id' => Subject::factory()->create(['department_id' => $department->id])->id, 'department_id' => $department->id,
    ]);

    expect(fn () => app(RecordPriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a]), $this->world->admin))
        ->toThrow(ValidationException::class, 'no tiene carga');
});

it('SEL-16: la marca aplica solo en las selecciones de los departamentos de sus grupos (un docente de dos departamentos conserva su turno en el otro)', function () {
    $other = SelectionWorld::create(SelectionStatus::Draft);
    // Misma persona adscrita también al departamento del otro mundo; el otro mundo es de otro periodo, así que se junta a mano.
    $other->selection->update(['term_id' => $this->world->term->id, 'modality_id' => $this->modality->id]);
    $other->load->update(['term_id' => $this->world->term->id, 'modality_id' => $this->modality->id]);
    TeacherDepartment::factory()->create(['teacher_id' => $this->teacher->id, 'department_id' => $other->department->id, 'is_primary' => false]);
    SelectionEntry::factory()->for($other->selection)->create(['teacher_id' => $this->teacher->id, 'base_hours' => 20, 'position' => 1, 'slot_index' => 0, 'status' => SelectionEntryStatus::Waiting]);
    $a = $this->world->group('A', [[1, '09:00', '10:00']]);

    $mark = app(RecordPriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a]), $this->world->admin);
    app(RecomputeSelectionOrder::class)->handle($other->selection->fresh());

    expect($mark->fresh()->department_ids)->toBe([$this->world->department->id])
        ->and($this->world->entryOf($this->teacher)->prior_choice_at)->not->toBeNull()
        ->and(SelectionEntry::query()->where('selection_id', $other->selection->id)->where('teacher_id', $this->teacher->id)->sole())
        ->prior_choice_at->toBeNull()->status->toBe(SelectionEntryStatus::Waiting);
});

it('SEL-16: RemovePriorChoice quita la marca, regresa al docente a la fila y no desasigna sus grupos', function () {
    $a = $this->world->group('A', [[1, '09:00', '10:00']]);
    app(RecordPriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a]), $this->world->admin);
    expect($this->world->entryOf($this->teacher)->prior_choice_at)->not->toBeNull();

    $removed = app(RemovePriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, $this->world->admin);

    expect($removed)->toBeTrue()
        ->and(PriorChoice::query()->count())->toBe(0)
        ->and($this->world->entryOf($this->teacher))->prior_choice_at->toBeNull()->status->toBe(SelectionEntryStatus::Waiting)->position->toBeGreaterThan(0)
        ->and($a->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and(Activity::query()->where('event', 'prior_choice_removed')->count())->toBe(1)
        ->and(app(RemovePriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, $this->world->admin))->toBeFalse();
});

it('SEL-16: RemovePriorChoice rechaza con una selección del periodo publicada', function () {
    $a = $this->world->group('A', [[1, '09:00', '10:00']]);
    app(RecordPriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, priorGroups([$a]), $this->world->admin);
    $this->world->selection->update(['status' => SelectionStatus::Published]);

    expect(fn () => app(RemovePriorChoice::class)->handle($this->world->term, $this->modality, $this->teacher, $this->world->admin))
        ->toThrow(ValidationException::class, 'no se cambia una elección previa');

    expect(PriorChoice::query()->count())->toBe(1);
});
