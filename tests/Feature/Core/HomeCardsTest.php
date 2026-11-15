<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\HomeCardsRequested;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\HomeCard;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
| Inicio pinta las tarjetas de lo que le toca hacer a la persona, que ponen los módulos al contestar HomeCardsRequested
| (evento con reporte, de solo lectura): Core no los conoce. Solo al personal; con el periodo activo.
*/

it('pinta las tarjetas que agregan los módulos, con su enlace, antes del bloque del puesto', function () {
    $term = Term::factory()->current()->create();
    $user = User::factory()->withRole(Role::Subdireccion, [])->create();
    Event::listen(HomeCardsRequested::class, function (HomeCardsRequested $event) use ($user, $term): void {
        expect($event->context->user()?->is($user))->toBeTrue()
            ->and($event->term?->is($term))->toBeTrue();

        $event->add(new HomeCard('prueba', 'Algo por hacer', 'Tienes 2 pendientes', 'Ir a hacerlo', 'https://nexus.test/hacer', 'o-bolt'));
    });

    actingAsRole($user, Role::Subdireccion);

    Livewire::test('core::pages.home')
        ->assertSeeHtml('data-testid="home-card-prueba"')
        ->assertSeeInOrder(['Algo por hacer', 'Tienes 2 pendientes', 'Ir a hacerlo', 'Subdirección'])
        ->assertSeeHtml('href="https://nexus.test/hacer"');
});

it('al alumno no le pide tarjetas', function () {
    Term::factory()->current()->create();
    $asked = false;
    Event::listen(HomeCardsRequested::class, function () use (&$asked): void {
        $asked = true;
    });

    actingAsRole(User::factory()->withRole(Role::Alumno, [])->create(), Role::Alumno);

    Livewire::test('core::pages.home')->assertDontSeeHtml('data-testid="home-card-');
    expect($asked)->toBeFalse();
});

it('sin periodo activo pregunta igual, con el periodo nulo', function () {
    $terms = [];
    Event::listen(HomeCardsRequested::class, function (HomeCardsRequested $event) use (&$terms): void {
        $terms[] = $event->term;
    });

    actingAsRole(User::factory()->withRole(Role::Subdireccion, [])->create(), Role::Subdireccion);
    Livewire::test('core::pages.home')->assertOk();

    expect($terms)->toBe([null]);
});
