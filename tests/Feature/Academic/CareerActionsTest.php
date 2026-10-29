<?php

use App\Modules\Academic\Actions\CreateCareer;
use App\Modules\Academic\Actions\DeleteCareer;
use App\Modules\Academic\Actions\SetCareerOffering;
use App\Modules\Academic\Actions\UpdateCareer;
use App\Modules\Academic\Events\CareerCreated;
use App\Modules\Academic\Events\CareerDeleted;
use App\Modules\Academic\Events\CareerOfferingChanged;
use App\Modules\Academic\Events\CareerUpdated;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Actions de Carrera. Crear y editar hacen cumplir la clave normalizada y única, también entre las
| borradas, y un departamento que existe; SetCareerOffering ofrece, deja de ofrecer y restaura la
| oferta de un campus (la de una carrera inactiva nace inactiva, como en la importación); borrar solo
| si no tiene planes, ni siquiera borrados, y cierra los alcances de sus coordinadores (supuesto S2).
*/

beforeEach(function () {
    $this->department = Department::factory()->create(['code' => 'SIS']);
    $this->career = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas', 'department_id' => $this->department->id])->fresh();
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
});

function newCareer(array $overrides = []): Career
{
    return app(CreateCareer::class)->handle(['code' => 'IIA', 'name' => 'Ingeniería en Inteligencia Artificial', 'department_id' => Department::where('code', 'SIS')->value('id'), ...$overrides]);
}

describe('CreateCareer', function () {
    it('da de alta una carrera activa con la clave en mayúsculas y sin espacios', function () {
        $career = newCareer(['code' => ' i i a ']);

        expect($career->fresh())->code->toBe('IIA')->is_active->toBeTrue()->department_id->toBe($this->department->id);
    });

    it('registra en la bitácora quién la dio de alta y con qué datos', function () {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $career = newCareer();
        $entry = Activity::sole();

        expect($entry)
            ->event->toBe('created')
            ->description->toBe('Dio de alta la carrera IIA')
            ->and($entry->subject->is($career))->toBeTrue()
            ->and($entry->causer->is($admin))->toBeTrue()
            ->and($entry->attribute_changes->toArray())->toBe([
                'attributes' => ['code' => 'IIA', 'name' => 'Ingeniería en Inteligencia Artificial', 'department_id' => $this->department->id, 'is_active' => true],
            ]);
    });

    it('avisa con CareerCreated', function () {
        Event::fake([CareerCreated::class]);

        $career = newCareer();

        Event::assertDispatched(CareerCreated::class, fn (CareerCreated $event): bool => $event->career->is($career));
    });

    it('rechaza una clave repetida con un error en code, también contra una borrada', function (bool $trashed) {
        if ($trashed) {
            $this->career->delete();
        }

        try {
            newCareer(['code' => 'isc']);
            $this->fail('Debió rechazar la clave.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['code'][0])->toContain('Ya existe una carrera con la clave ISC');
        }

        expect(Career::withTrashed()->count())->toBe(1);
    })->with(['activa' => false, 'borrada' => true]);

    it('rechaza una clave que queda vacía al normalizar', function () {
        expect(fn () => newCareer(['code' => "\u{00A0} "]))->toThrow(ValidationException::class, 'La clave no puede quedar vacía');
    });

    it('rechaza un departamento que no existe, que no es uuid o que está borrado, con el error en department_id', function (string $kind) {
        $id = match ($kind) {
            'inexistente' => (string) Str::uuid(),
            'no uuid' => 'no-es-uuid',
            'borrado' => tap(Department::factory()->create())->delete()->id,
        };

        try {
            newCareer(['department_id' => $id]);
            $this->fail('Debió rechazar el departamento.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['department_id'][0])->toBe('El departamento no existe o fue borrado.');
        }

        expect(Career::count())->toBe(1);
    })->with(['inexistente', 'no uuid', 'borrado']);

    it('acepta un departamento inactivo', function () {
        $inactive = Department::factory()->inactive()->create();

        expect(newCareer(['department_id' => $inactive->id])->department_id)->toBe($inactive->id);
    });
});

describe('UpdateCareer', function () {
    it('edita el nombre y acepta su propia clave', function () {
        app(UpdateCareer::class)->handle($this->career, ['code' => 'isc', 'name' => 'Sistemas']);

        expect($this->career->fresh())->code->toBe('ISC')->name->toBe('Sistemas');
    });

    it('cambia de departamento y deja el anterior en la bitácora', function () {
        $other = Department::factory()->create();

        app(UpdateCareer::class)->handle($this->career, ['department_id' => $other->id]);

        expect($this->career->fresh()->department_id)->toBe($other->id)
            ->and(Activity::sole()->attribute_changes->toArray())->toBe([
                'attributes' => ['department_id' => $other->id],
                'old' => ['department_id' => $this->department->id],
            ]);
    });

    it('rechaza al editar un departamento que no existe o que se borró', function () {
        $deleted = tap(Department::factory()->create())->delete();

        expect(fn () => app(UpdateCareer::class)->handle($this->career, ['department_id' => $deleted->id]))
            ->toThrow(ValidationException::class, 'El departamento no existe o fue borrado.');
        expect($this->career->fresh()->department_id)->toBe($this->department->id);
    });

    it('conserva su departamento aunque se haya borrado después', function () {
        $this->department->delete();

        app(UpdateCareer::class)->handle($this->career, ['department_id' => $this->department->id, 'name' => 'Sistemas']);

        expect($this->career->fresh()->name)->toBe('Sistemas');
    });

    it('rechaza al editar la clave de otra carrera', function () {
        Career::factory()->create(['code' => 'IND']);

        expect(fn () => app(UpdateCareer::class)->handle($this->career, ['code' => 'ind']))
            ->toThrow(ValidationException::class, 'Ya existe una carrera con la clave IND');
    });

    it('sin cambios no escribe, no registra ni avisa', function () {
        Event::fake([CareerUpdated::class]);

        app(UpdateCareer::class)->handle($this->career, ['code' => 'isc', 'name' => 'Ingeniería en Sistemas', 'department_id' => $this->department->id, 'is_active' => true]);

        expect(Activity::count())->toBe(0);
        Event::assertNotDispatched(CareerUpdated::class);
    });

    it('desactiva y activa dejando en la bitácora el valor anterior', function () {
        Event::fake([CareerUpdated::class]);

        app(UpdateCareer::class)->handle($this->career, ['is_active' => false]);
        app(UpdateCareer::class)->handle($this->career, ['is_active' => true]);

        [$off, $on] = Activity::orderBy('id')->get()->all();

        expect($off->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => false], 'old' => ['is_active' => true]])
            ->and($on->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => true], 'old' => ['is_active' => false]])
            ->and($off->description)->toBe('Actualizó la carrera ISC');
        Event::assertDispatchedTimes(CareerUpdated::class, 2);
    });
});

