<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\ApplyReanalysisSuggestions;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Queries\ReanalysisBoardQuery;
use App\Modules\Scheduling\Queries\ReanalysisSuggestionsQuery;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;
use App\Modules\Scheduling\Rules\Reanalysis\SuggestionBatchResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| ApplyReanalysisSuggestions (seguimiento de la tarea 8.3a): aplica varias sugerencias de un bloque, una por una, cada una
| con sus guardas (ApplyReanalysisSuggestion u OpenReanalysisGroup); las que no se pueden se dicen y las demás se aplican.
| Antes de escribir nada autoriza todo y rechaza el lote si dos chocan. El mundo: Cálculo Integral en C2 con ISIC faltante
| (70 esperados; el grupo A de 40 lugares en un salón de 50) e IIND sobrante (25 esperados en B, de 30, y E, de 20 y sin
| docente, el martes).
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $this->jefe = $this->world->head();
    $this->world->published()->gradesArrived()->inReanalysis($this->jefe);
    $this->a = $this->world->groups['A'];
    $this->e = $this->world->group($this->world->integral, $this->world->iind, 20, $this->world->c2, 2, day: 2);
    $this->e->update(['teacher_id' => null]);
    batchExpectIn($this->world, 'isc', 70);
    batchExpectIn($this->world, 'iind', 25);
    $this->expandKey = "expand_quota:{$this->a->id}:{$this->world->isc->id}";
    $this->openKey = "open_group:{$this->world->isc->id}";
    $this->closeKey = "close_group:{$this->e->id}";
});

function batchExpectIn(ReanalysisWorld $world, string $career, int $expected): void
{
    DemandForecast::query()->where('subject_id', $world->integral->id)->where('campus_id', $world->c2->id)->where('career_id', $world->{$career}->id)->update(['expected_students' => $expected]);
}

/**
 * Las sugerencias de Cálculo Integral en C2 de esas llaves, calculadas como en el drawer.
 *
 * @return list<ReanalysisSuggestion>
 */
function batchSuggestionsIn(ReanalysisWorld $world, string ...$keys): array
{
    $block = app(ReanalysisBoardQuery::class)->block($world->load->fresh(), null, $world->integral->id, $world->c2->id);
    $advice = app(ReanalysisSuggestionsQuery::class)->for($world->load, $block);

    return array_map(function (string $key) use ($advice): ReanalysisSuggestion {
        $suggestion = $advice->find($key);
        expect($suggestion)->not->toBeNull();

        return $suggestion;
    }, $keys);
}

/**
 * @return array<string, string> llave => motivo, de las que no se aplicaron
 */
function batchFailuresOf(SuggestionBatchResult $result): array
{
    return array_column(array_map(fn (array $failure): array => [$failure['suggestion']->key, $failure['reason']], $result->failed), 1, 0);
}

it('aplica tres de tipos distintos una por una, abrir el grupo primero, cada una con su bitácora', function () {
    [$expand, $close, $open] = batchSuggestionsIn($this->world, $this->expandKey, $this->closeKey, $this->openKey);

    $result = app(ApplyReanalysisSuggestions::class)->handle($this->world->load, [$expand, $close, $open], [$open->key => $this->world->newGroupPlacement($open)], $this->jefe);
    $opened = $result->opened[$this->openKey];

    expect(array_map(fn (ReanalysisSuggestion $suggestion): string => $suggestion->key, $result->applied))->toBe([$this->openKey, $this->expandKey, $this->closeKey])
        ->and($result->failed)->toBe([])
        ->and($this->a->fresh()->capacity)->toBe(50)
        ->and($this->e->fresh()->status)->toBe(GroupStatus::Cancelled)
        ->and($opened->teacher_id)->toBeNull()
        ->and(GroupQuota::query()->where('group_id', $opened->id)->pluck('max_seats', 'career_id')->all())->toBe([$this->world->isc->id => 20])
        ->and(Activity::query()->where('event', 'capacity_updated')->where('subject_id', $this->a->id)->sole()->causer_id)->toBe($this->jefe->id)
        ->and(Activity::query()->where('event', 'cancelled')->where('subject_id', $this->e->id)->sole()->causer_id)->toBe($this->jefe->id)
        ->and(Activity::query()->where('event', 'created')->where('subject_id', $opened->id)->sole()->causer_id)->toBe($this->jefe->id);
});

