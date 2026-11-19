<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use Dom\Element;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| «Aplicar las N elegidas» en el drawer de sugerencias del re-análisis (seguimiento de la tarea 8.3a): el jefe marca
| varias sugerencias de un bloque y las aplica juntas, con la confirmación de Nexus; cada una va por separado y el toast
| dice cuáles no se aplicaron. No se eligen las que no se pueden aplicar, las que chocan con otra elegida, «Abrir un
| grupo» sin su lugar ni la que se edita. Subdirección, el coordinador y el jefe de otro departamento no eligen ni
| aplican. El mundo: Cálculo Integral en C2 con ISIC faltante (70 esperados; el grupo A de 40 en un salón de 50) e IIND
| sobrante (25 esperados en B, de 30, y E, de 20 y sin docente, el martes).
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $this->jefe = $this->world->head();
    $this->world->published()->gradesArrived()->inReanalysis($this->jefe);
    $this->a = $this->world->groups['A'];
    $this->e = $this->world->group($this->world->integral, $this->world->iind, 20, $this->world->c2, 2, day: 2);
    $this->e->update(['teacher_id' => null]);
    chosenExpectIn($this->world, 'isc', 70);
    chosenExpectIn($this->world, 'iind', 25);
    $this->expandKey = "expand_quota:{$this->a->id}:{$this->world->isc->id}";
    $this->openKey = "open_group:{$this->world->isc->id}";
    $this->closeKey = "close_group:{$this->e->id}";
});

function chosenExpectIn(ReanalysisWorld $world, string $career, int $expected): void
{
    DemandForecast::query()->where('subject_id', $world->integral->id)->where('campus_id', $world->c2->id)->where('career_id', $world->{$career}->id)->update(['expected_students' => $expected]);
}

function chosenDrawer(User $user, ReanalysisWorld $world): Testable
{
    return Livewire::actingAs($user)
        ->test('scheduling::components.reanalysis-suggestions', ['loadId' => $world->load->id])
        ->dispatch('open-reanalysis-suggestions', subjectId: $world->integral->id, campusId: $world->c2->id);
}

function chosenCheckbox(Testable $drawer, string $key): ?Element
{
    return domOf($drawer)->querySelector("[data-key=\"{$key}\"] [data-testid=\"suggestion-choose\"]");
}

function chosenApplyButton(Testable $drawer): ?Element
{
    return domOf($drawer)->querySelector('[data-testid="suggestions-apply-chosen"]');
}