describe('SetCareerOffering', function () {
    it('crea la oferta activa en el campus, con bitácora y aviso', function () {
        Event::fake([CareerOfferingChanged::class]);
        $this->actingAs($admin = User::factory()->admin()->create());

        $offering = app(SetCareerOffering::class)->handle($this->career, $this->c2, true);
        $entry = Activity::sole();

        expect($offering)->is_active->toBeTrue()->career_id->toBe($this->career->id)->campus_id->toBe($this->c2->id)
            ->and($entry->event)->toBe('created')
            ->and($entry->description)->toBe('Ofreció la carrera ISC en C2')
            ->and($entry->subject->is($offering))->toBeTrue()
            ->and($entry->causer->is($admin))->toBeTrue();
        Event::assertDispatched(CareerOfferingChanged::class, fn (CareerOfferingChanged $event): bool => $event->offering->is($offering));
    });

    it('la desactiva y la reactiva, sin duplicar la fila y con el valor anterior en la bitácora', function () {
        $first = app(SetCareerOffering::class)->handle($this->career, $this->c2, true);
        $off = app(SetCareerOffering::class)->handle($this->career, $this->c2, false);
        $on = app(SetCareerOffering::class)->handle($this->career, $this->c2, true);

        $entries = Activity::orderBy('id')->get();

        expect(CareerCampus::withTrashed()->count())->toBe(1)
            ->and($off->is($first))->toBeTrue()->and($on->is($first))->toBeTrue()
            ->and($first->fresh()->is_active)->toBeTrue()
            ->and($entries)->toHaveCount(3)
            ->and($entries[1]->description)->toBe('Dejó de ofrecer la carrera ISC en C2')
            ->and($entries[1]->attribute_changes['attributes'])->toBe(['is_active' => false])
            ->and($entries[1]->attribute_changes['old'])->toMatchArray(['is_active' => true])
            ->and($entries[2]->description)->toBe('Ofreció la carrera ISC en C2');
    });

    it('restaura una oferta borrada en vez de duplicarla', function () {
        $deleted = CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c2->id, 'is_active' => false]);
        $deleted->delete();

        $offering = app(SetCareerOffering::class)->handle($this->career, $this->c2, true);

        expect($offering->is($deleted))->toBeTrue()
            ->and(CareerCampus::withTrashed()->count())->toBe(1)
            ->and($deleted->fresh())->deleted_at->toBeNull()->is_active->toBeTrue()
            ->and(Activity::sole()->attribute_changes['old'])->toMatchArray(['is_active' => false, 'deleted' => true]);
    });

    it('sin cambios no escribe, no registra ni avisa', function () {
        app(SetCareerOffering::class)->handle($this->career, $this->c2, true);
        $before = Activity::count();
        Event::fake([CareerOfferingChanged::class]);

        app(SetCareerOffering::class)->handle($this->career, $this->c2, true);

        expect(Activity::count())->toBe($before);
        Event::assertNotDispatched(CareerOfferingChanged::class);
    });

    it('en otro campus la oferta es otra fila', function () {
        $c1 = Campus::factory()->create(['code' => 'C1']);

        app(SetCareerOffering::class)->handle($this->career, $this->c2, true);
        app(SetCareerOffering::class)->handle($this->career, $c1, false);

        expect(CareerCampus::where('campus_id', $this->c2->id)->sole()->is_active)->toBeTrue()
            ->and(CareerCampus::where('campus_id', $c1->id)->sole()->is_active)->toBeFalse();
    });

    it('una carrera inactiva se puede ofrecer pero la oferta nace inactiva', function () {
        $this->career->update(['is_active' => false]);

        $offering = app(SetCareerOffering::class)->handle($this->career, $this->c2, true);

        expect($offering->is_active)->toBeFalse()
            ->and(Activity::sole()->description)->toBe('Ofreció la carrera ISC en C2');
    });

    it('una carrera inactiva con oferta activa: ofrecerla no la desactiva', function () {
        $existing = CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c2->id]);
        $this->career->update(['is_active' => false]);

        $offering = app(SetCareerOffering::class)->handle($this->career, $this->c2, true);

        expect($offering->is($existing))->toBeTrue()->and($existing->fresh()->is_active)->toBeTrue()->and(Activity::count())->toBe(0);
    });

    it('una carrera inactiva no reactiva una oferta existente inactiva', function () {
        CareerCampus::factory()->inactive()->create(['career_id' => $this->career->id, 'campus_id' => $this->c2->id]);
        $this->career->update(['is_active' => false]);

        $offering = app(SetCareerOffering::class)->handle($this->career, $this->c2, true);

        expect($offering->fresh()->is_active)->toBeFalse()->and(Activity::count())->toBe(0);
    });
});

