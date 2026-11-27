<?php

use App\Modules\Core\Actions\UpdateCampus;
use App\Modules\Core\Events\CampusUpdated;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| UpdateCampus cambia solo lo que recibe (código, nombre o estado; el campus es solo el lugar, MS3) con las mismas
| reglas que CreateCampus (CampusRules). Dentro de la transacción lee el campus con bloqueo, deja en la
| bitácora el valor anterior confirmado y el nuevo de lo que cambió y, después del commit, avisa con
| CampusUpdated. Si nada cambió, no escribe, no registra ni avisa.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create([
        'code' => 'C2',
        'name' => 'Campus II',
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

it('desactiva y reactiva el campus', function () {
    updateCampus($this->campus, ['is_active' => false]);
    expect($this->campus->fresh()->is_active)->toBeFalse();

    updateCampus($this->campus, ['is_active' => true]);
    expect($this->campus->fresh()->is_active)->toBeTrue();
});

it('registra en la bitácora quién cambió el campus, con el valor anterior y el nuevo de lo que cambió', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    updateCampus($this->campus, ['code' => 'C2', 'name' => 'Campus Dos', 'is_active' => false]);

    $entry = Activity::sole();

    expect($entry)
        ->event->toBe('updated')
        ->description->toBe('Actualizó el campus C2')
        ->and($entry->subject->is($this->campus))->toBeTrue()
        ->and($entry->causer->is($admin))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe([
            'attributes' => ['name' => 'Campus Dos', 'is_active' => false],
            'old' => ['name' => 'Campus II', 'is_active' => true],
        ]);
});

it('sin cambios no escribe, no registra ni avisa', function () {
    Event::fake([CampusUpdated::class]);
    $updatedAt = $this->campus->updated_at;
    $this->travel(1)->minute();

    updateCampus($this->campus, ['code' => 'c2', 'name' => 'Campus II', 'is_active' => true]);

    expect($this->campus->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(Activity::count())->toBe(0);
    Event::assertNotDispatched(CampusUpdated::class);
});

it('avisa con CampusUpdated qué campos cambiaron', function () {
    Event::fake([CampusUpdated::class]);

    updateCampus($this->campus, ['name' => 'Campus Dos', 'is_active' => false]);

    Event::assertDispatched(CampusUpdated::class, fn (CampusUpdated $event): bool => $event->campus->is($this->campus)
        && $event->changed === ['name', 'is_active']);
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

it('si otro guarda el mismo código entre la validación y la escritura, responde con el error de código', function () {
    Campus::updating(fn () => DB::table('campuses')->insert(['code' => 'C1', 'name' => 'Otra sesión']));

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

it('MS3: ignora la modalidad y el perfil de flujo: el campus es solo el lugar', function () {
    updateCampus($this->campus, ['modality' => 'en_linea', 'settings' => ['uses_rooms' => false], 'name' => 'Campus Dos']);

    expect($this->campus->fresh())->name->toBe('Campus Dos')
        ->and(Activity::sole()->attribute_changes->toArray())->toBe([
            'attributes' => ['name' => 'Campus Dos'],
            'old' => ['name' => 'Campus II'],
        ]);
});
