<?php

use App\Modules\Academic\Actions\SetSubjectRequiredSpace;
use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use Spatie\Activitylog\Models\Activity;

/*
| SetSubjectRequiredSpace (1.33): el tipo de espacio que pide la materia se marca y queda para los periodos siguientes.
| Con laboratorio, needs_lab (HC-06) queda encendido; con aula o sala de cómputo no cambia.
*/

it('marca la materia como de laboratorio y enciende needs_lab', function () {
    $subject = Subject::factory()->create(['needs_lab' => false]);

    $result = app(SetSubjectRequiredSpace::class)->handle($subject, RequiredSpace::Laboratorio, User::factory()->create());

    expect($result->fresh())
        ->required_space->toBe(RequiredSpace::Laboratorio)
        ->needs_lab->toBeTrue();
});

it('con sala de cómputo o aula deja needs_lab como estaba', function () {
    $actor = User::factory()->create();
    $apt = Subject::factory()->create(['needs_lab' => true]);
    $other = Subject::factory()->create(['needs_lab' => false]);

    app(SetSubjectRequiredSpace::class)->handle($apt, RequiredSpace::SalaComputo, $actor);
    app(SetSubjectRequiredSpace::class)->handle($other, RequiredSpace::Aula, $actor);

    expect($apt->fresh())->required_space->toBe(RequiredSpace::SalaComputo)->needs_lab->toBeTrue()
        ->and($other->fresh())->required_space->toBe(RequiredSpace::Aula)->needs_lab->toBeFalse();
});

it('deja bitácora del cambio de tipo de espacio', function () {
    $subject = Subject::factory()->create(['needs_lab' => false]);
    $actor = User::factory()->create();

    app(SetSubjectRequiredSpace::class)->handle($subject, RequiredSpace::Laboratorio, $actor);

    $log = Activity::query()->where('subject_id', $subject->id)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($actor->id)
        ->and($log->attribute_changes['attributes'])->toMatchArray(['required_space' => 'laboratorio', 'needs_lab' => true]);
});

it('no escribe ni registra si el tipo ya era ese', function () {
    $subject = Subject::factory()->create(['required_space' => RequiredSpace::Aula]);
    $before = Activity::query()->count();

    app(SetSubjectRequiredSpace::class)->handle($subject, RequiredSpace::Aula, User::factory()->create());

    expect(Activity::query()->count())->toBe($before);
});
