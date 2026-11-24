<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use Spatie\Activitylog\Models\Activity;

/*
| facilities:set-room-types (1.33): cambia el tipo de los salones de un campus, todo o nada, con bitácora.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $building = Building::factory()->create(['campus_id' => $this->c1->id]);
    $this->r701 = Room::factory()->create(['building_id' => $building->id, 'code' => '701', 'type' => RoomType::Aula]);
    $this->r702 = Room::factory()->create(['building_id' => $building->id, 'code' => '702', 'type' => RoomType::Aula]);
    $this->r704 = Room::factory()->create(['building_id' => $building->id, 'code' => '704', 'type' => RoomType::Aula]);
    $other = Building::factory()->create(['campus_id' => $this->c2->id]);
    $this->c2room = Room::factory()->create(['building_id' => $other->id, 'code' => '701', 'type' => RoomType::Aula]);
});

it('el comando cambia el tipo de los salones indicados del campus y deja bitácora', function () {
    $this->artisan('facilities:set-room-types', ['campus' => 'C1', 'assignments' => ['701=sala_computo', '702=laboratorio'], '--no-interaction' => true])
        ->assertSuccessful();

    expect($this->r701->fresh()->type)->toBe(RoomType::SalaComputo)
        ->and($this->r702->fresh()->type)->toBe(RoomType::Laboratorio)
        ->and($this->r704->fresh()->type)->toBe(RoomType::Aula)
        ->and($this->c2room->fresh()->type)->toBe(RoomType::Aula)
        ->and(Activity::query()->where('subject_id', $this->r701->id)->exists())->toBeTrue();
});

it('el comando rechaza un código inexistente sin tocar los demás', function () {
    $this->artisan('facilities:set-room-types', ['campus' => 'C1', 'assignments' => ['701=sala_computo', '999=laboratorio'], '--no-interaction' => true])
        ->assertFailed();

    expect($this->r701->fresh()->type)->toBe(RoomType::Aula);
});

it('el comando rechaza un tipo inválido sin tocar nada', function () {
    $this->artisan('facilities:set-room-types', ['campus' => 'C1', 'assignments' => ['701=sala_computo', '702=bodega'], '--no-interaction' => true])
        ->assertFailed();

    expect($this->r701->fresh()->type)->toBe(RoomType::Aula);
});
