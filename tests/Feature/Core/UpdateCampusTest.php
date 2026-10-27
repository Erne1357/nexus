<?php

use App\Modules\Core\Actions\UpdateCampus;
use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Events\CampusUpdated;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| UpdateCampus cambia solo lo que recibe (datos, estado o perfil de flujo) con las mismas reglas que
| CreateCampus (CampusRules). Dentro de la transacción lee el campus con bloqueo, deja en la
| bitácora el valor anterior confirmado y el nuevo de lo que cambió y, después del commit, avisa con
| CampusUpdated. Si nada cambió, no escribe, no registra ni avisa.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create([
        'code' => 'C2',
        'name' => 'Campus II',
        'modality' => Modality::Presencial,
    ])->fresh();
});

function updateCampus(Campus $campus, array $data): Campus
{
    return app(UpdateCampus::class)->handle($campus, $data);
}

it('cambia solo los campos que recibe', function () {
    updateCampus($this->campus, ['name' => 'Campus Dos']);

    expect($this->campus->fresh())
        ->name->toBe('Campus Dos')
        ->code->toBe('C2')
        ->modality->toBe(Modality::Presencial)
        ->is_active->toBeTrue();
});

it('normaliza el código nuevo a mayúsculas y sin espacios', function () {
    updateCampus($this->campus, ['code' => 'c 22']);

    expect($this->campus->fresh()->code)->toBe('C22');
});

it('acepta el código propio del campus, aunque llegue en minúsculas', function () {
    updateCampus($this->campus, ['code' => 'c2', 'name' => 'Campus Dos']);

    expect($this->campus->fresh())->code->toBe('C2')->name->toBe('Campus Dos');
});

it('rechaza el código de otro campus, también si está borrado', function (bool $trashed) {
    $other = Campus::factory()->create(['code' => 'C1']);

    if ($trashed) {
        $other->delete();
    }

    expect(fn () => updateCampus($this->campus, ['code' => 'c1']))
        ->toThrow(ValidationException::class, 'Ya existe un campus con el código C1');

    expect($this->campus->fresh()->code)->toBe('C2');
})->with(['activo' => false, 'borrado' => true]);

it('rechaza un perfil de flujo que CampusProfile no acepta y deja el campus como estaba', function () {
    expect(fn () => updateCampus($this->campus, [
        'name' => 'Campus Dos',
        'settings' => [...CampusProfile::DEFAULTS, 'day_start' => '21:00', 'day_end' => '07:00'],
    ]))->toThrow(ValidationException::class, 'day_end debe ser posterior a day_start');

    expect($this->campus->fresh())
        ->name->toBe('Campus II')
        ->settings->toEqual(CampusProfile::DEFAULTS)
        ->and(Activity::count())->toBe(0);
});

it('desactiva y reactiva el campus', function () {
    updateCampus($this->campus, ['is_active' => false]);
    expect($this->campus->fresh()->is_active)->toBeFalse();

    updateCampus($this->campus, ['is_active' => true]);
    expect($this->campus->fresh()->is_active)->toBeTrue();
});

it('registra en la bitácora quién cambió el campus, con el valor anterior y el nuevo de lo que cambió', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $settings = [...CampusProfile::DEFAULTS, 'uses_rooms' => false];

    updateCampus($this->campus, ['code' => 'C2', 'name' => 'Campus Dos', 'settings' => $settings]);

    $entry = Activity::sole();

    expect($entry)
        ->event->toBe('updated')
        ->description->toBe('Actualizó el campus C2')
        ->and($entry->subject->is($this->campus))->toBeTrue()
        ->and($entry->causer->is($admin))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe([
            'attributes' => ['name' => 'Campus Dos', 'settings' => $settings],
            'old' => ['name' => 'Campus II', 'settings' => CampusProfile::DEFAULTS],
        ]);
});

