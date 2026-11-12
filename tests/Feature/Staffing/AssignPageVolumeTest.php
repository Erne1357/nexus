<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Staffing\AssignmentWorld;

/*
| «Asignación docente» con volumen (tarea 3.9, revisión con datos masivos): el ranking muestra primero a quienes pueden
| tomar el grupo y pliega el bloque final; dice en qué orden va; un candidato con marca no se presenta como la
| recomendación; «Por materia» abre en «Sin docente» mientras falte alguno, y «Siguiente sin docente» lleva al que sigue.
*/

beforeEach(function () {
    $this->world = AssignmentWorld::create();
    $isc = $this->world->career('ISC');
    $poo = $this->world->subject('SCC-1013', 'Programación Orientada a Objetos');

    $this->group = $this->world->group($poo, $isc, 'D', 2, [[1, '10:00', '11:00']]);
    $this->blocked = $this->world->group($poo, $isc, 'G', 2, [[2, '13:00', '15:00']]);

    $this->teachers = collect(['Ana Ruiz', 'Beto Salas', 'Carla Díaz', 'Dante Ortiz', 'Elsa Núñez', 'Fausto Gil'])
        ->map(fn (string $name) => $this->world->teacher($name));
    $this->world->rank($this->group, $this->teachers->all(), [
        3 => ['flags' => ['choque']],
        4 => ['flags' => ['sin_horas', 'choque']],
        5 => ['flags' => ['no_disponible']],
    ]);
    $this->world->rank($this->blocked, $this->teachers->take(3)->all(), [
        ['flags' => ['no_disponible']],
        ['flags' => ['choque']],
        ['flags' => ['choque', 'no_disponible']],
    ]);

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();

    $this->staffed = $this->world->group($poo, $isc, 'E', 2, [[4, '10:00', '11:00']]);
    $this->world->rank($this->staffed, $this->teachers->take(3)->all());
    $this->world->assign($this->staffed, $this->teachers[0], $this->jefe);
});

/**
 * @param  array<string, string>  $query
 */
function volumeAssignPage(User $user, array $query = []): Testable
{
    onCampusPage(test()->world->campus);

    return Livewire::withQueryParams($query)->actingAs($user)->test('staffing::pages.assign');
}

describe('ranking completo', function () {
    it('muestra a quienes pueden tomar el grupo y pliega el bloque final en un renglón con sus cuentas', function () {
        volumeAssignPage($this->jefe)
            ->call('toggleRanking', $this->group->id)
            ->assertSeeHtmlInOrder(['Ana Ruiz', 'Beto Salas', 'Carla Díaz', 'data-testid="flagged-tier"'])
            ->assertSeeText('3 docentes no pueden tomarlo (1 sin horas, 2 choque, 1 no disponible)')
            ->assertDontSee('Dante Ortiz')
            ->assertDontSee('Fausto Gil')
            ->call('toggleFinalTier', $this->group->id)
            ->assertSeeHtmlInOrder(['data-testid="flagged-tier"', 'Dante Ortiz', 'Elsa Núñez', 'Fausto Gil'])
            ->call('toggleFinalTier', $this->group->id)
            ->assertDontSee('Dante Ortiz');
    });

    it('pliega el bloque final también en los dos paneles', function () {
        volumeAssignPage($this->jefe, ['vista' => 'paneles', 'grupo' => $this->group->id])
            ->assertSeeText('3 docentes no pueden tomarlo')
            ->assertDontSee('Dante Ortiz')
            ->call('toggleFinalTier', $this->group->id)
            ->assertSee('Dante Ortiz');
    });

    it('abierto en la vista por materia, repite «Ocultar ranking» al pie', function () {
        volumeAssignPage($this->jefe)
            ->assertDontSeeHtml('data-testid="hide-ranking-bottom"')
            ->call('toggleRanking', $this->group->id)
            ->assertSeeHtml('data-testid="hide-ranking-bottom"');
    });

    it('dice en qué orden va el ranking, en la barra de la vista por materia y junto al ranking', function () {
        volumeAssignPage($this->jefe)
            ->assertSeeText('Orden: horas base › historial y perfil › evaluación')
            ->call('toggleRanking', $this->group->id)
            ->assertSeeHtml('data-testid="ranking-order"');
    });
});

