<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Testing\TestResponse;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| CRUD de Campus (`/campus`, core::pages.campuses). Quién hace qué, según reglas-de-negocio.md,
| "Aprobaciones y permisos"): el campus es solo el lugar (MS3, supuesto por confirmar): código, nombre y estado, sin
| modalidad ni perfil de flujo, que se administran en Modalidades. El admin da de alta, edita y activa o desactiva
| cualquier campus; la subdirección ve los de su alcance y no edita; los demás roles no entran. Cada acción del
| componente vuelve a autorizar, aunque se llame directo con una página que abrió otra sesión.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->ead = Campus::factory()->inactive()->create(['code' => 'EAD', 'name' => 'Educación a Distancia']);

    $this->admin = User::factory()->admin()->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id])->create();
});

/**
 * @return Closure(mixed): bool
 */
function listsCampuses(array $codes): Closure
{
    return fn ($campuses): bool => $campuses->pluck('code')->all() === $codes;
}

/**
 * La página como DOM, para revisar clases y estructura y no solo el texto.
 */
function campusPageDom(TestResponse $response): HTMLDocument
{
    return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
}

/**
 * Las filas de la tabla (de 768 px en adelante), por la clave de su campus.
 *
 * @return array<string, Element>
 */
function campusTableRowsOn(TestResponse $response): array
{
    $rows = [];

    foreach (campusPageDom($response)->querySelectorAll('table tbody tr') as $row) {
        $rows[trim($row->querySelector('td')->textContent)] = $row;
    }

    return $rows;
}

/**
 * Los renglones apilados del celular, por la clave de su campus.
 *
 * @return array<string, Element>
 */
function campusCardsOn(TestResponse $response): array
{
    $cards = [];

    foreach (campusPageDom($response)->querySelectorAll('[data-testid="campus-cards"] > li') as $card) {
        $cards[trim($card->querySelector('.font-mono')->textContent)] = $card;
    }

    return $cards;
}

/**
 * @return list<string>
 */
function campusClassesOf(?Element $element): array
{
    return $element === null ? [] : iterator_to_array($element->classList, false);
}

/**
 * El texto visible de un elemento, con los espacios juntos.
 */
function campusTextOf(Element $element): string
{
    return trim(preg_replace('/\s+/u', ' ', $element->textContent));
}

