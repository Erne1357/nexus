<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\PageTrailQuery;

/*
| PageTrailQuery (tarea 1.23, #694): lo que la banda pone después de «Nexus» (el área y el título de la pantalla). Lo
| leen la banda y la respuesta del login, que con él guarda el lugar de la ruta en la banda a la que llega la credencial;
| para el login dice además si la página a la que sigue tiene banda.
*/

function trailFor(User $user, string $url): ?array
{
    test()->actingAs($user);

    return app(PageTrailQuery::class)->forUrl($url, app(Actor::class));
}

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->admin = User::factory()->admin()->create();
});

it('da el área de la entrada del menú y el título de la pantalla, aunque la entrada se llame distinto', function () {
    $term = Term::factory()->current()->create();

    expect(trailFor($this->admin, route('core.terms.show', $term)))
        ->toBe(['area' => NavigationArea::Administracion->label(), 'title' => 'Ficha del periodo']);
});

it('una pantalla sin entrada en el menú lleva solo su título', function () {
    expect(trailFor($this->admin, url('/')))->toBe(['area' => null, 'title' => 'Inicio']);
});

it('una página pública, con otro layout, no tiene banda', function () {
    expect(trailFor($this->admin, route('published.index')))->toBeNull();
});

it('una pantalla que el puesto no ve en el menú no tiene banda para él', function () {
    $coordinador = User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create();

    expect(trailFor($coordinador, route('core.campuses')))->toBeNull()
        ->and(trailFor($this->admin, route('core.campuses')))->toBe(['area' => NavigationArea::Administracion->label(), 'title' => 'Campus']);
});

it('una descarga o una dirección que no existe no tienen banda', function () {
    expect(trailFor($this->admin, url('/horarios/20271/PRES/exportes/plantilla')))->toBeNull()
        ->and(trailFor($this->admin, url('/no-existe')))->toBeNull();
});

it('para la banda usa el título que le da la pantalla y, sin él, el nombre de la entrada', function () {
    $this->actingAs($this->admin);
    $trails = app(PageTrailQuery::class);

    expect($trails->of('core.terms.show', 'Ficha del periodo', app(Actor::class)))->toBe(['area' => NavigationArea::Administracion->label(), 'title' => 'Ficha del periodo'])
        ->and($trails->of('core.terms.show', null, app(Actor::class)))->toBe(['area' => NavigationArea::Administracion->label(), 'title' => 'Periodos'])
        ->and($trails->of(null, null, app(Actor::class)))->toBe(['area' => null, 'title' => null]);
});
