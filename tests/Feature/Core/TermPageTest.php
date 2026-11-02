<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\SetTermStage;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Ficha del periodo (`/terms/{term}`, core::pages.term, la maqueta B «Línea de tiempo»): encabezado con «Editar» y
| «Marcar como actual», y el calendario de las 8 etapas como línea de tiempo, con el panel lateral de cada etapa
| (fechas generales y una ventana propia opcional por campus activo). Lo administran el `admin` y la
| `subdireccion`; los demás roles no entran. Cada método público vuelve a autorizar aunque se llame directo con
| una página que abrió otra sesión.
*/

beforeEach(function () {
    $this->travelTo('2026-11-12 10:00:00');

    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c3 = Campus::factory()->create(['code' => 'C3', 'name' => 'Campus III']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    $this->other = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);

    $this->admin = User::factory()->admin()->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create();
});

function termPageStage(Term $term, Stage $stage, ?Campus $campus, string $from, string $to): TermStage
{
    return app(SetTermStage::class)->handle($term, $stage, $campus, $from, $to);
}

function termPageWindows(Term $term, Stage $stage): array
{
    return TermStage::where('term_id', $term->id)->where('stage', $stage->value)->get()
        ->mapWithKeys(fn (TermStage $window): array => [($window->campus?->code ?? 'general') => $window->starts_on->toDateString().'/'.$window->ends_on->toDateString()])
        ->sortKeys()
        ->all();
}

