<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Actions\AddRoomBlock;
use App\Modules\Facilities\Actions\RemoveRoomBlock;
use App\Modules\Facilities\Events\RoomBlockAdded;
use App\Modules\Facilities\Events\RoomBlockRemoved;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Bloqueos de un salón. El día y las horas deben caber en el perfil del campus (días y jornada); la
| hora de fin es posterior a la de inicio; el periodo es opcional (sin él, todos); quitar es borrado
| lógico.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->room = Room::factory()->for(Building::factory()->for($this->campus))->create(['code' => 'A-101']);
});

function blockData(array $overrides = []): array
{
    return ['term_id' => null, 'day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00', 'reason' => 'Mantenimiento', ...$overrides];
}

it('agrega un bloqueo para todos los periodos, con bitácora y aviso', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Event::fake([RoomBlockAdded::class]);

    $block = app(AddRoomBlock::class)->handle($this->room, blockData(['reason' => ' Reservado ']));
    $entry = Activity::sole();

    expect($block->fresh())->term_id->toBeNull()->day->toBe(2)->reason->toBe('Reservado')
        ->and(substr($block->fresh()->starts_at, 0, 5))->toBe('09:00')
        ->and($entry)->event->toBe('created')->description->toBe('Bloqueó el salón A-101 el martes de 09:00 a 11:00')
        ->and($entry->causer->is($admin))->toBeTrue();
    Event::assertDispatched(RoomBlockAdded::class, fn (RoomBlockAdded $event): bool => $event->block->is($block));
});

it('acepta un periodo existente y rechaza uno inexistente', function () {
    $term = Term::factory()->create();

    expect(app(AddRoomBlock::class)->handle($this->room, blockData(['term_id' => $term->id]))->term_id)->toBe($term->id)
        ->and(fn () => app(AddRoomBlock::class)->handle($this->room, blockData(['term_id' => fakeKey(5)])))
        ->toThrow(ValidationException::class, 'El periodo no existe');
});

it('rechaza un día que el campus no usa', function () {
    expect(fn () => app(AddRoomBlock::class)->handle($this->room, blockData(['day' => 6])))
        ->toThrow(ValidationException::class, 'El campus no tiene clases ese día');
});

it('respeta los días del perfil del campus, no unos fijos', function () {
    $this->campus->update(['settings' => [...$this->campus->settings, 'days' => [1, 6]]]);

    expect(app(AddRoomBlock::class)->handle($this->room->fresh(), blockData(['day' => 6]))->day)->toBe(6)
        ->and(fn () => app(AddRoomBlock::class)->handle($this->room->fresh(), blockData(['day' => 2])))
        ->toThrow(ValidationException::class, 'El campus no tiene clases ese día');
});

it('rechaza horas fuera de la jornada del campus', function (string $starts, string $ends) {
    expect(fn () => app(AddRoomBlock::class)->handle($this->room, blockData(['starts_at' => $starts, 'ends_at' => $ends])))
        ->toThrow(ValidationException::class, 'dentro del horario del campus (07:00 a 21:00)');
})->with([['06:00', '08:00'], ['20:00', '22:00']]);

it('acepta de la hora de inicio a la de cierre de la jornada', function () {
    expect(app(AddRoomBlock::class)->handle($this->room, blockData(['starts_at' => '07:00', 'ends_at' => '21:00'])))->toBeInstanceOf(RoomBlock::class);
});

it('rechaza una hora de fin igual o anterior a la de inicio', function (string $starts, string $ends) {
    expect(fn () => app(AddRoomBlock::class)->handle($this->room, blockData(['starts_at' => $starts, 'ends_at' => $ends])))
        ->toThrow(ValidationException::class, 'La hora de fin debe ser posterior');
})->with([['10:00', '10:00'], ['11:00', '10:00']]);

it('rechaza horas que no son HH:MM y un motivo vacío', function () {
    expect(fn () => app(AddRoomBlock::class)->handle($this->room, blockData(['starts_at' => '9am'])))->toThrow(ValidationException::class, 'hora de inicio')
        ->and(fn () => app(AddRoomBlock::class)->handle($this->room, blockData(['reason' => '  '])))->toThrow(ValidationException::class, 'El motivo no puede quedar vacío');
});

it('quitar un bloqueo es borrado lógico, con bitácora y aviso', function () {
    $block = app(AddRoomBlock::class)->handle($this->room, blockData());
    $logged = Activity::count();
    Event::fake([RoomBlockRemoved::class]);

    app(RemoveRoomBlock::class)->handle($block);

    expect(RoomBlock::count())->toBe(0)->and(RoomBlock::withTrashed()->count())->toBe(1)
        ->and(Activity::query()->orderByDesc('id')->firstOrFail())->event->toBe('deleted')->description->toBe('Quitó el bloqueo del salón A-101 el martes de 09:00 a 11:00');
    Event::assertDispatched(RoomBlockRemoved::class);
});

it('quitar un bloqueo que ya se quitó no vuelve a registrar nada', function () {
    $block = app(AddRoomBlock::class)->handle($this->room, blockData());
    app(RemoveRoomBlock::class)->handle($block);
    $logged = Activity::count();

    app(RemoveRoomBlock::class)->handle($block);

    expect(Activity::count())->toBe($logged);
});
