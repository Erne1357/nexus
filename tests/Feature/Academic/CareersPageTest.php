<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| CRUD de Carreras (`/academic/careers`, academic::pages.careers). Solo el admin (reglas-de-negocio.md,
| "Aprobaciones y permisos", fila "Administrar catálogos de Academic"): ve la tabla con su
| departamento, planes y la oferta del campus activo; busca, filtra por departamento y estado, da de
| alta, edita, activa, desactiva, ofrece, deja de ofrecer y borra. Cada acción del componente vuelve a
| autorizar, aunque se llame directo con una página que abrió otra sesión.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica-Electrónica']);

    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales', 'department_id' => $this->sis->id]);
    $this->iel = Career::factory()->create(['code' => 'IEL', 'name' => 'Ingeniería Eléctrica', 'department_id' => $this->ele->id]);
    $this->old = Career::factory()->inactive()->create(['code' => 'OLD', 'name' => 'Carrera Antigua', 'department_id' => $this->sis->id]);

    StudyPlan::factory()->count(2)->create(['career_id' => $this->isc->id]);
    StudyPlan::factory()->create(['career_id' => $this->isc->id])->delete();
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->c2->id]);
    CareerCampus::factory()->inactive()->create(['career_id' => $this->old->id, 'campus_id' => $this->c2->id]);

    $this->admin = User::factory()->admin()->create();
});

/**
 * @param  list<string>  $codes
 * @return Closure(mixed): bool
 */
function listsCareers(array $codes): Closure
{
    return fn ($careers): bool => $careers->pluck('code')->all() === $codes;
}