describe('admin y subdirección', function () {
    it('ven el encabezado con nombre, código, fechas y «Actual»', function (string $who) {
        $this->actingAs($this->{$who})
            ->get(route('core.terms.show', $this->term->id))
            ->assertOk()
            ->assertSee('<title>Ficha del periodo · Nexus</title>', false)
            ->assertSeeInOrder(['Periodos', 'Ene-Jun 2027', '20271', 'Actual', '25/01/2027 al 11/06/2027', 'Editar', 'Marcar como actual', 'Calendario']);
    })->with(['admin', 'subdireccion']);

    it('muestra las 8 etapas en orden, con su estado y los días que faltan', function () {
        termPageStage($this->term, Stage::CatalogValidation, null, '2026-11-10', '2026-11-19');
        termPageStage($this->term, Stage::Proposal, null, '2026-11-17', '2026-11-24');

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->assertSeeInOrder(['Validación de catálogos', 'En curso', 'quedan 7 días', 'Propuesta por carrera', 'Próxima', 'empieza en 5 días', 'Horarios y cupos', 'Asignación docente', 'Salones y acuerdos', 'Cierre y aprobación', 'Publicación', 'Re-análisis', 'Sin fecha'])
            ->assertSee('Nov 2026')
            ->assertSee('noviembre 2026')
            ->assertSee('Hoy 12 nov')
            ->assertViewHas('timeline', fn ($timeline): bool => count($timeline->rows) === 8
                && $timeline->from->toDateString() === '2026-11-09'
                && $timeline->to->toDateString() === '2026-11-29');
    });

    it('pinta una barra delgada por campus con ventana propia, con su código', function () {
        termPageStage($this->term, Stage::Scheduling, null, '2026-11-20', '2026-12-04');
        termPageStage($this->term, Stage::Scheduling, $this->c1, '2026-11-24', '2026-12-09');

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->assertSeeInOrder(['20 nov – 4 dic', 'C1', '24 nov – 9 dic'])
            ->assertViewHas('timeline', fn ($timeline): bool => count($timeline->rows[2]['campuses']) === 1 && $timeline->rows[2]['campuses'][0]['code'] === 'C1');
    });

    it('sin ninguna fecha dice que no hay calendario y lista las 8 etapas con «Poner fechas»', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->assertViewHas('timeline', fn ($timeline): bool => ! $timeline->hasRange() && count($timeline->rows) === 8)
            ->assertDontSee('Hoy 12 nov')
            ->assertSee('Este periodo no tiene calendario.')
            ->assertSee('data-slot="copy-calendar"', false)
            ->assertDontSee('Sin fecha')
            ->assertDontSee('border-dashed', false);

        expect(substr_count($page->html(), 'Poner fechas'))->toBe(8);

        $page->call('editStage', 'scheduling')->assertSet('showDrawer', true)->assertSee('Etapa 3 de 8');
    });

    it('las etapas se anuncian por su contenido: el botón no sustituye su nombre accesible', function () {
        termPageStage($this->term, Stage::Proposal, null, '2026-11-17', '2026-11-24');

        $page = Livewire::actingAs($this->admin)->test('core::pages.term', ['term' => $this->term->id]);

        expect($page->html())->toMatch('/<button[^>]*data-testid="stage-proposal"/')
            ->and(preg_match('/<button[^>]*data-testid="stage-proposal"[^>]*>/', $page->html(), $match))->toBe(1)
            ->and($match[0])->not->toContain('aria-label');
        $page->assertSee('Próxima')->assertSee('17–24 nov');
    });

    it('las insignias de estado llevan el tono de su barra y la terminada va atenuada', function () {
        termPageStage($this->term, Stage::CatalogValidation, null, '2026-11-02', '2026-11-05');
        termPageStage($this->term, Stage::Proposal, null, '2026-11-10', '2026-11-19');
        termPageStage($this->term, Stage::Scheduling, null, '2026-11-20', '2026-12-04');

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->assertSeeHtml('badge-info badge-soft')
            ->assertSeeHtml('badge-neutral badge-soft')
            ->assertSeeHtml('badge-success badge-soft')
            ->assertSeeHtml('bg-success/35')
            ->assertSee('Terminada');
    });

    it('«Actual» usa el tono primario', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->assertSeeHtml('badge-primary');
    });

    it('edita el periodo desde el modal y lo confirma con un toast', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('edit')
            ->assertSet('showModal', true)
            ->assertSet('form.code', '20271')
            ->assertSet('form.starts_on', '2027-01-25')
            ->set('form.name', 'Primavera 2027')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false)
            ->assertSee('Primavera 2027');

        expect($this->term->fresh()->name)->toBe('Primavera 2027')
            ->and(lastToastOf($page))->title->toBe('Periodo actualizado')->description->toBe('20271 · Primavera 2027');
    });

    it('deshabilita el código con su motivo cuando el periodo ya se abrió y no deja cambiarlo', function () {
        TermOpening::factory()->for($this->term)->for($this->c2)->create();

        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('edit')
            ->assertSee('data-testid="term-code-locked"', false)
            ->assertSee('El código ya no se puede cambiar: el periodo se abrió en C2.')
            ->set('form.code', '20272')
            ->call('save')
            ->assertHasErrors('form.code')
            ->assertSee('El código ya no se puede cambiar');

        expect($this->term->fresh()->code)->toBe('20271');
    });

    it('deja el código libre mientras el periodo no se ha abierto', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('edit')
            ->assertDontSee('data-testid="term-code-locked"', false);
    });

    it('muestra en español los errores del formulario y de las reglas del periodo', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('edit')
            ->set('form.name', '')
            ->set('form.code', '2027')
            ->call('save')
            ->assertHasErrors(['form.name' => 'required'])
            ->assertSee('El campo nombre es obligatorio.')
            ->set('form.name', 'X')
            ->call('save')
            ->assertHasErrors('form.code')
            ->assertSee('El código lleva el año y el periodo')
            ->set('form.code', '20263')
            ->call('save')
            ->assertSee('Ya existe un periodo con el código 20263.');
    });

    it('marca el periodo como actual y quita la marca al anterior', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->other->id])
            ->assertSee('¿Marcar el periodo 20263 como el actual?', escape: false)
            ->call('makeCurrent');

        expect($this->other->fresh()->is_current)->toBeTrue()
            ->and($this->term->fresh()->is_current)->toBeFalse()
            ->and(lastToastOf($page))->title->toBe('Periodo actual')->description->toBe('20263 · Ago-Dic 2026');
    });

    it('deshabilita «Marcar como actual» con su motivo en el periodo actual', function () {
        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->assertSee('Ya es el periodo actual.')
            ->assertDontSee('wire:click="makeCurrent"', escape: false);
    });
});