function seesCampusMenu(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(Actor::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Campus');
}

describe('admin', function () {
    it('ve la tabla con código, nombre y estado de todos los campus, sin modalidad ni perfil de flujo', function () {
        $this->actingAs($this->admin)
            ->get(route('core.campuses'))
            ->assertOk()
            ->assertSeeInOrder(['Código', 'Nombre', 'Estado'])
            ->assertSeeInOrder(['C1', 'Campus I', 'Activo', 'C2', 'Campus II', 'EAD', 'Educación a Distancia', 'Inactivo'])
            ->assertDontSee('Perfil de flujo')
            ->assertDontSee('Salones · bloques');
    });

    it('pinta la clave en Mono, el estado con su insignia y la fila inactiva en gris y sin negritas', function () {
        $rows = campusTableRowsOn($this->actingAs($this->admin)->get(route('core.campuses')));

        expect(array_keys($rows))->toBe(['C1', 'C2', 'EAD']);

        expect(campusClassesOf($rows['C1']))->not->toContain('text-neutral')
            ->and(campusClassesOf($rows['C1']->querySelector('td .font-mono')))->toContain('font-semibold')
            ->and(campusClassesOf($badge = $rows['C1']->querySelector('.badge')))->toContain('badge-success', 'badge-soft')
            ->and(trim($badge->textContent))->toBe('Activo');

        expect(campusClassesOf($rows['EAD']))->toContain('text-neutral')
            ->and(campusClassesOf($rows['EAD']->querySelector('td .font-mono')))->not->toContain('font-semibold')
            ->and(campusClassesOf($badge = $rows['EAD']->querySelector('.badge')))->toContain('badge-neutral', 'badge-soft')
            ->and(trim($badge->textContent))->toBe('Inactivo');
    });

    it('en el celular apila cada campus: clave, nombre y estado; luego las acciones', function () {
        $cards = campusCardsOn($this->actingAs($this->admin)->get(route('core.campuses')));

        expect(array_keys($cards))->toBe(['C1', 'C2', 'EAD'])
            ->and(campusTextOf($cards['C1']))->toBe('C1 Campus I Activo Editar Acciones Desactivar')
            ->and(campusTextOf($cards['EAD']))->toBe('EAD Educación a Distancia Inactivo Editar Acciones Activar')
            ->and(campusClassesOf($cards['EAD']))->toContain('text-neutral')
            ->and(campusClassesOf($cards['EAD']->querySelector('.font-mono')))->not->toContain('font-semibold')
            ->and(campusClassesOf($cards['EAD']->querySelector('.badge')))->toContain('badge-neutral', 'badge-soft')
            ->and(campusClassesOf($cards['C1']->querySelector('.font-mono')))->toContain('font-semibold')
            ->and(campusClassesOf($cards['C1']->querySelector('.badge')))->toContain('badge-success', 'badge-soft');
    });

    it('resume al pie qué campus muestra la página y de cuántos, y pagina de 15 en 15', function () {
        foreach (range(1, 15) as $number) {
            Campus::factory()->create(['code' => sprintf('X%02d', $number)]);
        }

        Livewire::actingAs($this->admin)
            ->test('core::pages.campuses')
            ->assertSeeText('Campus C1 a X12 · 1–15 de 18')
            ->assertSee('Ir a la página 2')
            ->call('gotoPage', 2)
            ->assertSeeText('Campus X13 a X15 · 16–18 de 18');

        Livewire::actingAs($this->admin)
            ->test('core::pages.campuses')
            ->set('search', 'C1')
            ->assertSeeText('Campus C1 · 1 de 1')
            ->assertDontSee('Ir a la página 2');
    });

    it('en una página que quedó vacía (por desactivar el último o un enlace viejo) deja volver a las otras', function () {
        foreach (range(1, 15) as $number) {
            Campus::factory()->create(['code' => sprintf('X%02d', $number)]);
        }

        Livewire::actingAs($this->admin)
            ->test('core::pages.campuses')
            ->call('gotoPage', 3)
            ->assertSee('Ningún campus coincide con la búsqueda.')
            ->assertDontSeeText('Campus C1 a')
            ->assertSee('Ir a la página 1')
            ->assertSee('Ir a la página 2');
    });

    it('busca por código o nombre y filtra activos o inactivos', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.campuses')
            ->assertViewHas('campuses', listsCampuses(['C1', 'C2', 'EAD']))
            ->set('search', 'distancia')
            ->assertViewHas('campuses', listsCampuses(['EAD']))
            ->set('search', '')
            ->set('status', 'inactive')
            ->assertViewHas('campuses', listsCampuses(['EAD']))
            ->set('status', 'active')
            ->assertViewHas('campuses', listsCampuses(['C1', 'C2']));
    });

    it('MS3, MS21: da de alta un campus desde el modal con solo código y nombre: es solo el lugar', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.campuses')
            ->call('create')
            ->assertSet('showModal', true)
            ->assertDontSee('Perfil de flujo')
            ->assertDontSeeHtml('wire:model="modality"')
            ->assertSeeHtml('wire:model="code"')
            ->set('code', 'c3')
            ->set('name', 'Campus III')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $campus = Campus::where('code', 'C3')->sole();

        expect($campus)
            ->name->toBe('Campus III')
            ->is_active->toBeTrue()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Campus creado')->description->toBe('C3 · Campus III');
    });

    it('edita código y nombre de un campus', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.campuses')
            ->call('edit', $this->c2->id)
            ->assertSet('showModal', true)
            ->assertSet('code', 'C2')
            ->assertSet('name', 'Campus II')
            ->set('name', 'Campus Dos')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect($this->c2->fresh())
            ->name->toBe('Campus Dos')
            ->and(lastToastOf($page)['title'])->toBe('Campus actualizado');
    });

    it('el componente ya no tiene modalidad ni perfil que el navegador pueda mandar', function () {
        $page = Livewire::actingAs($this->admin)->test('core::pages.campuses')->call('edit', $this->c2->id);

        expect(fn () => $page->set('modality', 'mixta'))->toThrow(PublicPropertyNotFoundException::class)
            ->and(fn () => $page->set('settings', ['uses_rooms' => false]))->toThrow(PublicPropertyNotFoundException::class);
    });

    it('desactiva y reactiva un campus', function () {
        $page = Livewire::actingAs($this->admin)->test('core::pages.campuses');

        $page->call('toggleActive', $this->c2->id);
        expect($this->c2->fresh()->is_active)->toBeFalse()
            ->and(lastToastOf($page)['title'])->toBe('Campus desactivado');

        $page->call('toggleActive', $this->c2->id);
        expect($this->c2->fresh()->is_active)->toBeTrue()
            ->and(lastToastOf($page)['title'])->toBe('Campus activado');
    });

    it('muestra en español los errores de validación del formulario', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.campuses')
            ->call('create')
            ->set('code', '')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['code' => 'required', 'name' => 'required'])
            ->assertSee('El campo código es obligatorio.')
            ->assertSee('El campo nombre es obligatorio.')
            ->assertSet('showModal', true);

        expect(Campus::count())->toBe(3);
    });

    it('muestra en español la regla de la Action: código repetido', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.campuses')
            ->call('create')
            ->set('code', 'c1')
            ->set('name', 'Otro campus')
            ->call('save')
            ->assertHasErrors('code')
            ->assertSee('Ya existe un campus con el código C1, aunque esté desactivado o borrado.');

        expect(Campus::count())->toBe(3);
    });
});