function seesCareersMenu(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(ScopedContext::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Carreras');
}

describe('admin', function () {
    it('ve la tabla con clave, nombre, departamento, planes sin borrar, oferta en C2 y estado', function () {
        $response = $this->actingAs($this->admin)->get(route('academic.careers'));

        $response->assertOk()
            ->assertSeeInOrder(['Clave', 'Nombre', 'Departamento', 'Planes', 'Se ofrece en C2', 'Estado'])
            ->assertSeeInOrder(['IEL', 'Ingeniería Eléctrica', 'ISC', 'OLD', 'Carrera Antigua', 'Inactiva']);

        $rows = collect(domOf($response)->querySelectorAll('table tbody tr'))
            ->mapWithKeys(fn ($row) => [textOf($row->querySelector('td')) => textOf($row)]);

        expect($rows['ISC'])->toContain('SIS 2 Sí Activa')
            ->and($rows['IEL'])->toContain('ELE 0 No Activa')
            ->and($rows['OLD'])->toContain('SIS 0 No Inactiva');
    });

    it('en el celular apila cada carrera en data-testid="career-cards"', function () {
        $cards = domOf($this->actingAs($this->admin)->get(route('academic.careers')))
            ->querySelectorAll('[data-testid="career-cards"] > li');

        expect(count($cards))->toBe(3)
            ->and(textOf($cards[1]))->toContain('ISC Ingeniería en Sistemas Computacionales Activa')
            ->and(textOf($cards[1]))->toContain('SIS · 2 planes · Se ofrece en C2');
    });

    it('sin campus activo pide elegirlo en lugar de la acción de ofrecer', function () {
        Campus::factory()->create(['code' => 'C1']);

        $response = $this->actingAs($this->admin)->get(route('academic.careers'));

        $response->assertOk()->assertSee('Se ofrece en el campus')->assertSee('Elige un campus en tu credencial')
            ->assertDontSee('Dejar de ofrecer');
    });

    it('con un campus activo elegido muestra su clave y la acción de ofrecer', function () {
        $c1 = Campus::factory()->create(['code' => 'C1']);
        session([ScopedContext::ACTIVE_CAMPUS => $c1->id]);

        $this->actingAs($this->admin)->get(route('academic.careers'))
            ->assertSee('Se ofrece en C1')->assertSee('Ofrecer')->assertDontSee('Elige un campus en tu credencial');
    });

    it('busca por clave o nombre y filtra por departamento y estado', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.careers')
            ->assertViewHas('careers', listsCareers(['IEL', 'ISC', 'OLD']))
            ->set('search', 'antigua')
            ->assertViewHas('careers', listsCareers(['OLD']))
            ->set('search', 'isc')
            ->assertViewHas('careers', listsCareers(['ISC']))
            ->set('search', '')
            ->set('department', $this->sis->id)
            ->assertViewHas('careers', listsCareers(['ISC', 'OLD']))
            ->set('status', 'inactive')
            ->assertViewHas('careers', listsCareers(['OLD']))
            ->set('status', 'active')
            ->assertViewHas('careers', listsCareers(['ISC']))
            ->set('department', 'no-es-uuid')
            ->set('status', '')
            ->assertViewHas('careers', listsCareers(['IEL', 'ISC', 'OLD']));
    });

    it('da de alta una carrera desde el modal y lo confirma con un toast', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('academic::pages.careers')
            ->call('create')
            ->assertSet('showModal', true)
            ->set('code', 'iia')
            ->set('name', 'Inteligencia Artificial')
            ->set('department_id', $this->sis->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect(Career::where('code', 'IIA')->sole())->name->toBe('Inteligencia Artificial')->department_id->toBe($this->sis->id)
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Carrera creada')->description->toBe('IIA · Inteligencia Artificial');
    });

    it('muestra en español los errores de validación, el de clave repetida y el de departamento', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.careers')
            ->call('create')
            ->call('save')
            ->assertHasErrors(['code' => 'required', 'name' => 'required', 'department_id' => 'required'])
            ->assertSee('El campo clave es obligatorio.')
            ->assertSee('El campo departamento es obligatorio.')
            ->set('code', 'isc')
            ->set('name', 'Otra')
            ->set('department_id', $this->sis->id)
            ->call('save')
            ->assertHasErrors('code')
            ->assertSee('Ya existe una carrera con la clave ISC')
            ->set('code', 'nueva')
            ->set('department_id', (string) Str::uuid())
            ->call('save')
            ->assertHasErrors('department_id')
            ->assertSee('El departamento no existe o fue borrado.');

        expect(Career::count())->toBe(3);
    });

    it('edita una carrera', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('academic::pages.careers')
            ->call('edit', $this->iel->id)
            ->assertSet('showModal', true)
            ->assertSet('code', 'IEL')
            ->assertSet('name', 'Ingeniería Eléctrica')
            ->assertSet('department_id', $this->ele->id)
            ->set('name', 'Eléctrica')
            ->set('department_id', $this->sis->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect($this->iel->fresh())->name->toBe('Eléctrica')->department_id->toBe($this->sis->id)
            ->and(lastToastOf($page)['title'])->toBe('Carrera actualizada');
    });

    it('desactiva y reactiva una carrera', function () {
        $page = Livewire::actingAs($this->admin)->test('academic::pages.careers');

        $page->call('toggleActive', $this->iel->id);
        expect($this->iel->fresh()->is_active)->toBeFalse()
            ->and(lastToastOf($page)['title'])->toBe('Carrera desactivada');

        $page->call('toggleActive', $this->iel->id);
        expect($this->iel->fresh()->is_active)->toBeTrue()
            ->and(lastToastOf($page)['title'])->toBe('Carrera activada');
    });

    it('ofrece la carrera en el campus activo y deja de ofrecerla', function () {
        $page = Livewire::actingAs($this->admin)->test('academic::pages.careers');

        $page->call('toggleOffering', $this->iel->id);
        expect(CareerCampus::where('career_id', $this->iel->id)->where('campus_id', $this->c2->id)->sole()->is_active)->toBeTrue()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Carrera ofrecida')->description->toBe('IEL · C2');

        $page->call('toggleOffering', $this->iel->id);
        expect(CareerCampus::where('career_id', $this->iel->id)->sole()->is_active)->toBeFalse()
            ->and(lastToastOf($page)['title'])->toBe('Carrera fuera de la oferta');
    });

    it('ofrecer una carrera inactiva avisa que la oferta nace inactiva', function () {
        $this->iel->update(['is_active' => false]);

        $page = Livewire::actingAs($this->admin)->test('academic::pages.careers')->call('toggleOffering', $this->iel->id);

        expect(CareerCampus::where('career_id', $this->iel->id)->sole()->is_active)->toBeFalse()
            ->and(lastToastOf($page))->type->toBe('warning')->title->toBe('Oferta registrada, inactiva');
    });

    it('sin campus activo no ofrece nada y lo dice', function () {
        Campus::factory()->create(['code' => 'C1']);

        $page = Livewire::actingAs($this->admin)->test('academic::pages.careers')->call('toggleOffering', $this->iel->id);

        expect(CareerCampus::where('career_id', $this->iel->id)->count())->toBe(0)
            ->and(lastToastOf($page))->type->toBe('error')->title->toBe('Elige un campus');
    });

    it('borra una carrera sin planes', function () {
        $page = Livewire::actingAs($this->admin)->test('academic::pages.careers')->call('delete', $this->iel->id);

        expect(Career::find($this->iel->id))->toBeNull()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Carrera borrada');
    });

    it('con planes no borra y muestra el error', function () {
        $page = Livewire::actingAs($this->admin)->test('academic::pages.careers')->call('delete', $this->isc->id);

        expect(Career::find($this->isc->id))->not->toBeNull()
            ->and(lastToastOf($page))->type->toBe('error')->description->toBe('No se puede borrar: tiene 3 planes de estudio, aunque estén borrados.');
    });

    it('un id que no es uuid es una carrera que no existe', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.careers')
            ->call('edit', 'no-es-uuid')
            ->assertNotFound();
    });

    it('carga 30 carreras con sus conteos y su oferta en pocas consultas', function () {
        Career::factory()->count(27)->create(['department_id' => $this->sis->id])->each(function (Career $career) {
            StudyPlan::factory()->create(['career_id' => $career->id]);
            CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $this->c2->id]);
        });

        $this->actingAs($this->admin);
        DB::enableQueryLog();
        $this->get(route('academic.careers'))->assertOk();
        $queries = count(DB::getQueryLog());

        expect($queries)->toBeLessThanOrEqual(12);
    });
});

