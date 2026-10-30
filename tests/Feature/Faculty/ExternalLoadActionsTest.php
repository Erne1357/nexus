<?php

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Actions\AddExternalLoad;
use App\Modules\Faculty\Actions\RemoveExternalLoad;
use App\Modules\Faculty\Actions\UpdateExternalLoad;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Horas fuera del ITCJ de un docente en un periodo (S16, supuesto por confirmar): de 1 a 40 y una fila por docente,
| periodo y origen. Alta, cambio y baja dejan bitácora con el valor anterior y avisan tras el commit; poner de nuevo
| un origen quitado lo restaura.
*/

beforeEach(function () {
    $this->teacher = Teacher::factory()->create(['employee_number' => '1000001']);
    $this->term = Term::factory()->current()->create();
    $this->activityBase = (int) Activity::max('id');
});

/**
 * @return array<string, list<string>>
 */
function loadErrorsOf(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Debió rechazar con un error de validación.');
}

it('S16: agrega horas de un origen en el periodo, con bitácora y aviso', function () {
    Event::fake([ExternalLoadChanged::class]);

    $load = app(AddExternalLoad::class)->handle($this->teacher, $this->term, ['source' => '  TecNM Virtual ', 'hours' => '12', 'notes' => 'Dos grupos']);

    expect($load)->source->toBe('TecNM Virtual')->hours->toBe(12)->term_id->toBe($this->term->id)->notes->toBe('Dos grupos');

    $log = Activity::where('id', '>', $this->activityBase)->sole();

    expect($log->event)->toBe('created')
        ->and($log->attribute_changes['attributes'])->toMatchArray(['source' => 'TecNM Virtual', 'hours' => 12])
        ->and($log->properties['teacher_id'])->toBe($this->teacher->id);
    Event::assertDispatched(ExternalLoadChanged::class);
});

it('S16: las horas van de 1 a 40 y el origen es obligatorio', function (mixed $hours, string $field) {
    $errors = loadErrorsOf(fn () => app(AddExternalLoad::class)->handle($this->teacher, $this->term, ['source' => $field === 'source' ? ' ' : 'TecNM Virtual', 'hours' => $hours]));

    expect($errors)->toHaveKey($field)
        ->and(TeacherExternalLoad::count())->toBe(0);
})->with([
    'cero' => [0, 'hours'],
    'cuarenta y uno' => [41, 'hours'],
    'no es número' => ['mucho', 'hours'],
    'sin origen' => [5, 'source'],
]);

it('S16: acepta 1 y 40', function (int $hours) {
    expect(app(AddExternalLoad::class)->handle($this->teacher, $this->term, ['source' => "Origen {$hours}", 'hours' => $hours])->hours)->toBe($hours);
})->with([1, 40]);

it('S16: un origen se repite solo en otro periodo u otro docente', function () {
    app(AddExternalLoad::class)->handle($this->teacher, $this->term, ['source' => 'TecNM Virtual', 'hours' => 5]);

    expect(loadErrorsOf(fn () => app(AddExternalLoad::class)->handle($this->teacher, $this->term, ['source' => 'tecnm virtual', 'hours' => 6])))->toHaveKey('source');

    app(AddExternalLoad::class)->handle($this->teacher, Term::factory()->create(), ['source' => 'TecNM Virtual', 'hours' => 5]);
    app(AddExternalLoad::class)->handle(Teacher::factory()->create(), $this->term, ['source' => 'TecNM Virtual', 'hours' => 5]);

    expect(TeacherExternalLoad::count())->toBe(3);
});

it('cambia horas, origen y notas con el valor anterior en la bitácora', function () {
    $load = app(AddExternalLoad::class)->handle($this->teacher, $this->term, ['source' => 'TecNM Virtual', 'hours' => 5]);
    $base = (int) Activity::max('id');

    app(UpdateExternalLoad::class)->handle($load, ['source' => 'TecNM Virtual', 'hours' => 8, 'notes' => 'Subió']);

    $log = Activity::where('id', '>', $base)->sole();

    expect($load->fresh())->hours->toBe(8)->notes->toBe('Subió')
        ->and($log->event)->toBe('updated')
        ->and($log->attribute_changes['old'])->toMatchArray(['hours' => 5])
        ->and($log->attribute_changes['attributes'])->toMatchArray(['hours' => 8]);
});

it('cambiar el origen a uno que ya tiene el periodo es un error, y sin cambios no escribe', function () {
    $a = app(AddExternalLoad::class)->handle($this->teacher, $this->term, ['source' => 'A', 'hours' => 5]);
    app(AddExternalLoad::class)->handle($this->teacher, $this->term, ['source' => 'B', 'hours' => 5]);
    $base = (int) Activity::max('id');

    expect(loadErrorsOf(fn () => app(UpdateExternalLoad::class)->handle($a, ['source' => 'B', 'hours' => 5])))->toHaveKey('source');

    app(UpdateExternalLoad::class)->handle($a, ['source' => 'A', 'hours' => 5]);

    expect(Activity::where('id', '>', $base)->count())->toBe(0);
});

it('quita con borrado lógico y bitácora; ponerlo de nuevo lo restaura', function () {
    $load = app(AddExternalLoad::class)->handle($this->teacher, $this->term, ['source' => 'TecNM Virtual', 'hours' => 5]);
    $base = (int) Activity::max('id');

    app(RemoveExternalLoad::class)->handle($load);

    expect($load->fresh()->trashed())->toBeTrue()
        ->and(Activity::where('id', '>', $base)->sole()->event)->toBe('deleted');

    $restored = app(AddExternalLoad::class)->handle($this->teacher, $this->term, ['source' => 'TecNM Virtual', 'hours' => 9]);

    expect($restored->id)->toBe($load->id)->and($restored->trashed())->toBeFalse()->and($restored->hours)->toBe(9)
        ->and(TeacherExternalLoad::withTrashed()->count())->toBe(1);
});
