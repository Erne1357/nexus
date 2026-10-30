<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Facilities\Models\SubjectRoomDefault;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
| Integridad de edificios, salones, bloqueos y salones por defecto (docs/dominio/modelo-de-datos.md, "Facilities").
| Las claves naturales son únicas por campus también contra un registro borrado, el campus de un salón es el de su
| edificio y el de un salón por defecto es el de su salón (llaves compuestas); los CHECK cubren tipo, capacidad,
| día, horas y prioridad.
*/

describe('claves naturales', function () {
    it('la clave de un edificio es única por campus, también contra uno borrado; otro campus puede repetirla', function () {
        $campus = Campus::factory()->create();
        Building::factory()->for($campus)->create(['code' => 'A'])->delete();
        Building::factory()->create(['code' => 'A']);

        expect(fn () => Building::factory()->for($campus)->create(['code' => 'A']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('la clave de un salón es única por campus, también contra uno borrado; otro campus puede repetirla', function () {
        $building = Building::factory()->create();
        Room::factory()->for($building)->create(['code' => 'A-101'])->delete();
        Room::factory()->create(['code' => 'A-101']);

        expect(fn () => Room::factory()->for($building)->create(['code' => 'A-101']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('un salón por defecto es único por materia, campus y salón, también contra uno borrado', function () {
        $default = SubjectRoomDefault::factory()->create();
        $default->delete();

        expect(fn () => SubjectRoomDefault::factory()->create([
            'subject_id' => $default->subject_id, 'room_id' => $default->room_id,
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('llaves compuestas de campus', function () {
    it('un salón con el campus distinto al de su edificio se rechaza', function () {
        $building = Building::factory()->create();
        $otherCampus = Campus::factory()->create();

        expect(fn () => Room::factory()->for($building)->create(['campus_id' => $otherCampus->id]))->toThrow(QueryException::class);
    });

    it('un salón por defecto con un salón de otro campus se rechaza', function () {
        $room = Room::factory()->create();
        $otherCampus = Campus::factory()->create();

        expect(fn () => SubjectRoomDefault::factory()->create(['room_id' => $room->id, 'campus_id' => $otherCampus->id]))
            ->toThrow(QueryException::class);
    });

    it('un salón por defecto con el salón de su campus se acepta', function () {
        $room = Room::factory()->create();

        expect(SubjectRoomDefault::factory()->create(['room_id' => $room->id])->campus_id)->toBe($room->campus_id);
    });
});

describe('restricciones CHECK', function () {
    it('rechaza una capacidad de 0 o negativa', function (int $capacity) {
        expect(fn () => Room::factory()->capacity($capacity)->create())->toThrow(QueryException::class);
    })->with([0, -5]);

    it('rechaza un tipo de salón que no existe', function () {
        $room = Room::factory()->create();

        expect(fn () => DB::table('rooms')->where('id', $room->id)->update(['type' => 'bodega']))->toThrow(QueryException::class);
    });

    it('rechaza características que no son una lista', function () {
        $room = Room::factory()->create();

        expect(fn () => DB::table('rooms')->where('id', $room->id)->update(['features' => '{"a": 1}']))->toThrow(QueryException::class);
    });

    it('rechaza un día fuera de 1 a 6', function (int $day) {
        expect(fn () => RoomBlock::factory()->create(['day' => $day]))->toThrow(QueryException::class);
    })->with([0, 7]);

    it('acepta del lunes (1) al sábado (6)', function (int $day) {
        expect(RoomBlock::factory()->create(['day' => $day])->day)->toBe($day);
    })->with([1, 6]);

    it('rechaza un bloqueo que termina antes o a la hora que empieza', function (string $ends) {
        expect(fn () => RoomBlock::factory()->create(['starts_at' => '10:00', 'ends_at' => $ends]))->toThrow(QueryException::class);
    })->with(['09:00', '10:00']);

    it('rechaza una prioridad menor a 1', function () {
        expect(fn () => SubjectRoomDefault::factory()->create(['priority' => 0]))->toThrow(QueryException::class);
    });

    it('rechaza un origen que no existe', function () {
        $default = SubjectRoomDefault::factory()->create();

        expect(fn () => DB::table('subject_room_defaults')->where('id', $default->id)->update(['source' => 'sii']))->toThrow(QueryException::class);
    });
});

it('un bloqueo sin periodo aplica a todos y uno con periodo lo referencia', function () {
    $term = Term::factory()->create();

    expect(RoomBlock::factory()->create()->term_id)->toBeNull()
        ->and(RoomBlock::factory()->create(['term_id' => $term->id])->term_id)->toBe($term->id);
});