it('sin cambios no escribe, no registra ni avisa, aunque PostgreSQL haya reordenado el jsonb del perfil', function () {
    Event::fake([CampusUpdated::class]);
    $updatedAt = $this->campus->updated_at;
    $this->travel(1)->minute();

    updateCampus($this->campus, ['code' => 'c2', 'name' => 'Campus II', 'settings' => CampusProfile::DEFAULTS, 'is_active' => true]);

    expect($this->campus->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(Activity::count())->toBe(0);
    Event::assertNotDispatched(CampusUpdated::class);
});

it('avisa con CampusUpdated qué campos cambiaron', function () {
    Event::fake([CampusUpdated::class]);

    updateCampus($this->campus, ['name' => 'Campus Dos', 'settings' => [...CampusProfile::DEFAULTS, 'days' => [1, 2, 3, 4, 5, 6]]]);

    Event::assertDispatched(CampusUpdated::class, fn (CampusUpdated $event): bool => $event->campus->is($this->campus)
        && $event->changed === ['name', 'settings']);
});

it('avisa solo después del commit: si la transacción se revierte, nadie se entera', function () {
    $heard = [];
    Event::listen(CampusUpdated::class, function (CampusUpdated $event) use (&$heard): void {
        $heard[] = $event;
    });

    try {
        DB::transaction(function (): void {
            updateCampus($this->campus, ['name' => 'Campus Dos']);

            throw new RuntimeException('Falla algo después, en la misma transacción.');
        });
    } catch (RuntimeException) {
        //
    }

    expect($heard)->toBe([])
        ->and($this->campus->fresh()->name)->toBe('Campus II');

    updateCampus($this->campus->fresh(), ['name' => 'Campus Dos']);

    expect($heard)->toHaveCount(1);
});

describe('con el perfil guardado inválido', function () {
    beforeEach(function () {
        $this->broken = ['block_minutes' => 45];
        DB::table('campuses')->where('id', $this->campus->id)->update(['settings' => json_encode($this->broken)]);
        $this->campus = $this->campus->fresh();
    });

    it('activa, desactiva y cambia nombre o código sin error', function () {
        updateCampus($this->campus, ['is_active' => false]);
        updateCampus($this->campus, ['is_active' => true, 'name' => 'Campus Dos', 'code' => 'C22']);

        expect($this->campus->fresh())
            ->is_active->toBeTrue()
            ->name->toBe('Campus Dos')
            ->code->toBe('C22')
            ->settings->toBe($this->broken);
    });

    it('se repara guardando un perfil válido, y la bitácora conserva el perfil roto como valor anterior', function () {
        updateCampus($this->campus, ['settings' => CampusProfile::DEFAULTS]);

        expect($this->campus->fresh()->profile->toArray())->toBe(CampusProfile::DEFAULTS)
            ->and(Activity::sole()->attribute_changes['old'])->toBe(['settings' => $this->broken]);
    });
});

it('si otro guarda el mismo código entre la validación y la escritura, responde con el error de código', function () {
    Campus::updating(fn () => DB::table('campuses')->insert(['code' => 'C1', 'name' => 'Otra sesión', 'modality' => 'presencial']));

    expect(fn () => updateCampus($this->campus, ['code' => 'C1']))
        ->toThrow(ValidationException::class, 'Ya existe un campus con el código C1');

    expect($this->campus->fresh()->code)->toBe('C2')
        ->and(Activity::count())->toBe(0);
});

it('registra como valor anterior el confirmado en la base, leído con bloqueo dentro de la transacción', function () {
    $stale = $this->campus;
    DB::table('campuses')->where('id', $stale->id)->update(['name' => 'Campus 2']);
    DB::enableQueryLog();

    updateCampus($stale, ['name' => 'Campus Dos']);

    $locked = collect(DB::getQueryLog())->contains(fn (array $query): bool => str_contains($query['query'], 'from "campuses"')
        && str_contains($query['query'], 'for update'));

    expect($locked)->toBeTrue()
        ->and(Activity::sole()->attribute_changes->toArray())->toBe([
            'attributes' => ['name' => 'Campus Dos'],
            'old' => ['name' => 'Campus 2'],
        ]);
});
