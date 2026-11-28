<?php

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\Staffing\AssignmentWorld;

/*
| Fase 2b: `request.done` lleva el módulo de la ruta y, en Livewire, el componente y el método llamados.
*/

function moduleLines(ArrayObject $calls): array
{
    return array_values(array_filter($calls->getArrayCopy(), fn (array $c): bool => $c['message'] === 'request.done'));
}

function spyLog(): ArrayObject
{
    $calls = new ArrayObject;
    Log::spy()->shouldReceive('log')->andReturnUsing(function (string $level, string $message, array $context = []) use ($calls): void {
        $calls[] = compact('level', 'message', 'context');
    });

    return $calls;
}

it('etiqueta con su módulo una ruta de módulo', function () {
    $calls = spyLog();

    $this->actingAs(User::factory()->admin()->create())->get(route('staffing.assign'));

    expect(moduleLines($calls)[0]['context']['module'])->toBe('staffing');
});

it('usa el alias kebab', function () {
    $calls = spyLog();

    $this->actingAs(User::factory()->admin()->create())->get(route('academic-load.proposal'));

    expect(moduleLines($calls)[0]['context']['module'])->toBe('academic-load');
});

it('deja core fuera de los módulos y no arrastra el anterior', function () {
    $calls = spyLog();

    $this->actingAs(User::factory()->admin()->create())->get(route('staffing.assign'));
    auth()->logout();
    $this->get('/login');

    $lines = moduleLines($calls);
    expect($lines[1]['context']['module'])->toBe('core')
        ->and($lines[1]['context']['livewire'])->toBeNull();
});

it('etiqueta la llamada de Livewire con su componente', function () {
    $world = AssignmentWorld::create();
    onCampusPage($world->campus);

    Livewire::actingAs(User::factory()->admin()->create())->test('staffing::pages.assign')->call('setTab', 'ranking');

    expect(Context::get('livewire'))->toBe('staffing::pages.assign.setTab')
        ->and(Context::get('module'))->toBe('staffing');
});
