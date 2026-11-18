<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\ReanalysisBoardQuery;
use App\Modules\Scheduling\Queries\ReanalysisSuggestions;
use App\Modules\Scheduling\Queries\ReanalysisSuggestionsQuery;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;
use Illuminate\Support\Facades\DB;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| ReanalysisSuggestionsQuery (tarea 8.3a): las sugerencias de una materia en un campus salen de sus grupos vivos y de los
| salones más grandes libres a la hora de cada sesión (HC-02, HC-06, HC-10, HC-11), en un número fijo de consultas. El
| mundo: Cálculo Integral en C2 con ISIC faltante (70 esperados; el grupo A de 40 lugares en un salón de 50) e IIND
| ajustada; en C1 con ISIC sobrante (30 contra el grupo D de 45); Cálculo Diferencial de primer semestre (RA-09).
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $this->world->published()->gradesArrived()->inReanalysis($this->world->head());
    DemandForecast::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->where('career_id', $this->world->isc->id)->update(['expected_students' => 70]);
});

function reanalysisSuggestionsOf(ReanalysisWorld $world, Subject $subject, Campus $campus): ReanalysisSuggestions
{
    $block = app(ReanalysisBoardQuery::class)->block($world->load, null, $subject->id, $campus->id);

    return app(ReanalysisSuggestionsQuery::class)->for($world->load, $block);
}

/**
 * @return list<string>
 */
function reanalysisSuggestionKeys(ReanalysisSuggestions $suggestions): array
{
    return array_map(fn (ReanalysisSuggestion $suggestion): string => $suggestion->key, $suggestions->suggestions);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function reanalysisRoomIn(Campus $campus, string $code, int $capacity, array $attributes = []): Room
{
    return Room::factory()->capacity($capacity)->create(['building_id' => Building::factory()->create(['campus_id' => $campus->id])->id, 'code' => $code, ...$attributes]);
}

it('faltante: amplía el cupo hasta su salón y, con un salón más grande libre a la misma hora, sugiere cambiarlo', function () {
    reanalysisRoomIn($this->world->c2, 'L-70', 70);
    $a = $this->world->groups['A'];
    $isc = $this->world->isc->id;

    $suggestions = reanalysisSuggestionsOf($this->world, $this->world->integral, $this->world->c2);
    $room = $suggestions->find("change_room:{$a->id}:{$isc}");

    expect(reanalysisSuggestionKeys($suggestions))->toBe(["expand_quota:{$a->id}:{$isc}", "change_room:{$a->id}:{$isc}"])
        ->and($suggestions->find("expand_quota:{$a->id}:{$isc}")->seats)->toBe(10)
        ->and($room->seats)->toBe(20)
        ->and($room->moves[0]['to']->code)->toBe('L-70')
        ->and($suggestions->seats)->toBe([$isc => 40, $this->world->iind->id => 30])
        ->and(array_keys($suggestions->models))->toBe([$a->id, $this->world->groups['B']->id]);
});

it('HC-02, HC-06, HC-10, HC-11: no ofrece un salón ocupado, de otro tipo, apartado por otro grupo o bloqueado a esa hora', function () {
    $busy = reanalysisRoomIn($this->world->c2, 'L-80', 80);
    $other = $this->world->group($this->world->differential, $this->world->iind, 20, $this->world->c2, 1, day: 4);
    GroupSession::factory()->for($other)->inRoom($busy)->on(1, '08:00', '10:00')->create();
    reanalysisRoomIn($this->world->c2, 'LAB-1', 80, ['type' => RoomType::Laboratorio]);
    $loaned = reanalysisRoomIn($this->world->c2, 'L-82', 80);
    InterdepartmentalAgreement::factory()->create(['term_id' => $this->world->term->id, 'campus_id' => $this->world->c2->id, 'room_id' => $loaned->id, 'group_id' => $other->id, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00']);
    $blocked = reanalysisRoomIn($this->world->c2, 'L-83', 80);
    RoomBlock::factory()->create(['room_id' => $blocked->id, 'day' => 1, 'starts_at' => '08:30', 'ends_at' => '09:30']);
    reanalysisRoomIn($this->world->c2, 'L-84', 80, ['is_active' => false]);

    $suggestions = reanalysisSuggestionsOf($this->world, $this->world->integral, $this->world->c2);

    expect(reanalysisSuggestionKeys($suggestions))->toBe(["expand_quota:{$this->world->groups['A']->id}:{$this->world->isc->id}", 'open_group'])
        ->and($suggestions->find('open_group')->evidence)->toBe(['ISIC: faltan 20 lugares aunque se amplíen los grupos que caben']);
});

it('HC-10: un salón de otro departamento se ofrece después de los propios, diciendo a quién se pide', function () {
    reanalysisRoomIn($this->world->c2, 'SC-90', 90, ['department_id' => $this->world->isc->department_id]);

    $room = reanalysisSuggestionsOf($this->world, $this->world->integral, $this->world->c2)->find("change_room:{$this->world->groups['A']->id}:{$this->world->isc->id}");

    expect($room->moves[0]['to']->code)->toBe('SC-90')
        ->and($room->moves[0]['to']->loanFrom)->not->toBeNull();
});

it('sobrante: sugiere cerrar el grupo que sobra y advierte a su docente', function () {
    $e = $this->world->group($this->world->integral, $this->world->isc, 20, $this->world->c1, 2, day: 2);

    $close = reanalysisSuggestionsOf($this->world, $this->world->integral, $this->world->c1)->find("close_group:{$e->id}");

    expect($close)->not->toBeNull()
        ->and($close->relocations)->toBe([])
        ->and($close->warning)->toBe("{$e->teacher->name} deja de impartir el grupo {$e->code} (2 h a la semana). Se le avisa al republicar (RA-05).");
});

it('RA-09: el bloque de primer semestre no da sugerencias', function () {
    expect(reanalysisSuggestionsOf($this->world, $this->world->differential, $this->world->c2)->suggestions)->toBe([]);
});

it('lee en un número fijo de consultas, sin importar cuántos grupos tenga el bloque', function () {
    reanalysisRoomIn($this->world->c2, 'L-70', 70);
    $block = fn () => app(ReanalysisBoardQuery::class)->block($this->world->load, null, $this->world->integral->id, $this->world->c2->id);
    $count = function () use ($block): int {
        $resolved = $block();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ReanalysisSuggestionsQuery::class)->for($this->world->load, $resolved);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count();

    foreach ([2, 3, 4] as $day) {
        $this->world->group($this->world->integral, $this->world->isc, 30, $this->world->c2, 2, day: $day);
    }

    expect($count())->toBe($few)->toBeLessThanOrEqual(15);
});
