<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\MySelectionTurnQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| MySelectionTurnQuery (1.85, IN-03): el turno del propio docente en las selecciones vivas del periodo (1.33). Solo lee el
| renglón de ese docente; lo ajeno, en borrador, terminado o de elección previa no aparece.
*/

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-12-08 10:40', 'America/Ciudad_Juarez');
    $this->term = Term::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->query = app(MySelectionTurnQuery::class);

    $this->selection = function (SelectionStatus $status = SelectionStatus::Published, ?Term $term = null, string $department = 'Sistemas y Computación'): Selection {
        return Selection::factory()->create([
            'term_id' => ($term ?? $this->term)->id,
            'department_id' => Department::factory()->create(['name' => $department])->id,
            'status' => $status,
        ]);
    };
    $this->entry = function (Selection $selection, ?Teacher $teacher = null, int $position = 1, array $state = []): SelectionEntry {
        return SelectionEntry::factory()->create([
            'selection_id' => $selection->id,
            'teacher_id' => ($teacher ?? $this->teacher)->id,
            'position' => $position,
            'slot_starts_at' => '2026-12-08 10:30',
            'slot_ends_at' => '2026-12-08 11:00',
            ...$state,
        ]);
    };
});

it('una selección en borrador, terminada o cancelada no da turno', function (SelectionStatus $status) {
    ($this->entry)(($this->selection)($status));

    expect($this->query->forTeacher($this->teacher, $this->term, $this->now))->toBe([]);
})->with([SelectionStatus::Draft, SelectionStatus::Finished, SelectionStatus::Cancelled]);

it('una publicada con él en el lugar 14 de 139 da su posición, total y horario, y en turno si le toca', function () {
    $selection = ($this->selection)();
    ($this->entry)($selection, null, 14);
    foreach (range(1, 138) as $index) {
        SelectionEntry::factory()->create(['selection_id' => $selection->id, 'position' => $index + 14, 'slot_starts_at' => '2026-12-09 09:00', 'slot_ends_at' => '2026-12-09 09:30']);
    }

    $turns = $this->query->forTeacher($this->teacher, $this->term, $this->now);

    expect($turns)->toHaveCount(1)
        ->and($turns[0])->toMatchArray(['selection_id' => $selection->id, 'department' => 'Sistemas y Computación', 'position' => 14, 'total' => 139, 'in_turn' => true])
        ->and($turns[0]['starts_at']->equalTo(CarbonImmutable::parse('2026-12-08 10:30', 'America/Ciudad_Juarez')))->toBeTrue()
        ->and($turns[0]['ends_at']->equalTo(CarbonImmutable::parse('2026-12-08 11:00', 'America/Ciudad_Juarez')))->toBeTrue();
});

it('el turno termina en el borde: a la hora de fin ya no está en turno, antes de empezar tampoco', function () {
    ($this->entry)(($this->selection)());

    expect($this->query->forTeacher($this->teacher, $this->term, $this->now->setTime(11, 0))[0]['in_turn'])->toBeFalse()
        ->and($this->query->forTeacher($this->teacher, $this->term, $this->now->setTime(10, 29))[0]['in_turn'])->toBeFalse()
        ->and($this->query->forTeacher($this->teacher, $this->term, $this->now->setTime(10, 30))[0]['in_turn'])->toBeTrue();
});

it('solo ve su renglón: otro docente de la misma selección no aparece ni lo cambia', function () {
    $selection = ($this->selection)();
    $other = Teacher::factory()->create();
    ($this->entry)($selection, $other, 3);
    ($this->entry)($selection, null, 4, ['slot_starts_at' => '2026-12-09 09:00', 'slot_ends_at' => '2026-12-09 09:30']);

    $mine = $this->query->forTeacher($this->teacher, $this->term, $this->now);
    $theirs = $this->query->forTeacher($other, $this->term, $this->now);

    expect($mine)->toHaveCount(1)
        ->and($mine[0])->toMatchArray(['position' => 4, 'total' => 2, 'in_turn' => false])
        ->and($theirs[0])->toMatchArray(['position' => 3, 'in_turn' => true]);
});

it('dos selecciones vivas dan dos entradas, ordenadas por el inicio de su turno', function () {
    ($this->entry)(($this->selection)(SelectionStatus::Running, null, 'Industrial'), null, 2, ['slot_starts_at' => '2026-12-10 09:00', 'slot_ends_at' => '2026-12-10 09:30']);
    ($this->entry)(($this->selection)(SelectionStatus::Published, null, 'Sistemas'), null, 1);

    $turns = $this->query->forTeacher($this->teacher, $this->term, $this->now);

    expect(array_column($turns, 'department'))->toBe(['Sistemas', 'Industrial']);
});

it('las selecciones de otro periodo no cuentan', function () {
    ($this->entry)(($this->selection)(SelectionStatus::Published, Term::factory()->create()));

    expect($this->query->forTeacher($this->teacher, $this->term, $this->now))->toBe([]);
});

it('SEL-16: un renglón de elección previa o que ya cerró no da turno', function () {
    ($this->entry)(($this->selection)(), null, 1, ['prior_choice_at' => '2026-12-01 09:00', 'status' => SelectionEntryStatus::Done]);
    ($this->entry)(($this->selection)(), null, 2, ['status' => SelectionEntryStatus::Done, 'closed_at' => '2026-12-08 10:35']);

    expect($this->query->forTeacher($this->teacher, $this->term, $this->now))->toBe([]);
});

it('en la ronda extra usa su hora extra y solo si sigue pendiente', function () {
    $selection = ($this->selection)(SelectionStatus::ExtraRound);
    ($this->entry)($selection, null, 5, ['status' => SelectionEntryStatus::PendingExtra, 'extra_slot_starts_at' => '2026-12-12 10:00', 'extra_slot_ends_at' => '2026-12-12 10:30']);
    $done = Teacher::factory()->create();
    ($this->entry)($selection, $done, 6, ['status' => SelectionEntryStatus::Done]);

    $turns = $this->query->forTeacher($this->teacher, $this->term, CarbonImmutable::parse('2026-12-12 10:10', 'America/Ciudad_Juarez'));

    expect($turns)->toHaveCount(1)
        ->and($turns[0])->toMatchArray(['position' => 5, 'in_turn' => true])
        ->and($turns[0]['starts_at']->format('H:i'))->toBe('10:00')
        ->and($this->query->forTeacher($done, $this->term, $this->now))->toBe([]);
});

it('lee con consultas constantes aunque haya tres selecciones vivas', function () {
    foreach (range(1, 3) as $index) {
        ($this->entry)(($this->selection)(SelectionStatus::Published, null, "Depto {$index}"), null, $index);
    }

    DB::enableQueryLog();
    $this->query->forTeacher($this->teacher, $this->term, $this->now);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(4);
});
