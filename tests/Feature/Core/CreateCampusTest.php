<?php

use App\Modules\Core\Actions\CreateCampus;
use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| CreateCampus recibe datos ya validados en forma (el componente) y hace cumplir las reglas del
| campus (docs/dominio/modelo-de-datos.md, `campuses`): código en mayúsculas, sin espacios y único
| entre todos los campus, también los borrados; perfil de flujo válido según CampusProfile.
| Necesita base de datos (unicidad y bitácora), por eso es feature y no unit.
*/

function newCampus(array $overrides = []): Campus
{
    return app(CreateCampus::class)->handle([
        'code' => 'C3',
        'name' => 'Campus III',
        'modality' => Modality::Presencial,
        'settings' => CampusProfile::DEFAULTS,
        ...$overrides,
    ]);
}

it('da de alta el campus activo, con su modalidad y su perfil de flujo', function () {
    $campus = newCampus(['modality' => Modality::Mixta, 'settings' => [...CampusProfile::DEFAULTS, 'days' => [1, 2, 3, 4, 5, 6]]])->fresh();

    expect($campus)
        ->code->toBe('C3')
        ->name->toBe('Campus III')
        ->modality->toBe(Modality::Mixta)
        ->is_active->toBeTrue()
        ->and($campus->profile->days)->toBe([1, 2, 3, 4, 5, 6]);
});

it('normaliza el código a mayúsculas y sin espacios', function () {
    expect(newCampus(['code' => ' c 3 '])->code)->toBe('C3');
});

it('guarda el perfil completo y normalizado, con las claves que faltan por omisión', function () {
    $campus = newCampus(['settings' => ['days' => [5, 1, 1, 3]]])->fresh();

    expect($campus->settings)->toEqual([...CampusProfile::DEFAULTS, 'days' => [1, 3, 5]]);
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

it('rechaza un perfil de flujo que CampusProfile no acepta y no crea nada', function () {
    try {
        newCampus(['settings' => [...CampusProfile::DEFAULTS, 'block_minutes' => 45]]);
        $this->fail('Debió rechazar el perfil.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('settings')
            ->and($exception->errors()['settings'][0])->toContain('block_minutes debe dividir exacto la jornada');
    }

    expect(Campus::count())->toBe(0)
        ->and(Activity::count())->toBe(0);
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
                'modality' => 'presencial',
                'settings' => CampusProfile::DEFAULTS,
                'is_active' => true,
            ],
        ]);
});
