<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\AddAdscription;
use App\Modules\Faculty\Actions\CreateTeacher;
use App\Modules\Faculty\Actions\SetTeacherActive;
use App\Modules\Faculty\Actions\UpdateTeacher;
use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\TeacherCreated;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Actions de Docentes (spec 4.2 y 4.3). El número de empleado va sin espacios y es único también contra los
| borrados; dar de alta deja la adscripción principal, el usuario (SyncTeacherAccess) y la bitácora; editar sin
| cambios no escribe; la baja desactiva su usuario y reactivarlo lo devuelve; adscribir a un docente que ya existe
| (S9, supuesto por confirmar) agrega la adscripción sin tocar sus datos y restaura una que se había quitado.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function newTeacher(array $overrides = [], ?Department $department = null, ?Campus $campus = null): Teacher
{
    return app(CreateTeacher::class)->handle([
        'employee_number' => '1234567',
        'name' => 'Ana Pérez Soto',
        'email' => 'ana.perez@example.test',
        'degree' => 'maestria',
        'hired_on' => '2015-08-17',
        ...$overrides,
    ], $department ?? test()->sc, $campus ?? test()->c2);
}

/**
 * Los errores de validación que lanza `$action`, o falla si no lanza.
 *
 * @return array<string, list<string>>
 */
function teacherErrorsOf(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Debió rechazar con un error de validación.');
}

