<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Staffing\AssignmentWorld;

/*
| «Asignación docente» con volumen (tarea 3.9, revisión con datos masivos): el ranking muestra primero a quienes pueden
| tomar el grupo y pliega el bloque final; dice en qué orden va; un candidato con marca no se presenta como la
| recomendación.
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
});

/**
 * @param  array<string, string>  $query
 */
function volumeAssignPage(User $user, array $query = []): Testable
{
    test()->withSession([Actor::ACTIVE_CAMPUS => test()->world->campus->id]);

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