it('si una cambió desde que se calculó, aplica las demás y dice cuál no y por qué', function () {
    [$expand, $close] = batchSuggestionsIn($this->world, $this->expandKey, $this->closeKey);
    $this->e->update(['capacity' => 25]);

    $result = app(ApplyReanalysisSuggestions::class)->handle($this->world->load, [$expand, $close], [], $this->jefe);

    expect(array_map(fn (ReanalysisSuggestion $suggestion): string => $suggestion->key, $result->applied))->toBe([$this->expandKey])
        ->and(batchFailuresOf($result))->toBe([$this->closeKey => 'Esto cambió desde que abriste las sugerencias; vuelve a abrirlas.'])
        ->and($this->a->fresh()->capacity)->toBe(50)
        ->and($this->e->fresh()->status)->toBe(GroupStatus::Planned);
});

it('«Abrir un grupo» sin el lugar del motor no se aplica y lo dice; las demás sí', function () {
    [$expand, $open] = batchSuggestionsIn($this->world, $this->expandKey, $this->openKey);

    $result = app(ApplyReanalysisSuggestions::class)->handle($this->world->load, [$expand, $open], [], $this->jefe);

    expect(batchFailuresOf($result))->toBe([$this->openKey => 'Busca otra vez el horario y el salón del grupo nuevo.'])
        ->and($this->a->fresh()->capacity)->toBe(50)
        ->and(Group::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->count())->toBe(3);
});

it('rechaza el lote sin aplicar nada si dos elegidas cambian el mismo grupo o las dos abren un grupo', function () {
    Room::factory()->capacity(70)->create(['building_id' => Building::factory()->create(['campus_id' => $this->world->c2->id])->id, 'code' => 'L-70']);
    batchExpectIn($this->world, 'isc', 120);
    batchExpectIn($this->world, 'iind', 200);
    $b = $this->world->groups['B'];
    $iind = $this->world->iind->id;
    // IIND falta más, así que va primero: amplía B y se lleva L-70 a su hora; ISIC solo amplía A y abre un grupo.
    [$expandB, $roomB, $openIsic, $openIind, $expandA] = batchSuggestionsIn($this->world, "expand_quota:{$b->id}:{$iind}", "change_room:{$b->id}:{$iind}", $this->openKey, "open_group:{$iind}", $this->expandKey);
    $batch = app(ApplyReanalysisSuggestions::class);

    expect(fn () => $batch->handle($this->world->load, [$expandA, $expandB, $roomB], [], $this->jefe))
        ->toThrow(ValidationException::class, "«{$expandB->title}» y «{$roomB->title}»: las dos cambian el mismo grupo. Aplica una y revisa la otra con las sugerencias de ahora.")
        ->and(fn () => $batch->handle($this->world->load, [$openIsic, $expandA, $openIind], [], $this->jefe))
        ->toThrow(ValidationException::class, 'las dos abren un grupo, y se abre uno por vez')
        ->and($this->a->fresh()->capacity)->toBe(40)
        ->and($b->fresh()->capacity)->toBe(30);
});

it('autoriza todas antes de escribir: si el jefe no puede con una, no aplica ninguna', function () {
    [$expand, $close] = batchSuggestionsIn($this->world, $this->expandKey, $this->closeKey);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'cancel' ? false : null);

    expect(fn () => app(ApplyReanalysisSuggestions::class)->handle($this->world->load, [$expand, $close], [], $this->jefe))->toThrow(AuthorizationException::class)
        ->and($this->a->fresh()->capacity)->toBe(40)
        ->and($this->e->fresh()->status)->toBe(GroupStatus::Planned);
});

it('no aplica nada con la carga fuera de re-análisis ni con el lote vacío', function () {
    [$expand] = batchSuggestionsIn($this->world, $this->expandKey);
    $batch = app(ApplyReanalysisSuggestions::class);

    expect(fn () => $batch->handle($this->world->load, [], [], $this->jefe))->toThrow(ValidationException::class, 'Elige al menos una sugerencia.');

    $this->world->load->update(['status' => LoadStatus::Closed]);

    expect(fn () => $batch->handle($this->world->load, [$expand], [], $this->jefe))->toThrow(ValidationException::class, 'La carga ya no está en re-análisis: las sugerencias ya no se aplican.')
        ->and($this->a->fresh()->capacity)->toBe(40);
});

it('RA-07: el coordinador, Subdirección y el jefe de otro departamento no aplican en lote', function (string $who) {
    [$expand, $close] = batchSuggestionsIn($this->world, $this->expandKey, $this->closeKey);
    $user = match ($who) {
        'coordinador' => $this->world->coordinator($this->world->isc),
        'subdireccion' => User::factory()->withRole(Role::Subdireccion)->create(),
        'otro jefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create(),
    };

    expect(fn () => app(ApplyReanalysisSuggestions::class)->handle($this->world->load, [$expand, $close], [], $user))->toThrow(AuthorizationException::class)
        ->and($this->a->fresh()->capacity)->toBe(40)
        ->and($this->e->fresh()->status)->toBe(GroupStatus::Planned);
})->with(['coordinador', 'subdireccion', 'otro jefe']);
