<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Faculty\Actions\AddAdscription;
use App\Modules\Faculty\Actions\CreateTeacher;
use App\Modules\Faculty\Actions\SetPrimaryAdscription;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Cambiar la principal de un docente (SetPrimaryAdscription): deja una sola principal, registra el valor anterior,
| no escribe si ya lo es y rechaza una adscripción quitada. Quitar adscripciones es RemoveAdscription (RemoveAdscriptionTest).
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->teacher = app(CreateTeacher::class)->handle(['employee_number' => '1234567', 'name' => 'Ana Pérez Soto'], $this->sc);
    $this->second = app(AddAdscription::class)->handle($this->teacher, $this->ii);
    $this->first = $this->teacher->adscriptions()->where('is_primary', true)->sole();
    $this->activityBase = (int) Activity::max('id');
});

/**
 * @return array<string, list<string>>
 */
function adscriptionErrorsOf(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Debió rechazar con un error de validación.');
}

describe('principal', function () {
    it('cambiar la principal deja una sola y registra el valor anterior', function () {
        Event::fake([AdscriptionChanged::class]);

        app(SetPrimaryAdscription::class)->handle($this->second);

        $primaries = TeacherDepartment::query()->where('teacher_id', $this->teacher->id)->where('is_primary', true)->get();

        expect($primaries)->toHaveCount(1)
            ->and($primaries->sole()->id)->toBe($this->second->id)
            ->and($this->first->fresh()->is_primary)->toBeFalse()
            ->and(activitySince()->count())->toBe(2)
            ->and(activitySince()->where('subject_id', $this->second->id)->sole()->attribute_changes['old'])->toMatchArray(['is_primary' => false]);
        Event::assertDispatched(AdscriptionChanged::class);
    });

    it('si ya es la principal no escribe, no registra ni avisa', function () {
        Event::fake([AdscriptionChanged::class]);

        app(SetPrimaryAdscription::class)->handle($this->first);

        expect($this->first->fresh()->is_primary)->toBeTrue()
            ->and(activitySince()->count())->toBe(0);
        Event::assertNotDispatched(AdscriptionChanged::class);
    });

    it('rechaza una adscripción quitada', function () {
        $this->second->delete();

        $errors = adscriptionErrorsOf(fn () => app(SetPrimaryAdscription::class)->handle($this->second));

        expect($errors)->toHaveKey('adscription');
    });
});
