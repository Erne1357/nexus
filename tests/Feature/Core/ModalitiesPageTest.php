<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Actions\UpdateModality;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Modalidades (`/modalidades`, core::pages.modalities): el catálogo con el perfil de flujo de cada una (MS1, supuesto por
| confirmar; M3), con la forma de Campus: tabla y un panel lateral (x-drawer) con el perfil. El admin da de alta, edita,
| activa y desactiva; la subdirección ve y edita solo el perfil de flujo; los demás roles no entran. Cada acción del
| componente vuelve a autorizar. Mientras Scheduling lea `campuses.settings`, guardar el perfil de una modalidad lo
| escribe también en sus campus por el puente `modality_id` (temporal, hasta el paso de contracción).
*/

beforeEach(function () {
    $this->pres = modalityOf('PRES');
    $this->ead = modalityOf('EAD');
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);

    $this->admin = User::factory()->admin()->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id])->create();
});

/**
 * @return Closure(mixed): bool
 */
function listsModalities(array $codes): Closure
{
    return fn ($modalities): bool => $modalities->pluck('code')->all() === $codes;
}

describe('admin', function () {
    it('ve la tabla con clave, modalidad, perfil de flujo y estado de las cuatro modalidades', function () {
        $this->actingAs($this->admin)
            ->get(route('core.modalities'))
            ->assertOk()
            ->assertSeeInOrder([
                'Clave', 'Modalidad', 'Perfil de flujo', 'Estado',
                'PRES', 'Presencial', 'Salones · bloques de 60 min · lunes a viernes, 7:00 a 21:00', 'Activa',
                'EAD', 'EAD', 'Salones · bloques de 60 min · lunes a sábado, 7:00 a 21:00 · asignación central', 'Inactiva',
                'ENL', 'En línea', 'Sin salones',
                'TNMV', 'TecNM Virtual', 'docentes externos',
            ])
            ->assertSeeText('4 modalidades');
    });

    it('busca por clave o nombre y filtra activas o inactivas', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.modalities')
            ->assertViewHas('modalities', listsModalities(['PRES', 'EAD', 'ENL', 'TNMV']))
            ->set('search', 'virtual')
            ->assertViewHas('modalities', listsModalities(['TNMV']))
            ->set('search', '')
            ->set('status', 'active')
            ->assertViewHas('modalities', listsModalities(['PRES']))
            ->set('status', 'inactive')
            ->assertViewHas('modalities', listsModalities(['EAD', 'ENL', 'TNMV']));
    });

    it('da de alta una modalidad desde el panel y lo confirma con un toast', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.modalities')
            ->call('create')
            ->assertSet('showDrawer', true)
            ->assertSet('settings', ModalityProfile::DEFAULTS)
            ->set('code', 'sem')
            ->set('name', 'Semipresencial')
            ->set('settings.uses_rooms', false)
            ->set('settings.block_minutes', '30')
            ->set('settings.days', ['1', '2', '3', '4', '5', '6'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showDrawer', false);

        $modality = Modality::where('code', 'SEM')->sole();

        expect($modality)
            ->name->toBe('Semipresencial')
            ->is_active->toBeTrue()
            ->profile->usesRooms->toBeFalse()
            ->and($modality->profile->blockMinutes)->toBe(30)
            ->and($modality->profile->days)->toBe([1, 2, 3, 4, 5, 6])
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Modalidad creada')->description->toBe('SEM · Semipresencial')
            ->and(Activity::where('subject_id', $modality->id)->count())->toBe(1);
    });

    it('edita los datos y el perfil de flujo de una modalidad', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.modalities')
            ->call('edit', $this->ead->id)
            ->assertSet('showDrawer', true)
            ->assertSet('canEditData', true)
            ->assertSet('code', 'EAD')
            ->set('name', 'EaD')
            ->set('settings.uses_rooms', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showDrawer', false);

        expect($this->ead->fresh())
            ->name->toBe('EaD')
            ->is_active->toBeFalse()
            ->profile->usesRooms->toBeFalse()
            ->and(lastToastOf($page)['title'])->toBe('Modalidad actualizada');
    });

    it('activa y desactiva una modalidad desde la tabla', function () {
        $page = Livewire::actingAs($this->admin)->test('core::pages.modalities');

        $page->call('toggleActive', $this->ead->id);
        expect($this->ead->fresh()->is_active)->toBeTrue()
            ->and(lastToastOf($page)['title'])->toBe('Modalidad activada');

        $page->call('toggleActive', $this->ead->id);
        expect($this->ead->fresh()->is_active)->toBeFalse()
            ->and(lastToastOf($page)['title'])->toBe('Modalidad desactivada');
    });

    it('resume el perfil que se está editando en una línea', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.modalities')
            ->call('edit', $this->pres->id)
            ->assertSee('Así se resume')
            ->assertSee('Salones · bloques de 60 min · lunes a viernes, 7:00 a 21:00')
            ->set('settings.uses_rooms', false)
            ->assertSee('Sin salones · bloques de 60 min');
    });

    it('rechaza un perfil inválido con el error en su campo, de forma y de reglas', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.modalities')
            ->call('create')
            ->set('code', '')
            ->set('name', '')
            ->set('settings.day_start', '7am')
            ->set('settings.block_minutes', '0')
            ->set('settings.days', [])
            ->call('save')
            ->assertHasErrors([
                'code' => 'required',
                'name' => 'required',
                'settings.day_start' => 'date_format',
                'settings.block_minutes' => 'min',
                'settings.days' => 'required',
            ])
            ->assertSee('El campo clave es obligatorio.')
            ->assertSee('El campo inicio de la jornada debe ser una hora con el formato HH:MM.')
            ->assertSet('showDrawer', true);

        $page->set('code', 'X1')
            ->set('name', 'Otra')
            ->set('settings.day_start', '07:00')
            ->set('settings.block_minutes', '45')
            ->set('settings.days', ['1'])
            ->call('save')
            ->assertHasErrors('settings')
            ->assertSee('block_minutes debe dividir exacto la jornada');

        expect(Modality::count())->toBe(4);
    });

    it('rechaza una clave repetida, también la de una modalidad borrada', function () {
        $this->ead->delete();

        Livewire::actingAs($this->admin)
            ->test('core::pages.modalities')
            ->call('create')
            ->set('code', 'ead')
            ->set('name', 'Otra')
            ->call('save')
            ->assertHasErrors('code')
            ->assertSee('Ya existe una modalidad con la clave EAD, aunque esté desactivada o borrada.');
    });

    it('un perfil guardado inválido no rompe la tabla: se marca y el panel carga los valores por omisión', function () {
        $this->ead->forceFill(['settings' => ['block_minutes' => 45]])->save();

        Livewire::actingAs($this->admin)
            ->test('core::pages.modalities')
            ->assertSee('Perfil inválido')
            ->call('edit', $this->ead->id)
            ->assertSet('settings', ModalityProfile::DEFAULTS)
            ->assertSee('El perfil de flujo guardado no es válido');
    });

    it('la clave no cambia una vez creada: el panel la deshabilita con su motivo y la Action la rechaza', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.modalities')
            ->call('edit', $this->pres->id)
            ->assertSee('La clave no cambia una vez creada.')
            ->assertSeeHtml('data-testid="code-input"')
            ->set('code', 'OTRA')
            ->call('save')
            ->assertHasErrors('code')
            ->assertSee('La clave de una modalidad no cambia una vez creada.');

        expect($this->pres->fresh()->code)->toBe('PRES')
            ->and(fn () => app(UpdateModality::class)->handle($this->pres, ['code' => 'otra']))->toThrow(ValidationException::class)
            ->and(app(UpdateModality::class)->handle($this->pres, ['code' => ' pres ', 'name' => 'Presencial 2'])->code)->toBe('PRES')
            ->and($this->c1->fresh()->modality_id)->toBe($this->pres->id);
    });

    it('M11: no desactiva la única modalidad activa', function () {
        $page = Livewire::actingAs($this->admin)->test('core::pages.modalities')->call('toggleActive', $this->pres->id);

        expect(lastToastOf($page))->type->toBe('error')
            ->and(lastToastOf($page)['description'])->toContain('única modalidad activa')
            ->and($this->pres->fresh()->is_active)->toBeTrue();
    });

    it('M11: con el bloqueo en orden fijo sigue rechazando la última activa, y desactiva otra si queda una', function () {
        $this->ead->update(['is_active' => true]);

        app(UpdateModality::class)->handle($this->ead, ['is_active' => false]);
        expect($this->ead->fresh()->is_active)->toBeFalse();

        expect(fn () => app(UpdateModality::class)->handle($this->pres, ['is_active' => false]))->toThrow(ValidationException::class)
            ->and($this->pres->fresh()->is_active)->toBeTrue();
    });

    it('M11: no desactiva una modalidad con un periodo abierto y cargas sin publicar', function () {
        $this->ead->update(['is_active' => true]);
        $term = Term::factory()->create(['code' => '20271']);
        TermOpening::factory()->for($term)->for($this->pres)->create(['opened_by' => $this->admin->id]);
        AcademicLoad::factory()->create(['term_id' => $term->id, 'campus_id' => $this->c1->id, 'modality_id' => $this->pres->id, 'status' => LoadStatus::Closed]);

        $page = Livewire::actingAs($this->admin)->test('core::pages.modalities')->call('toggleActive', $this->pres->id);

        expect(lastToastOf($page))->type->toBe('error')
            ->and(lastToastOf($page)['description'])->toContain('20271')->toContain('1 carga sin publicar')
            ->and($this->pres->fresh()->is_active)->toBeTrue();
    });

    it('M11: desactiva una modalidad con periodo abierto si todas sus cargas están publicadas', function () {
        $this->ead->update(['is_active' => true]);
        $term = Term::factory()->create(['code' => '20271']);
        TermOpening::factory()->for($term)->for($this->pres)->create(['opened_by' => $this->admin->id]);
        AcademicLoad::factory()->create(['term_id' => $term->id, 'campus_id' => $this->c1->id, 'modality_id' => $this->pres->id, 'status' => LoadStatus::Published]);

        $page = Livewire::actingAs($this->admin)->test('core::pages.modalities')->call('toggleActive', $this->pres->id);

        expect(lastToastOf($page)['title'])->toBe('Modalidad desactivada')
            ->and($this->pres->fresh()->is_active)->toBeFalse();
    });

    it('M11: el botón del panel y el del menú piden confirmación con el impacto', function () {
        $this->ead->update(['is_active' => true]);
        $term = Term::factory()->create(['code' => '20271']);
        TermOpening::factory()->for($term)->for($this->pres)->create(['opened_by' => $this->admin->id]);
        User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'career_id' => careerKey(20)])->create();

        $html = Livewire::actingAs($this->admin)
            ->test('core::pages.modalities')
            ->call('edit', $this->pres->id)
            ->html();

        expect(substr_count($html, 'data-confirm-title="¿Desactivar la modalidad Presencial?"'))->toBeGreaterThanOrEqual(2)
            ->and($html)->toContain('data-testid="drawer-toggle-active"')
            ->toContain('Está abierta en 20271')->toContain('1 nombramiento vigente');
    });

    it('guardar el perfil de Presencial lo escribe también en los campus presenciales, en la misma operación (temporal)', function () {
        $other = Campus::factory()->create(['code' => 'XX', 'modality_id' => $this->ead->id]);

        Livewire::actingAs($this->admin)
            ->test('core::pages.modalities')
            ->call('edit', $this->pres->id)
            ->set('settings.day_end', '22:00')
            ->set('settings.days', ['1', '2', '3', '4', '5', '6'])
            ->call('save')
            ->assertHasNoErrors();

        expect($this->pres->fresh()->profile)->dayEnd->toBe('22:00')
            ->and($this->c1->fresh()->profile)->dayEnd->toBe('22:00')->days->toBe([1, 2, 3, 4, 5, 6])
            ->and($this->c2->fresh()->profile)->dayEnd->toBe('22:00')
            ->and($other->fresh()->profile->dayEnd)->toBe('21:00');
    });

    it('si un campus rechaza el perfil, no queda nada guardado: ni la modalidad ni los otros campus', function () {
        $this->c2->update(['modality_id' => $this->pres->id]);
        $before = $this->pres->fresh()->settings;

        Campus::saving(function (Campus $campus): void {
            if ($campus->code === 'C2') {
                throw new RuntimeException('falla simulada');
            }
        });

        expect(fn () => app(UpdateModality::class)->handle($this->pres, ['settings' => [...ModalityProfile::DEFAULTS, 'day_end' => '22:00']]))
            ->toThrow(RuntimeException::class);

        expect($this->pres->fresh()->settings)->toBe($before)
            ->and($this->c1->fresh()->profile->dayEnd)->toBe('21:00')
            ->and(Activity::count())->toBe(0);
    });
});

