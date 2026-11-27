<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Actions\CreateBuilding;
use App\Modules\Facilities\Actions\UpdateBuilding;
use App\Modules\Facilities\Events\BuildingCreated;
use App\Modules\Facilities\Events\BuildingUpdated;
use App\Modules\Facilities\Models\Building;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Actions de Edificio. La clave se normaliza (mayúsculas, sin espacios) y es única por campus, también
| contra los borrados; el mismo código puede existir en otro campus.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
});

function newBuilding(Campus $campus, array $overrides = []): Building
{
    return app(CreateBuilding::class)->handle($campus, ['code' => 'A', 'name' => 'Edificio A', ...$overrides]);
}

it('da de alta un edificio activo del campus con la clave en mayúsculas y sin espacios', function () {
    $building = newBuilding($this->c2, ['code' => ' ed - a ']);

    expect($building->fresh())->code->toBe('ED-A')->name->toBe('Edificio A')->is_active->toBeTrue()
        ->campus_id->toBe($this->c2->id);
});

it('registra en la bitácora quién dio de alta el edificio', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $building = newBuilding($this->c2);
    $entry = Activity::sole();

    expect($entry)->event->toBe('created')->description->toBe('Dio de alta el edificio A')
        ->and($entry->subject->is($building))->toBeTrue()
        ->and($entry->causer->is($admin))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe(['attributes' => ['campus_id' => $this->c2->id, 'code' => 'A', 'name' => 'Edificio A', 'is_active' => true]]);
});

it('avisa con BuildingCreated', function () {
    Event::fake([BuildingCreated::class]);

    $building = newBuilding($this->c2);

    Event::assertDispatched(BuildingCreated::class, fn (BuildingCreated $event): bool => $event->building->is($building));
});

it('rechaza una clave repetida en el campus, también contra un borrado, pero no en otro campus', function (bool $trashed) {
    $existing = newBuilding($this->c2);
    $trashed && $existing->delete();

    expect(fn () => newBuilding($this->c2, ['code' => 'a']))->toThrow(ValidationException::class, 'Ya existe un edificio con la clave A');
    expect(newBuilding($this->c1, ['code' => 'a']))->code->toBe('A');
})->with(['activo' => false, 'borrado' => true]);

it('rechaza una clave o un nombre vacíos', function () {
    expect(fn () => newBuilding($this->c2, ['code' => "\u{00A0} "]))->toThrow(ValidationException::class, 'La clave no puede quedar vacía')
        ->and(fn () => newBuilding($this->c2, ['name' => '  ']))->toThrow(ValidationException::class, 'El nombre no puede quedar vacío');
});

it('edita el nombre, acepta su propia clave y rechaza la de otro edificio', function () {
    $building = newBuilding($this->c2);
    newBuilding($this->c2, ['code' => 'B']);

    app(UpdateBuilding::class)->handle($building, ['code' => 'a', 'name' => 'Edificio Uno']);
    expect($building->fresh()->name)->toBe('Edificio Uno');

    expect(fn () => app(UpdateBuilding::class)->handle($building, ['code' => 'b']))
        ->toThrow(ValidationException::class, 'Ya existe un edificio con la clave B');
});

it('activa y desactiva con bitácora del valor anterior y del nuevo, y avisa', function () {
    $building = newBuilding($this->c2);
    $logged = Activity::count();
    Event::fake([BuildingUpdated::class]);

    app(UpdateBuilding::class)->handle($building, ['is_active' => false]);

    expect($building->fresh()->is_active)->toBeFalse()
        ->and(Activity::query()->orderByDesc('id')->firstOrFail()->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => false], 'old' => ['is_active' => true]]);
    Event::assertDispatched(BuildingUpdated::class, fn (BuildingUpdated $event): bool => $event->changed === ['is_active']);
});

it('sin cambios no escribe, no registra ni avisa', function () {
    $building = newBuilding($this->c2);
    $logged = Activity::count();
    Event::fake([BuildingUpdated::class]);
    $updatedAt = $building->fresh()->updated_at;
    $this->travel(1)->minute();

    app(UpdateBuilding::class)->handle($building, ['code' => 'a', 'name' => 'Edificio A', 'is_active' => true]);

    expect($building->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(Activity::count())->toBe($logged);
    Event::assertNotDispatched(BuildingUpdated::class);
});

it('no cambia el campus aunque se lo manden', function () {
    $building = newBuilding($this->c2);

    app(UpdateBuilding::class)->handle($building, ['campus_id' => $this->c1->id, 'name' => 'Otro']);

    expect($building->fresh()->campus_id)->toBe($this->c2->id);
});

it('si otra sesión gana la carrera de la clave, responde con el mismo error de validación', function () {
    Building::creating(function (Building $building): void {
        Building::query()->insert(['campus_id' => $building->campus_id, 'code' => $building->code, 'name' => 'Ganó otra', 'is_active' => true]);
    });

    expect(fn () => newBuilding($this->c2))->toThrow(ValidationException::class, 'Ya existe un edificio con la clave A');
});