describe('subdirección', function () {
    it('entra y ve solo los campus de su alcance', function () {
        $this->actingAs($this->subdireccion)->get(route('core.campuses'))->assertOk();

        Livewire::actingAs($this->subdireccion)
            ->test('core::pages.campuses')
            ->assertViewHas('campuses', listsCampuses(['C1']))
            ->assertDontSee('Campus II');
    });

    it('no edita, no da de alta ni activa aunque llame directo al componente: el campus ya no tiene perfil que editar', function () {
        Livewire::actingAs($this->subdireccion)->test('core::pages.campuses')->call('edit', $this->c1->id)->assertForbidden();
        Livewire::actingAs($this->subdireccion)->test('core::pages.campuses')->call('create')->assertForbidden();
        Livewire::actingAs($this->subdireccion)->test('core::pages.campuses')->call('toggleActive', $this->c1->id)->assertForbidden();
        Livewire::actingAs($this->subdireccion)->test('core::pages.campuses')
            ->set('code', 'C9')->set('name', 'Campus nuevo')->call('save')->assertForbidden();

        expect(Campus::count())->toBe(3)->and($this->c1->fresh()->name)->toBe('Campus I')->and(Activity::count())->toBe(0);
    });

    it('la de C1 no ve ni edita C2, ni con la página que otra sesión dejó abierta en C2', function () {
        Livewire::actingAs($this->subdireccion)->test('core::pages.campuses')
            ->assertViewHas('campuses', listsCampuses(['C1']))
            ->call('edit', $this->c2->id)
            ->assertForbidden();

        $opened = Livewire::actingAs($this->admin)->test('core::pages.campuses')->call('edit', $this->c2->id);
        $this->actingAs($this->subdireccion);
        $opened->set('name', 'Otro nombre')->call('save')->assertForbidden();

        expect($this->c2->fresh())->name->toBe('Campus II')->is_active->toBeTrue();
    });

    it('ve deshabilitados, con su motivo, los controles que no puede usar', function () {
        Livewire::actingAs($this->subdireccion)
            ->test('core::pages.campuses')
            ->assertSee('Solo el administrador da de alta campus.')
            ->assertSee('Solo el administrador ve los campus inactivos.')
            ->assertSee('Solo el administrador activa o desactiva campus.')
            ->assertSee('Solo el administrador edita campus.');
    });
});

describe('otros roles e invitados', function () {
    $roles = [
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)]],
        'docente' => [Role::Docente, []],
    ];

    it('reciben 403 en la página', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->c1->id, ...$scope])->create();

        $this->actingAs($user)->get(route('core.campuses'))->assertForbidden();
    })->with($roles);

    it('reciben 403 en cada acción del componente aunque la llamen directo con una página que abrió el admin', function (Role $role, array $scope, string $method, bool $editFirst) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->c1->id, ...$scope])->create();
        $page = Livewire::actingAs($this->admin)->test('core::pages.campuses');

        if ($editFirst) {
            $page->call('edit', $this->c1->id);
        }

        $this->actingAs($user);
        $page->call($method, ...(in_array($method, ['edit', 'toggleActive'], true) ? [$this->c1->id] : []))->assertForbidden();

        expect(Campus::count())->toBe(3)
            ->and($this->c1->fresh()->is_active)->toBeTrue()
            ->and(Activity::count())->toBe(0);
    })->with($roles)->with([
        'nuevo campus' => ['create', false],
        'editar' => ['edit', false],
        'activar o desactivar' => ['toggleActive', false],
        'guardar un alta' => ['save', false],
        'guardar una edición' => ['save', true],
    ]);

    it('un invitado va al login', function () {
        $this->get(route('core.campuses'))->assertRedirect('/login');
    });
});

describe('menú', function () {
    it('la entrada Campus apunta a la página de campus', function () {
        $item = collect(app(Navigation::class)->items())->firstWhere('label', 'Campus');

        expect($item)
            ->route->toBe('core.campuses')
            ->icon->toBe('o-building-office-2')
            ->and(route('core.campuses', absolute: false))->toBe('/campus');
    });

    it('la ven el admin y la subdirección', function () {
        expect(seesCampusMenu($this->admin))->toBeTrue()
            ->and(seesCampusMenu($this->subdireccion))->toBeTrue();
    });

    it('no la ven los demás roles', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->c1->id, ...$scope])->create();

        expect(seesCampusMenu($user))->toBeFalse();
    })->with([
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)]],
        'docente' => [Role::Docente, []],
    ]);
});