describe('subdirección', function () {
    it('entra, ve las modalidades y edita solo el perfil de flujo', function () {
        $this->actingAs($this->subdireccion)->get(route('core.modalities'))->assertOk();

        Livewire::actingAs($this->subdireccion)
            ->test('core::pages.modalities')
            ->call('edit', $this->pres->id)
            ->assertSet('canEditData', false)
            ->assertSee('Solo el administrador cambia nombre y estado.')
            ->set('code', 'X1')
            ->set('name', 'Otro nombre')
            ->set('settings.requires_subdireccion_approval', true)
            ->call('save')
            ->assertHasNoErrors();

        expect($this->pres->fresh())
            ->code->toBe('PRES')
            ->name->toBe('Presencial')
            ->is_active->toBeTrue()
            ->profile->requiresSubdireccionApproval->toBeTrue()
            ->and(array_keys(Activity::where('subject_id', $this->pres->id)->sole()->attribute_changes['attributes']))->toBe(['settings']);
    });

    it('no da de alta, activa ni desactiva aunque llame directo al componente', function () {
        Livewire::actingAs($this->subdireccion)->test('core::pages.modalities')->call('create')->assertForbidden();
        Livewire::actingAs($this->subdireccion)->test('core::pages.modalities')->call('toggleActive', $this->ead->id)->assertForbidden();
        Livewire::actingAs($this->subdireccion)->test('core::pages.modalities')
            ->set('code', 'X1')->set('name', 'Nueva')->call('save')->assertForbidden();

        expect(Modality::count())->toBe(4)->and($this->ead->fresh()->is_active)->toBeFalse();
    });

    it('ve deshabilitados, con su motivo, los controles que no puede usar', function () {
        Livewire::actingAs($this->subdireccion)
            ->test('core::pages.modalities')
            ->assertSee('Solo el administrador da de alta modalidades.')
            ->assertSee('Solo el administrador ve las modalidades inactivas.')
            ->assertSee('Solo el administrador activa o desactiva modalidades.');
    });
});