describe('alta', function () {
    it('da de alta un docente activo con el número sin espacios, su adscripción principal y su usuario', function () {
        $teacher = newTeacher(['employee_number' => " 123\u{00A0}4567 "]);
        $teacher = $teacher->fresh(['adscriptions', 'user']);

        expect($teacher)
            ->employee_number->toBe('1234567')
            ->name->toBe('Ana Pérez Soto')
            ->email->toBe('ana.perez@example.test')
            ->degree->toBe(TeacherDegree::Maestria)
            ->hired_on->toDateString()->toBe('2015-08-17')
            ->is_active->toBeTrue()
            ->and($teacher->adscriptions)->toHaveCount(1)
            ->and($teacher->adscriptions->sole())
            ->department_id->toBe($this->sc->id)
            ->campus_id->toBe($this->c2->id)
            ->is_primary->toBeTrue()
            ->and($teacher->user)->not->toBeNull()
            ->and($teacher->user->username)->toBe('1234567')
            ->and($teacher->user->hasRole(Role::Docente->value))->toBeTrue();
    });

    it('acepta un docente sin correo, grado ni fecha de ingreso', function () {
        $teacher = newTeacher(['email' => '', 'degree' => null, 'hired_on' => ''])->fresh();

        expect($teacher)->email->toBeNull()->degree->toBeNull()->hired_on->toBeNull();
    });

    it('deja en la bitácora el alta del docente, de su adscripción y de su usuario, y avisa con TeacherCreated', function () {
        Event::fake([TeacherCreated::class, AdscriptionChanged::class]);
        $this->actingAs($admin = User::factory()->admin()->create());

        $teacher = newTeacher();
        $created = Activity::query()->where('subject_type', $teacher->getMorphClass())->where('event', 'created')->sole();

        expect($created->description)->toBe('Dio de alta al docente 1234567')
            ->and($created->causer->is($admin))->toBeTrue()
            ->and($created->attribute_changes->toArray())->toBe(['attributes' => [
                'employee_number' => '1234567',
                'name' => 'Ana Pérez Soto',
                'email' => 'ana.perez@example.test',
                'degree' => 'maestria',
                'hired_on' => '2015-08-17',
                'is_active' => true,
            ]])
            ->and(Activity::query()->where('subject_type', (new TeacherDepartment)->getMorphClass())->sole()->description)
            ->toBe('Adscribió al docente 1234567 a SC en C2 (principal)')
            ->and(Activity::query()->where('description', 'Dio de alta al usuario 1234567')->exists())->toBeTrue();
        Event::assertDispatched(TeacherCreated::class, fn (TeacherCreated $event): bool => $event->teacher->is($teacher));
        Event::assertDispatched(AdscriptionChanged::class);
    });

    it('rechaza un número repetido con un error en employee_number, también contra un borrado', function (bool $trashed) {
        $existing = Teacher::factory()->create(['employee_number' => '1234567']);

        if ($trashed) {
            $existing->delete();
        }

        expect(teacherErrorsOf(fn () => newTeacher(['employee_number' => '123 4567']))['employee_number'][0])
            ->toBe('Ya existe un docente con el número de empleado 1234567, aunque esté inactivo o borrado.')
            ->and(Teacher::withTrashed()->count())->toBe(1)
            ->and(TeacherDepartment::withTrashed()->count())->toBe(0);
    })->with(['activo' => false, 'borrado' => true]);

    it('si otra sesión guarda el mismo número entre la revisión y el alta, responde con el mismo error', function () {
        Teacher::creating(function (Teacher $teacher): void {
            DB::table('teachers')->insert(['employee_number' => $teacher->employee_number, 'name' => 'Otra sesión', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        });

        expect(teacherErrorsOf(fn () => newTeacher())['employee_number'][0])->toContain('Ya existe un docente con el número de empleado 1234567');
    });

    it('rechaza un número o un nombre que quedan vacíos', function (string $field, string $value, string $message) {
        expect(teacherErrorsOf(fn () => newTeacher([$field => $value]))[$field][0])->toBe($message)
            ->and(Teacher::count())->toBe(0);
    })->with([
        'número' => ['employee_number', "\u{00A0} ", 'El número de empleado no puede quedar vacío ni ser solo espacios.'],
        'nombre' => ['name', '   ', 'El nombre no puede quedar vacío.'],
    ]);

    it('rechaza un grado que no es de TeacherDegree', function () {
        expect(teacherErrorsOf(fn () => newTeacher(['degree' => 'bachillerato'])))->toBe(['degree' => ['El grado no es válido.']]);
    });

    it('rechaza un departamento borrado sin escribir nada', function () {
        $this->sc->delete();

        expect(teacherErrorsOf(fn () => newTeacher()))->toBe(['department_id' => ['El departamento no existe o está borrado.']])
            ->and(Teacher::count())->toBe(0);
    });

    it('si no puede crear el usuario por falta de NEXUS_DEFAULT_PASSWORD, no da de alta nada', function () {
        config(['auth.default_password' => null]);

        expect(fn () => newTeacher())->toThrow(RuntimeException::class, 'NEXUS_DEFAULT_PASSWORD')
            ->and(Teacher::count())->toBe(0)
            ->and(TeacherDepartment::count())->toBe(0)
            ->and(User::count())->toBe(0);
    });

    it('un evento que se revierte con la transacción no avisa', function () {
        Event::fake([TeacherCreated::class]);

        DB::beginTransaction();
        newTeacher();
        DB::rollBack();

        Event::assertNotDispatched(TeacherCreated::class);
    });
});

describe('edición', function () {
    beforeEach(function () {
        $this->teacher = Teacher::factory()->adscribedTo($this->sc, $this->c2)->create([
            'employee_number' => '7654321',
            'name' => 'Luis Gómez',
            'email' => null,
            'degree' => TeacherDegree::Licenciatura,
            'hired_on' => '2010-01-04',
        ]);
    });

    it('cambia nombre, correo, grado y fecha de ingreso, deja la bitácora con el valor anterior y avisa con TeacherUpdated', function () {
        Event::fake([TeacherUpdated::class]);

        $teacher = app(UpdateTeacher::class)->handle($this->teacher, [
            'name' => ' Luis Gómez Ruiz ',
            'email' => 'luis@example.test',
            'degree' => 'doctorado',
            'hired_on' => '2010-01-04',
        ]);

        expect($teacher->fresh())
            ->name->toBe('Luis Gómez Ruiz')
            ->email->toBe('luis@example.test')
            ->degree->toBe(TeacherDegree::Doctorado)
            ->and(Activity::sole())
            ->event->toBe('updated')
            ->description->toBe('Actualizó al docente 7654321')
            ->and(Activity::sole()->attribute_changes->toArray())->toBe([
                'attributes' => ['name' => 'Luis Gómez Ruiz', 'email' => 'luis@example.test', 'degree' => 'doctorado'],
                'old' => ['name' => 'Luis Gómez', 'email' => null, 'degree' => 'licenciatura'],
            ]);
        Event::assertDispatched(TeacherUpdated::class, fn (TeacherUpdated $event): bool => $event->changed === ['name', 'email', 'degree']);
    });

    it('sin cambios no escribe, no registra ni avisa', function () {
        Event::fake([TeacherUpdated::class]);
        $updatedAt = $this->teacher->fresh()->updated_at;

        app(UpdateTeacher::class)->handle($this->teacher, ['name' => 'Luis Gómez', 'email' => '', 'degree' => 'licenciatura', 'hired_on' => '2010-01-04']);

        expect(Activity::count())->toBe(0)
            ->and($this->teacher->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
        Event::assertNotDispatched(TeacherUpdated::class);
    });

    it('no cambia el número de empleado ni el estado: el número es su usuario y la baja es SetTeacherActive', function () {
        app(UpdateTeacher::class)->handle($this->teacher, ['employee_number' => '9999999', 'is_active' => false]);

        expect($this->teacher->fresh())->employee_number->toBe('7654321')->is_active->toBeTrue()
            ->and(Activity::count())->toBe(0);
    });

    it('toma el valor anterior de la base con bloqueo, aunque el modelo llegue viejo', function () {
        $stale = Teacher::find($this->teacher->id);
        DB::table('teachers')->where('id', $this->teacher->id)->update(['name' => 'Nombre de otra sesión']);

        app(UpdateTeacher::class)->handle($stale, ['name' => 'Luis Gómez']);

        expect(Activity::sole()->attribute_changes->toArray()['old'])->toBe(['name' => 'Nombre de otra sesión']);
    });
});

describe('baja y reactivación', function () {
    beforeEach(function () {
        $this->teacher = newTeacher();
        // La bitácora es inmutable: se cuenta desde aquí.
        $this->logged = (int) Activity::max('id');
    });

    it('desactivar al docente desactiva su usuario; reactivarlo lo devuelve', function () {
        Event::fake([TeacherUpdated::class]);

        $teacher = app(SetTeacherActive::class)->handle($this->teacher, false);
        $entry = Activity::query()->where('id', '>', $this->logged)->where('subject_type', $teacher->getMorphClass())->sole();

        expect($teacher->fresh()->is_active)->toBeFalse()
            ->and($teacher->fresh()->user->is_active)->toBeFalse()
            ->and($entry->description)->toBe('Dio de baja al docente 1234567')
            ->and($entry->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => false], 'old' => ['is_active' => true]]);

        $teacher = app(SetTeacherActive::class)->handle($teacher, true);

        expect($teacher->fresh()->is_active)->toBeTrue()
            ->and($teacher->fresh()->user->is_active)->toBeTrue()
            ->and(Activity::query()->where('id', '>', $this->logged)->where('subject_type', $teacher->getMorphClass())->latest('id')->first()->description)
            ->toBe('Reactivó al docente 1234567');
        Event::assertDispatchedTimes(TeacherUpdated::class, 2);
    });

    it('sin cambio de estado no escribe ni avisa', function () {
        Event::fake([TeacherUpdated::class]);

        app(SetTeacherActive::class)->handle($this->teacher, true);

        expect(Activity::query()->where('id', '>', $this->logged)->count())->toBe(0);
        Event::assertNotDispatched(TeacherUpdated::class);
    });
});

describe('adscripción de un docente que ya existe (S9)', function () {
    beforeEach(function () {
        $this->teacher = Teacher::factory()->adscribedTo($this->ii, $this->c1)->create([
            'employee_number' => '5555555',
            'name' => 'Rosa Díaz',
            'email' => 'rosa@example.test',
        ]);
    });

    it('agrega la adscripción, que no es la principal, sin tocar sus datos, y le abre el campus en su usuario', function () {
        Event::fake([AdscriptionChanged::class]);
        $before = $this->teacher->fresh()->only(['employee_number', 'name', 'email', 'degree', 'is_active']);

        $adscription = app(AddAdscription::class)->handle($this->teacher, $this->sc, $this->c2);

        expect($adscription)
            ->teacher_id->toBe($this->teacher->id)
            ->department_id->toBe($this->sc->id)
            ->campus_id->toBe($this->c2->id)
            ->is_primary->toBeFalse()
            ->and($this->teacher->fresh()->only(['employee_number', 'name', 'email', 'degree', 'is_active']))->toBe($before)
            ->and($this->teacher->adscriptions()->count())->toBe(2)
            ->and($this->teacher->fresh()->user->roleScopes()->where('role', Role::Docente)->pluck('campus_id')->sort()->values()->all())
            ->toBe(collect([$this->c1->id, $this->c2->id])->sort()->values()->all())
            ->and(Activity::query()->where('subject_type', $adscription->getMorphClass())->sole()->description)
            ->toBe('Adscribió al docente 5555555 a SC en C2');
        Event::assertDispatched(AdscriptionChanged::class, fn (AdscriptionChanged $event): bool => $event->adscription->is($adscription));
    });

    it('restaura una adscripción que se había quitado', function () {
        $removed = TeacherDepartment::factory()->for($this->teacher)->for($this->sc)->for($this->c2)->create(['is_primary' => false]);
        $removed->delete();

        $adscription = app(AddAdscription::class)->handle($this->teacher, $this->sc, $this->c2);

        expect($adscription->id)->toBe($removed->id)
            ->and($adscription->fresh()->trashed())->toBeFalse()
            ->and(TeacherDepartment::withTrashed()->where('teacher_id', $this->teacher->id)->count())->toBe(2)
            ->and(Activity::query()->where('subject_type', $adscription->getMorphClass())->sole())
            ->event->toBe('restored')
            ->description->toBe('Volvió a adscribir al docente 5555555 a SC en C2');
    });

    it('rechaza una adscripción que ya tiene', function () {
        expect(teacherErrorsOf(fn () => app(AddAdscription::class)->handle($this->teacher, $this->ii, $this->c1)))
            ->toBe(['adscription' => ['Rosa Díaz ya está adscrito a II en C1.']])
            ->and(TeacherDepartment::withTrashed()->count())->toBe(1);
    });

    it('rechaza una segunda principal: cambiarla es a mano', function () {
        expect(teacherErrorsOf(fn () => app(AddAdscription::class)->handle($this->teacher, $this->sc, $this->c2, primary: true)))
            ->toBe(['adscription' => ['Rosa Díaz ya tiene una adscripción principal.']]);
    });

    it('la primera adscripción de un docente sin principal es la principal', function () {
        $loose = Teacher::factory()->create();

        expect(app(AddAdscription::class)->handle($loose, $this->sc, $this->c2)->is_primary)->toBeTrue();
    });
});