describe('jefe del departamento', function () {
    it('elige tres de tipos distintos y las aplica juntas, con la confirmación de Nexus', function () {
        $drawer = chosenDrawer($this->jefe, $this->world);

        expect(chosenCheckbox($drawer, $this->openKey)->hasAttribute('disabled'))->toBeTrue()
            ->and(chosenCheckbox($drawer, $this->openKey)->getAttribute('title'))->toBe('Busca primero su horario y salón para elegirla.')
            ->and(chosenCheckbox($drawer, $this->expandKey)->hasAttribute('disabled'))->toBeFalse()
            ->and(chosenApplyButton($drawer)->hasAttribute('disabled'))->toBeTrue()
            ->and(textOf(chosenApplyButton($drawer)))->toBe('Aplicar las elegidas');

        $drawer->call('findPlacement', $this->openKey)->set('chosen', [$this->expandKey, $this->closeKey, $this->openKey]);
        $button = chosenApplyButton($drawer);

        expect(textOf($button))->toBe('Aplicar las 3 elegidas')
            ->and($button->hasAttribute('disabled'))->toBeFalse()
            ->and($button->getAttribute('data-confirm-title'))->toBe('¿Aplicar las 3 sugerencias elegidas?')
            ->and($button->getAttribute('data-confirm-button'))->toBe('Aplicar las 3')
            ->and($button->getAttribute('data-confirm-tone'))->toBe('error')
            ->and($button->getAttribute('x-confirm'))->toBe('Se amplía 1 cupo, se abre 1 grupo y se cierra 1 grupo. El grupo E se cancela y pierde su horario: mar 7:00–9:00. '
                .'Cada una se aplica por separado: si una ya no se puede, se aplican las demás y te dice cuál no.');

        $drawer->call('applyChosen')->assertDispatched('reanalysis-adjusted')->assertSet('chosen', []);
        $opened = Group::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->whereNull('teacher_id')->where('status', '!=', GroupStatus::Cancelled->value)->sole();

        expect(lastToastOf($drawer)['title'])->toBe('Se aplicaron las 3 sugerencias')
            ->and(lastToastOf($drawer)['description'])->toBe("Entran en la versión 2 al publicar. El grupo {$opened->code} de ACF-0902 para ISIC no tiene docente: asígnalo en Asignación docente.")
            ->and($this->a->fresh()->capacity)->toBe(50)
            ->and($this->e->fresh()->status)->toBe(GroupStatus::Cancelled)
            ->and($drawer->get('placements'))->toBe([]);
    });

    it('si una cambió desde que la elegiste, aplica las demás y dice cuál no', function () {
        $drawer = chosenDrawer($this->jefe, $this->world)->set('chosen', [$this->expandKey, $this->closeKey]);
        $teacher = Teacher::factory()->create();
        $this->e->update(['teacher_id' => $teacher->id]);

        $drawer->call('applyChosen')->assertDispatched('reanalysis-adjusted');

        expect(lastToastOf($drawer)['title'])->toBe('Se aplicó 1; 1 no')
            ->and(lastToastOf($drawer)['description'])->toBe("«Cerrar el grupo E»: Primero quita a {$teacher->name} del grupo E en Asignación docente; luego lo puedes cerrar. Lo aplicado entra en la versión 2 al publicar.")
            ->and($this->a->fresh()->capacity)->toBe(50)
            ->and($this->e->fresh()->status)->toBe(GroupStatus::Planned);
    });

    it('una elegida que ya no se sugiere no se aplica y lo dice; si no queda ninguna, no aplica nada', function () {
        $drawer = chosenDrawer($this->jefe, $this->world)->set('chosen', [$this->expandKey, $this->closeKey]);
        chosenExpectIn($this->world, 'iind', 50);

        $drawer->call('applyChosen');

        expect(lastToastOf($drawer)['title'])->toBe('Se aplicó 1; 1 no')
            ->and(lastToastOf($drawer)['description'])->toStartWith('Una ya no se sugiere: la demanda o los grupos cambiaron.')
            ->and($this->e->fresh()->status)->toBe(GroupStatus::Planned);

        chosenExpectIn($this->world, 'iind', 25);
        $drawer = chosenDrawer($this->jefe, $this->world)->set('chosen', [$this->closeKey]);
        chosenExpectIn($this->world, 'iind', 40);
        $drawer->call('applyChosen')->assertNotDispatched('reanalysis-adjusted');

        expect(lastToastOf($drawer)['title'])->toBe('No se aplicó ninguna')
            ->and(lastToastOf($drawer)['description'])->toBe('Una ya no se sugiere: la demanda o los grupos cambiaron.');
    });

    it('dos que cambian el mismo grupo no se eligen juntas: la segunda se deshabilita con el motivo', function () {
        Room::factory()->capacity(70)->create(['building_id' => Building::factory()->create(['campus_id' => $this->world->c2->id])->id, 'code' => 'L-70']);
        chosenExpectIn($this->world, 'isc', 120);
        $roomKey = "change_room:{$this->a->id}:{$this->world->isc->id}";
        $drawer = chosenDrawer($this->jefe, $this->world)->set('chosen', [$this->expandKey]);
        $room = chosenCheckbox($drawer, $roomKey);

        expect($room->hasAttribute('disabled'))->toBeTrue()
            ->and(textOf(domOf($drawer)->querySelector("[data-key=\"{$roomKey}\"] [data-testid=\"suggestion-clash\"]")))->toBe('Choca con «Ampliar el cupo de ISIC en el grupo A de 40 a 50», que ya elegiste: las dos cambian el mismo grupo. Aplícala después, con las sugerencias de ahora.')
            ->and(chosenCheckbox($drawer, $this->closeKey)->hasAttribute('disabled'))->toBeFalse();

        $drawer->set('chosen', [$this->expandKey, $roomKey])->assertSet('chosen', [$this->expandKey]);
    });

    it('la que editas sale del lote: se aplica con su «Aplicar»', function () {
        $drawer = chosenDrawer($this->jefe, $this->world)->set('chosen', [$this->expandKey, $this->closeKey])->call('edit', $this->expandKey);

        expect($drawer->get('chosen'))->toBe([$this->closeKey])
            ->and(chosenCheckbox($drawer, $this->expandKey)->getAttribute('title'))->toBe('La estás editando: aplícala con su «Aplicar».')
            ->and(textOf(chosenApplyButton($drawer)))->toBe('Aplicar la elegida');
    });

    it('con la carga fuera de re-análisis no aplica nada y lo dice', function () {
        $drawer = chosenDrawer($this->jefe, $this->world)->set('chosen', [$this->expandKey]);
        $this->world->load->update(['status' => LoadStatus::Closed]);

        $drawer->call('applyChosen');

        expect(lastToastOf($drawer)['title'])->toBe('No se aplicaron las sugerencias')
            ->and(lastToastOf($drawer)['description'])->toBe('La carga ya no está en re-análisis: las sugerencias ya no se aplican.')
            ->and($this->a->fresh()->capacity)->toBe(40)
            ->and(chosenCheckbox($drawer, $this->expandKey))->toBeNull();
    });

    it('elegir más no lee más, y aplicar calcula las sugerencias dos veces (para aplicar y para pintar), elija las que elija', function () {
        $read = function (Closure $request): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $request();
            DB::disableQueryLog();
            $log = collect(DB::getQueryLog());

            return ['queries' => $log->count(), 'advice' => $log->filter(fn (array $query): bool => str_contains($query['query'], 'sum(max_seats) as held'))->count()];
        };
        $drawer = chosenDrawer($this->jefe, $this->world);
        $read(fn () => $drawer->set('chosen', []));
        $one = $read(fn () => $drawer->set('chosen', [$this->expandKey]));
        $two = $read(fn () => $drawer->set('chosen', [$this->expandKey, $this->closeKey]));
        $applying = $read(fn () => $drawer->call('applyChosen'));

        expect($two)->toBe($one)
            ->and($one['advice'])->toBe(1)
            ->and($applying['advice'])->toBe(2);
    });
});