describe('otros roles e invitados', function () {
    $roles = [
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'docente' => [Role::Docente, []],
    ];

    it('reciben 403 en la página', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->c1->id, ...$scope])->create();

        $this->actingAs($user)->get(route('core.modalities'))->assertForbidden();
    })->with($roles);

    it('reciben 403 en cada acción aunque la llamen directo con una página que abrió el admin', function (Role $role, array $scope, string $method) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->c1->id, ...$scope])->create();
        $page = Livewire::actingAs($this->admin)->test('core::pages.modalities')->call('edit', $this->pres->id);

        $this->actingAs($user);
        $page->call($method, ...(in_array($method, ['edit', 'toggleActive'], true) ? [$this->pres->id] : []))->assertForbidden();

        expect($this->pres->fresh()->is_active)->toBeTrue()->and(Activity::count())->toBe(0);
    })->with($roles)->with(['crear' => ['create'], 'editar' => ['edit'], 'activar' => ['toggleActive'], 'guardar' => ['save']]);

    it('un invitado va al login', function () {
        $this->get(route('core.modalities'))->assertRedirect('/login');
    });
});

describe('menú', function () {
    it('«Modalidades» va junto a Campus, apunta a su página y la ven el admin y la subdirección', function () {
        $labels = collect(app(Navigation::class)->items())->pluck('label')->all();
        $item = collect(app(Navigation::class)->items())->firstWhere('label', 'Modalidades');

        expect(array_search('Modalidades', $labels, true))->toBe(array_search('Campus', $labels, true) + 1)
            ->and($item)->route->toBe('core.modalities')
            ->and(route('core.modalities', absolute: false))->toBe('/modalidades');
    });

    it('no la ven los demás roles', function () {
        $user = User::factory()->withRole(Role::Docente, ['campus_id' => $this->c1->id])->create();
        $this->actingAs($user);

        expect(collect(app(Navigation::class)->visibleTo(app(Actor::class)))->pluck('label')->all())->not->toContain('Modalidades');
    });
});