describe('otros roles e invitados', function () {
    $roles = [
        'subdirección' => [Role::Subdireccion, []],
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'docente' => [Role::Docente, []],
    ];

    it('reciben 403 en la página', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

        $this->actingAs($user)->get(route('academic.careers'))->assertForbidden();
    })->with($roles);

    it('reciben 403 en cada método del componente aunque lo llamen directo con una página que abrió el admin', function (string $method, bool $editFirst) {
        $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
        $page = Livewire::actingAs($this->admin)->test('academic::pages.careers');
        $careers = Career::count();
        $offerings = CareerCampus::count();

        if ($editFirst) {
            $page->call('edit', $this->iel->id);
        }

        $this->actingAs($user);
        $page->call($method, ...(in_array($method, ['edit', 'toggleActive', 'toggleOffering', 'delete'], true) ? [$this->iel->id] : []))->assertForbidden();

        expect(Career::count())->toBe($careers)
            ->and(CareerCampus::count())->toBe($offerings)
            ->and($this->iel->fresh()->is_active)->toBeTrue()
            ->and(Activity::whereIn('subject_type', [$this->iel->getMorphClass(), (new CareerCampus)->getMorphClass()])->count())->toBe(0);
    })->with([
        'nuevo' => ['create', false],
        'editar' => ['edit', false],
        'guardar un alta' => ['save', false],
        'guardar una edición' => ['save', true],
        'activar o desactivar' => ['toggleActive', false],
        'ofrecer o dejar de ofrecer' => ['toggleOffering', false],
        'borrar' => ['delete', false],
    ]);

    it('un invitado va al login', function () {
        $this->get(route('academic.careers'))->assertRedirect('/login');
    });
});

describe('menú', function () {
    it('la entrada Carreras apunta a la página y la ve solo el admin', function () {
        $item = collect(app(Navigation::class)->items())->firstWhere('label', 'Carreras');
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();

        expect($item)->route->toBe('academic.careers')->icon->toBe('o-academic-cap')
            ->and(route('academic.careers', absolute: false))->toBe('/academic/careers')
            ->and(seesCareersMenu($this->admin))->toBeTrue()
            ->and(seesCareersMenu($coordinador))->toBeFalse();
    });
});
