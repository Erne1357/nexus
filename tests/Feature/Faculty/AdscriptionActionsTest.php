<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Faculty\Actions\AddAdscription;
use App\Modules\Faculty\Actions\CreateTeacher;
use App\Modules\Faculty\Actions\RemoveAdscription;
use App\Modules\Faculty\Actions\SetPrimaryAdscription;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Quitar y cambiar la principal de una adscripción (S8, supuesto por confirmar): no se quita la única (para eso
| está la baja) ni la principal (primero se elige otra); cambiar la principal deja una sola; quitar la única de un
| campus cierra el alcance de `docente` en ese campus (S14). Todo deja bitácora y avisa tras el commit.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->teacher = app(CreateTeacher::class)->handle(['employee_number' => '1234567', 'name' => 'Ana Pérez Soto'], $this->sc, $this->c2);
    $this->second = app(AddAdscription::class)->handle($this->teacher, $this->ii, $this->c2);
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

describe('quitar', function () {
    it('quita una adscripción que no es la principal, deja bitácora y avisa tras el commit', function () {
        Event::fake([AdscriptionChanged::class]);

        app(RemoveAdscription::class)->handle($this->second);

        $activity = activitySince()->sole();

        expect($this->second->fresh()->trashed())->toBeTrue()
            ->and(TeacherDepartment::withTrashed()->find($this->second->id)->trashed())->toBeTrue()
            ->and($activity->event)->toBe('deleted')
            ->and($activity->subject_id)->toBe($this->second->id)
            ->and($activity->attribute_changes['old'])->toMatchArray(['department_id' => $this->ii->id, 'campus_id' => $this->c2->id, 'is_primary' => false]);
        Event::assertDispatched(AdscriptionChanged::class);
    });

    it('S8: rechaza quitar la principal', function () {
        $errors = adscriptionErrorsOf(fn () => app(RemoveAdscription::class)->handle($this->first));

        expect($errors['adscription'][0])->toContain('principal')
            ->and($this->first->fresh()->trashed())->toBeFalse()
            ->and(activitySince()->count())->toBe(0);
    });

    it('S8: rechaza quitar la única, aunque ya no sea la principal en los datos', function () {
        $this->second->forceDelete();

        $errors = adscriptionErrorsOf(fn () => app(RemoveAdscription::class)->handle($this->first));

        expect($errors['adscription'][0])->toContain('única')
            ->and($this->first->fresh()->trashed())->toBeFalse();
    });

    it('rechaza quitar una que ya se quitó', function () {
        app(RemoveAdscription::class)->handle($this->second);

        $errors = adscriptionErrorsOf(fn () => app(RemoveAdscription::class)->handle($this->second));

        expect($errors)->toHaveKey('adscription');
    });

    it('S14: quitar la única de un campus cierra el alcance de docente en ese campus y deja el otro', function () {
        $other = app(AddAdscription::class)->handle($this->teacher, $this->sc, $this->c1);
        $user = $this->teacher->fresh()->user;
        $scopesIn = fn (Campus $campus) => RoleScope::query()->where('user_id', $user->id)->where('role', Role::Docente)->where('campus_id', $campus->id)->count();

        expect($scopesIn($this->c1))->toBe(1);

        app(RemoveAdscription::class)->handle($other);

        expect($scopesIn($this->c1))->toBe(0)
            ->and($scopesIn($this->c2))->toBe(1);
    });
});

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
        app(RemoveAdscription::class)->handle($this->second);

        $errors = adscriptionErrorsOf(fn () => app(SetPrimaryAdscription::class)->handle($this->second));

        expect($errors)->toHaveKey('adscription');
    });
});