describe('sección «Campus»', function () {
    it('lista los campus activos por código con su estado y no ofrece uno inactivo', function () {
        $this->c3->update(['is_active' => false]);

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->assertSeeInOrder(['Abierto en', 'Abrir en Campus I', 'Abrir en Campus II', 'Calendario', 'Campus', 'C1', 'Campus I', 'No abierto en este periodo', 'C2', 'Campus II'])
            ->assertSee('Abrir en Campus I')
            ->assertDontSee('Abrir en Campus III')
            ->assertSee('¿Abrir el periodo 20271 en Campus II?');
    });

    it('el resumen muestra los códigos abiertos y un botón por campus pendiente', function () {
        $this->c3->update(['is_active' => false]);
        TermOpening::factory()->for($this->term)->for($this->c2)->create(['opened_by' => $this->admin->id]);

        $page = Livewire::actingAs($this->admin)->test('core::pages.term', ['term' => $this->term->id]);

        expect(preg_match('/data-testid="campus-summary".*?<\/section>/s', $page->html(), $match))->toBe(1)
            ->and($match[0])->toContain('data-testid="opened-C2"')->toContain('Abrir en Campus I')
            ->not->toContain('Abrir en Campus II')->not->toContain('data-testid="open-menu"');
    });

    it('con más de dos campus pendientes el resumen ofrece un solo menú «Abrir en…»', function () {
        Campus::factory()->create(['code' => 'C4', 'name' => 'Campus IV']);

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->assertSeeHtml('data-testid="open-menu"')
            ->assertSee('Abrir en…')
            ->assertSee('ningún campus')
            ->assertDontSee('Abrir en Campus I');
    });

    it('abre el periodo en Campus II, crea cargas y revisiones y lo dice en un toast', function (string $who) {
        Department::factory()->create(['code' => 'D1']);
        Department::factory()->create(['code' => 'D2']);

        $page = Livewire::actingAs($this->{$who})
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('openIn', $this->c2->id)
            ->assertSee('Abierto el 12 nov 2026 por '.$this->{$who}->name)
            ->assertSee('Completar apertura')
            ->assertDontSee('data-testid="open-C2"', false);

        $toast = lastToastOf($page);

        expect($toast['title'])->toBe('Periodo abierto en C2')
            ->and($toast['description'])->toBe('10 revisiones de catálogo nuevas, 2 cargas académicas nuevas')
            ->and(TermOpening::where('term_id', $this->term->id)->pluck('campus_id')->all())->toBe([$this->c2->id])
            ->and(AcademicLoad::count())->toBe(2)
            ->and(CatalogReview::count())->toBe(10);
    })->with(['admin', 'subdireccion']);

    it('«Completar apertura» crea lo que falte y la segunda vez dice que no faltaba nada', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('openIn', $this->c2->id);

        Department::factory()->create(['code' => 'D3']);

        $page->call('completeIn', $this->c2->id);
        expect(lastToastOf($page))->title->toBe('Apertura completada en C2')->description->toContain('1 carga');

        $page->call('completeIn', $this->c2->id);
        expect(lastToastOf($page))->title->toBe('Apertura completada en C2')->description->toBe('No faltaba nada.')
            ->and(TermOpening::count())->toBe(1);
    });

    it('un campus abierto y desactivado después se lista solo lectura, sin acciones', function () {
        TermOpening::factory()->for($this->term)->for($this->c3)->create(['opened_by' => $this->admin->id]);
        $this->c3->update(['is_active' => false]);

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->assertSee('C3')
            ->assertSee('Campus inactivo')
            ->assertSeeHtml('badge-neutral badge-soft')
            ->assertDontSee('data-testid="complete-C3"', escape: false)
            ->assertDontSee('data-testid="open-C3"', escape: false);
    });

    it('un campus abierto y borrado después también se lista', function () {
        TermOpening::factory()->for($this->term)->for($this->c3)->create(['opened_by' => $this->admin->id]);
        $this->c3->delete();

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->assertSee('Campus III')
            ->assertSee('Campus borrado');
    });

    it('rechaza abrir en un campus inactivo con un toast de error y sin crear nada', function () {
        $this->c3->update(['is_active' => false]);

        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('openIn', $this->c3->id);

        expect(lastToastOf($page))->title->toBe('No se abrió el periodo')->description->toContain('C3')
            ->and(TermOpening::count())->toBe(0);
    });

    it('un campus inexistente o manipulado da 404, no 500', function (string $id) {
        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('openIn', $id)
            ->assertNotFound();
    })->with(['uuid sin campus' => fakeKey(999), 'no es uuid' => 'no-es-un-uuid', 'número' => '999999']);

    it('un campus borrado no se abre', function () {
        $this->c3->delete();

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('openIn', $this->c3->id)
            ->assertNotFound();
    });
});

