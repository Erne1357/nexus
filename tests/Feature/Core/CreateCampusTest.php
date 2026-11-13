<?php

use App\Modules\Core\Actions\CreateCampus;
use App\Modules\Core\Events\CampusCreated;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| CreateCampus recibe datos ya validados en forma (el componente) y hace cumplir las reglas del
| campus de CampusRules (docs/dominio/modelo-de-datos.md, `campuses`): código en mayúsculas, sin
| espacios y único entre todos los campus, también los borrados. El campus es solo el lugar (MS3): sin modalidad ni
| perfil de flujo. Después del commit avisa con CampusCreated. Necesita base de datos (unicidad y
| bitácora), por eso es feature y no unit.
*/

function newCampus(array $overrides = []): Campus
{
    return app(CreateCampus::class)->handle([
        'code' => 'C3',
        'name' => 'Campus III',
        ...$overrides,
    ]);
}

it('MS3: da de alta el campus activo, solo con código y nombre', function () {
    $campus = newCampus()->fresh();

    expect($campus)
        ->code->toBe('C3')
        ->name->toBe('Campus III')
        ->is_active->toBeTrue()
        ->and(array_keys($campus->getAttributes()))->not->toContain('modality', 'settings', 'modality_id');
});

it('normaliza el código a mayúsculas y sin espacios', function () {
    expect(newCampus(['code' => ' c 3 '])->code)->toBe('C3');
});

it('rechaza un código que ya existe, aunque llegue en minúsculas', function () {
    Campus::factory()->create(['code' => 'C3']);

    expect(fn () => newCampus(['code' => 'c3']))
        ->toThrow(ValidationException::class, 'Ya existe un campus con el código C3');

    expect(Campus::withTrashed()->count())->toBe(1);
});

it('rechaza el código de un campus borrado: sigue reservado', function () {
    Campus::factory()->create(['code' => 'C3'])->delete();

    expect(fn () => newCampus())->toThrow(ValidationException::class, 'Ya existe un campus con el código C3');
});

it('rechaza un código que queda vacío al normalizar y no crea nada', function () {
    expect(fn () => newCampus(['code' => "\u{00A0} "]))
        ->toThrow(ValidationException::class, 'El código no puede quedar vacío ni ser solo espacios.');

    expect(Campus::count())->toBe(0);
});

it('si otro guarda el mismo código entre la validación y la escritura, responde con el error de código', function () {
    Campus::creating(fn () => DB::table('campuses')->insert(['code' => 'C3', 'name' => 'Otra sesión']));

    expect(fn () => newCampus())->toThrow(ValidationException::class, 'Ya existe un campus con el código C3');

    expect(Activity::count())->toBe(0);
});

it('registra en la bitácora quién dio de alta el campus y con qué datos', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $campus = newCampus();

    $entry = Activity::sole();

    expect($entry)
        ->event->toBe('created')
        ->description->toBe('Dio de alta el campus C3')
        ->and($entry->subject->is($campus))->toBeTrue()
        ->and($entry->causer->is($admin))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe([
            'attributes' => [
                'code' => 'C3',
                'name' => 'Campus III',
                'is_active' => true,
            ],
        ]);
});

it('avisa con CampusCreated solo después del commit', function () {
    $heard = [];
    Event::listen(CampusCreated::class, function (CampusCreated $event) use (&$heard): void {
        $heard[] = $event->campus->code;
    });

    try {
        DB::transaction(function (): void {
            newCampus();

            throw new RuntimeException('Falla algo después, en la misma transacción.');
        });
    } catch (RuntimeException) {
        //
    }

    expect($heard)->toBe([])
        ->and(Campus::count())->toBe(0);

    newCampus();

    expect($heard)->toBe(['C3']);
});