describe('quien no ajusta (RA-07)', function () {
    it('Subdirección ve las sugerencias sin casillas ni «Aplicar las elegidas», y no elige ni aplica', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
        $drawer = chosenDrawer($subdireccion, $this->world);

        expect(chosenCheckbox($drawer, $this->expandKey))->toBeNull()
            ->and(chosenApplyButton($drawer))->toBeNull();

        chosenDrawer($subdireccion, $this->world)->set('chosen', [$this->expandKey])->assertForbidden();
        chosenDrawer($subdireccion, $this->world)->call('applyChosen')->assertForbidden();

        expect($this->a->fresh()->capacity)->toBe(40);
    });

    it('el coordinador y el jefe de otro departamento no aplican sobre el drawer del jefe', function (string $who) {
        $actor = $who === 'coordinador'
            ? $this->world->coordinator($this->world->isc)
            : User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();
        $drawer = chosenDrawer($this->jefe, $this->world)->set('chosen', [$this->expandKey, $this->closeKey]);
        $this->actingAs($actor);

        $drawer->call('applyChosen')->assertForbidden();

        expect($this->a->fresh()->capacity)->toBe(40)
            ->and($this->e->fresh()->status)->toBe(GroupStatus::Planned);
    })->with(['coordinador', 'otro jefe']);
});