describe('panel de una etapa', function () {
    it('abre con las fechas guardadas: la general y la propia de cada campus activo', function () {
        termPageStage($this->term, Stage::Scheduling, null, '2026-11-20', '2026-12-04');
        termPageStage($this->term, Stage::Scheduling, $this->c1, '2026-11-24', '2026-12-09');
        termPageStage($this->term, Stage::Proposal, $this->c2, '2026-11-01', '2026-11-05');

        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'scheduling')
            ->assertSet('showDrawer', true)
            ->assertSet('stage', 'scheduling')
            ->assertSet('general', ['starts_on' => '2026-11-20', 'ends_on' => '2026-12-04'])
            ->assertSet('own', [$this->c1->id => ['starts_on' => '2026-11-24', 'ends_on' => '2026-12-09']])
            ->assertSeeInOrder(['Etapa 3 de 8', 'Horarios y cupos', 'General', 'Campus I', 'Quitar ventana propia', 'Campus II', 'Agregar ventana propia', 'Usa las fechas generales'])
            ->assertSee('Por campus');

        $page->call('editStage', 'proposal')
            ->assertSet('general', ['starts_on' => '', 'ends_on' => ''])
            ->assertSet('own', [$this->c2->id => ['starts_on' => '2026-11-01', 'ends_on' => '2026-11-05']])
            ->assertSee('Sin fechas generales.');
    });

    it('muestra en los campus sin ventana propia las fechas generales que se editan, no las guardadas', function () {
        termPageStage($this->term, Stage::Scheduling, null, '2026-11-20', '2026-12-04');
        $previewOf = fn ($page): string => preg_match('/data-testid="general-preview-C2".*?<\/p>/s', $page->html(), $match) === 1 ? preg_replace('/\s+/', ' ', $match[0]) : '';

        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'scheduling')
            ->assertSee('20 nov – 4 dic');

        $page->set('general.starts_on', '2026-11-23')
            ->set('general.ends_on', '2026-12-11')
            ->assertSee('Usa las fechas generales');
        expect($previewOf($page))->toContain('23 nov – 11 dic')->not->toContain('20 nov – 4 dic');

        $page->set('general.ends_on', '');
        expect($previewOf($page))->toContain('Fechas generales incompletas.')->not->toContain('Sin fechas generales.');

        $page->call('clearGeneral');
        expect($previewOf($page))->toContain('Sin fechas generales.')->not->toContain('Usa las fechas generales');
    });

    it('no ofrece un campus inactivo', function () {
        termPageStage($this->term, Stage::Scheduling, $this->c3, '2026-11-24', '2026-12-09');
        $this->c3->update(['is_active' => false]);

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'scheduling')
            ->assertSet('own', [])
            ->assertDontSee('Campus III')
            ->call('addCampusWindow', $this->c3->id)
            ->assertNotFound();
    });

    it('una etapa desconocida es 404', function (string $stage) {
        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', $stage)
            ->assertNotFound();
    })->with(['no-existe', '', '10']);

    it('crea la ventana general al guardar', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'staffing')
            ->set('general.starts_on', '2026-11-24')
            ->set('general.ends_on', '2026-12-04')
            ->call('saveStage')
            ->assertHasNoErrors()
            ->assertSet('showDrawer', false)
            ->assertSee('24 nov – 4 dic');

        expect(termPageWindows($this->term, Stage::Staffing))->toBe(['general' => '2026-11-24/2026-12-04'])
            ->and(lastToastOf($page))->title->toBe('Etapa actualizada')->description->toBe('Asignación docente');
    });

    it('cambia la ventana general y agrega la propia de un campus en un solo guardado', function () {
        termPageStage($this->term, Stage::Staffing, null, '2026-11-24', '2026-12-04');

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'staffing')
            ->set('general.ends_on', '2026-12-11')
            ->call('addCampusWindow', $this->c2->id)
            ->assertSet('own', [$this->c2->id => ['starts_on' => '2026-11-24', 'ends_on' => '2026-12-11']])
            ->set("own.{$this->c2->id}.starts_on", '2026-11-30')
            ->call('saveStage')
            ->assertHasNoErrors()
            ->assertSet('showDrawer', false);

        expect(termPageWindows($this->term, Stage::Staffing))->toBe(['C2' => '2026-11-30/2026-12-11', 'general' => '2026-11-24/2026-12-11']);
    });

    it('quita la ventana propia de un campus al guardar, y el campus vuelve a la general', function () {
        termPageStage($this->term, Stage::Staffing, null, '2026-11-24', '2026-12-04');
        termPageStage($this->term, Stage::Staffing, $this->c2, '2026-11-30', '2026-12-11');

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'staffing')
            ->call('removeCampusWindow', $this->c2->id)
            ->assertSet('own', [])
            ->assertSee('Agregar ventana propia');

        expect(termPageWindows($this->term, Stage::Staffing))->toHaveCount(2);

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'staffing')
            ->call('removeCampusWindow', $this->c2->id)
            ->call('saveStage')
            ->assertHasNoErrors();

        expect(termPageWindows($this->term, Stage::Staffing))->toBe(['general' => '2026-11-24/2026-12-04']);
    });

    it('quita la ventana general con «Quitar fechas» al guardar', function () {
        termPageStage($this->term, Stage::Staffing, null, '2026-11-24', '2026-12-04');

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'staffing')
            ->call('clearGeneral')
            ->assertSet('general', ['starts_on' => '', 'ends_on' => ''])
            ->call('saveStage')
            ->assertHasNoErrors();

        expect(termPageWindows($this->term, Stage::Staffing))->toBe([]);
    });

    it('no toca lo que no cambió y deja bitácora de lo que sí', function () {
        termPageStage($this->term, Stage::Staffing, null, '2026-11-24', '2026-12-04');
        $before = Activity::count();

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'staffing')
            ->call('saveStage')
            ->assertHasNoErrors();

        expect(Activity::count())->toBe($before);
    });

    it('muestra en español los errores de las fechas, cada uno en su campo, sin guardar nada', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'staffing')
            ->set('general.starts_on', '2026-12-04')
            ->set('general.ends_on', '2026-11-24')
            ->call('addCampusWindow', $this->c2->id)
            ->set("own.{$this->c2->id}.starts_on", '')
            ->call('saveStage')
            ->assertHasErrors(['general.ends_on', "own.{$this->c2->id}.starts_on"])
            ->assertSee('La fecha de fin no puede ser anterior a la de inicio.')
            ->assertSee('Escribe la fecha.')
            ->assertSet('showDrawer', true);

        expect(TermStage::count())->toBe(0);

        $page->set('general.starts_on', 'mañana')
            ->call('saveStage')
            ->assertHasErrors(['general.starts_on' => 'date_format'])
            ->assertSee('El campo inicio debe ser una fecha válida.');

        $page->set('general.starts_on', '')
            ->call('saveStage')
            ->assertHasErrors('general.starts_on')
            ->assertSee('Escribe la fecha.');

        expect(TermStage::count())->toBe(0);
    });

    it('no guarda nada si la ventana de un campus falla, ni la general ni otros campus', function () {
        termPageStage($this->term, Stage::Staffing, null, '2026-11-24', '2026-12-04');

        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'staffing')
            ->set('general.ends_on', '2026-12-11')
            ->call('addCampusWindow', $this->c1->id)
            ->call('addCampusWindow', $this->c2->id)
            ->set("own.{$this->c2->id}.ends_on", '2026-11-01')
            ->call('saveStage')
            ->assertHasErrors("own.{$this->c2->id}.ends_on")
            ->assertSet('showDrawer', true);

        expect(termPageWindows($this->term, Stage::Staffing))->toBe(['general' => '2026-11-24/2026-12-04']);
    });

    it('rechaza con error de validación, no con 500, lo que el navegador manipuló', function (array $own, string $field) {
        Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'staffing')
            ->set('general.starts_on', '2026-11-24')
            ->set('general.ends_on', '2026-12-04')
            ->call('addCampusWindow', $this->c2->id)
            ->set("own.{$this->c2->id}", $own)
            ->call('saveStage')
            ->assertHasErrors("own.{$this->c2->id}.{$field}")
            ->assertSet('showDrawer', true);

        expect(TermStage::count())->toBe(0);
    })->with([
        'inicio nulo' => [['starts_on' => null], 'starts_on'],
        'sin fin' => [['starts_on' => '2026-11-24'], 'ends_on'],
        'sin nada' => [[], 'starts_on'],
        'fecha de otro formato' => [['starts_on' => '24/11/2026', 'ends_on' => '2026-12-04'], 'starts_on'],
    ]);

    it('rechaza una ventana general a medias o una propia que no es una lista', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'staffing')
            ->set('general', ['starts_on' => '2026-11-24'])
            ->call('saveStage')
            ->assertHasErrors('general.ends_on');

        $page->set('general', ['starts_on' => '', 'ends_on' => ''])
            ->set("own.{$this->c2->id}", 'texto')
            ->call('saveStage')
            ->assertHasErrors("own.{$this->c2->id}");

        expect(TermStage::count())->toBe(0);
    });

    it('ignora la ventana de un campus que se desactivó mientras el panel estaba abierto', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('core::pages.term', ['term' => $this->term->id])
            ->call('editStage', 'staffing')
            ->set('general.starts_on', '2026-11-24')
            ->set('general.ends_on', '2026-12-04')
            ->call('addCampusWindow', $this->c2->id);

        $this->c2->update(['is_active' => false]);

        $page->call('saveStage');

        expect(TermStage::where('campus_id', $this->c2->id)->count())->toBe(0);
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
        $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

        $this->actingAs($user)->get(route('core.terms.show', $this->term->id))->assertForbidden();
    })->with($roles);

    it('reciben 403 en cada método del componente aunque lo llamen directo con una página que abrió el admin', function (Role $role, array $scope, string $method) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();
        $page = Livewire::actingAs($this->admin)->test('core::pages.term', ['term' => $this->term->id]);

        match ($method) {
            'save' => $page->call('edit')->set('form.name', 'Otro'),
            'saveStage' => $page->call('editStage', 'staffing')->set('general.starts_on', '2026-11-24')->set('general.ends_on', '2026-12-04'),
            'removeCampusWindow' => $page->call('editStage', 'staffing')->call('addCampusWindow', $this->c2->id),
            default => null,
        };

        $this->actingAs($user);

        $arguments = match ($method) {
            'editStage' => ['staffing'],
            'addCampusWindow', 'removeCampusWindow', 'openIn', 'completeIn' => [$this->c2->id],
            default => [],
        };

        $page->call($method, ...$arguments)->assertForbidden();

        expect(TermStage::count())->toBe(0)
            ->and(TermOpening::count())->toBe(0)
            ->and($this->term->fresh())->name->toBe('Ene-Jun 2027')->is_current->toBeTrue()
            ->and(Activity::count())->toBe(0);
    })->with($roles)->with(['edit', 'save', 'makeCurrent', 'editStage', 'clearGeneral', 'addCampusWindow', 'removeCampusWindow', 'saveStage', 'openIn', 'completeIn']);

    it('no pueden cambiar el código aunque la página la abriera el admin', function () {
        $user = User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create();
        $page = Livewire::actingAs($this->admin)->test('core::pages.term', ['term' => $this->term->id])->call('edit');

        $this->actingAs($user);
        $page->set('form.code', '20272')->assertForbidden();

        expect($this->term->fresh()->code)->toBe('20271');
    });

    it('un invitado va al login', function () {
        $this->get(route('core.terms.show', $this->term->id))->assertRedirect('/login');
    });
});

describe('periodo inexistente', function () {
    it('un id desconocido o que no es uuid da 404', function (string $id) {
        $this->actingAs($this->admin)->get('/terms/'.$id)->assertNotFound();
    })->with(['uuid sin periodo' => fakeKey(999), 'no es uuid' => 'no-es-un-uuid', 'número' => '999999']);
});

describe('menú', function () {
    it('la ficha es una pantalla hija de Periodos y la deja activa', function () {
        $item = collect(app(Navigation::class)->items())->firstWhere('label', 'Periodos');

        expect($item->owns('core.terms.show'))->toBeTrue()
            ->and($item->owns('core.terms'))->toBeTrue()
            ->and($item->owns('core.campuses'))->toBeFalse();
    });

    it('la tabla de periodos enlaza a la ficha', function () {
        $this->actingAs($this->admin)
            ->get(route('core.terms'))
            ->assertSee(route('core.terms.show', $this->term->id), false)
            ->assertSee('Ver ficha');
    });
});