describe('recomendación', function () {
    it('el primer lugar sin marcas lleva el trato de recomendado y un candidato con marca no', function () {
        volumeAssignPage($this->jefe)
            ->assertSeeHtml('data-recommended="'.$this->group->id.'"')
            ->assertDontSeeHtml('data-recommended="'.$this->blocked->id.'"')
            ->assertSeeText('Ninguno sin marcas: estos son los primeros del bloque final.');
    });
});

it('SC-09: el traslado justo se ve como marca discreta, sin bajar al docente al bloque final', function () {
    $this->world->rank($group = $this->world->group($this->group->subject, $this->group->ownerCareer, 'H', 2, [[3, '16:00', '17:00']]), [$this->teachers[0], $this->teachers[1]], [
        ['flags' => ['traslado_justo']],
    ]);

    volumeAssignPage($this->jefe)
        ->assertSeeText('traslado justo')
        ->assertSeeHtml('data-recommended="'.$group->id.'"')
        ->call('toggleRanking', $group->id)
        ->assertDontSeeHtml('data-testid="flagged-tier"');
});

describe('filtro de entrada', function () {
    it('«Por materia» abre en «Sin docente» mientras la carga se asigna y falta algún docente', function () {
        volumeAssignPage($this->jefe)
            ->assertSet('state', 'sin')
            ->assertSeeHtml('data-group="'.$this->group->id.'"')
            ->assertDontSeeHtml('data-group="'.$this->staffed->id.'"');
    });

    it('?estado= manda sobre el de entrada', function () {
        volumeAssignPage($this->jefe, ['estado' => 'todos'])
            ->assertSet('state', 'todos')
            ->assertSeeHtml('data-group="'.$this->staffed->id.'"');
    });

    it('abre en «Todos» con ?grupo=, en los dos paneles, en propuesta o sin grupos por asignar', function (Closure $setup, array $query) {
        $setup->call($this);

        volumeAssignPage($this->jefe, $query)->assertSet('state', 'todos');
    })->with([
        'con ?grupo=' => [fn () => null, fn () => ['grupo' => test()->staffed->id]],
        'dos paneles' => [fn () => null, ['vista' => 'paneles']],
        'en propuesta' => [fn () => test()->world->load->update(['status' => LoadStatus::Proposed]), []],
        'todos con docente' => [function () {
            test()->world->assign(test()->group, test()->teachers[0], test()->jefe);
            test()->world->assign(test()->blocked, test()->teachers[1], test()->jefe);
        }, []],
    ]);
});

describe('siguiente sin docente', function () {
    it('en la vista por materia cada grupo lleva al siguiente sin docente, y el último vuelve al primero', function () {
        volumeAssignPage($this->jefe, ['estado' => 'todos'])
            ->assertSeeHtml('data-from="'.$this->group->id.'" data-next-group="'.$this->blocked->id.'"')
            ->assertSeeHtml('data-from="'.$this->staffed->id.'" data-next-group="'.$this->blocked->id.'"')
            ->assertSeeHtml('data-from="'.$this->blocked->id.'" data-next-group="'.$this->group->id.'"');
    });

    it('con «Sin docente» va al pie del ranking abierto, donde el siguiente queda lejos', function () {
        volumeAssignPage($this->jefe)
            ->assertDontSeeHtml('data-testid="next-without-teacher"')
            ->call('toggleRanking', $this->group->id)
            ->assertSeeHtml('data-from="'.$this->group->id.'" data-next-group="'.$this->blocked->id.'"');
    });

    it('en los dos paneles elige el siguiente grupo sin docente de la lista', function () {
        volumeAssignPage($this->jefe, ['vista' => 'paneles', 'grupo' => $this->staffed->id])
            ->assertSeeHtml('data-from="'.$this->staffed->id.'" data-next-group="'.$this->blocked->id.'"')
            ->call('pick', $this->blocked->id)
            ->assertSeeHtml('data-from="'.$this->blocked->id.'" data-next-group="'.$this->group->id.'"');
    });
});