describe('DeleteCareer', function () {
    it('borra sin planes: deja deleted_at, bitácora y avisa con CareerDeleted', function () {
        Event::fake([CareerDeleted::class]);
        $this->actingAs($admin = User::factory()->admin()->create());

        app(DeleteCareer::class)->handle($this->career);

        $entry = Activity::where('event', 'deleted')->sole();

        expect(Career::find($this->career->id))->toBeNull()
            ->and(Career::withTrashed()->find($this->career->id)->deleted_at)->not->toBeNull()
            ->and($entry->description)->toBe('Borró la carrera ISC')
            ->and($entry->causer->is($admin))->toBeTrue()
            ->and($entry->attribute_changes['old'])->toBe(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas', 'department_id' => $this->department->id, 'is_active' => true]);
        Event::assertDispatched(CareerDeleted::class);
    });

    it('rechaza borrar con planes y dice cuántos, también si están borrados', function () {
        StudyPlan::factory()->create(['career_id' => $this->career->id]);
        StudyPlan::factory()->create(['career_id' => $this->career->id])->delete();

        try {
            app(DeleteCareer::class)->handle($this->career);
            $this->fail('Debió rechazar el borrado.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['career'][0])->toBe('No se puede borrar: tiene 2 planes de estudio, aunque estén borrados.');
        }

        expect(Career::find($this->career->id))->not->toBeNull()->and(Activity::count())->toBe(0);
    });

    it('al borrar cierra los alcances de sus coordinadores', function () {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->career->id])->create();

        app(DeleteCareer::class)->handle($this->career);

        expect($coordinador->roleScopes()->sole()->valid_to->toDateString())->toBe(today()->subDay()->toDateString());
    });
});
