<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Staffing\ClosingWorld;

/*
| Las justificaciones de «Cierre de la carga» (HC-15): la lista (staffing::components.closing-justifications) filtra
| por pestaña, búsqueda y «Sin docente · Sin salón» en el servidor y se muestra de 50 en 50; quien cierra la carga elige
| varios grupos y los justifica con un motivo (justificación en lote, confirmado el 10 de noviembre de 2026). El
| drawer de un grupo (staffing::components.closing-justify-drawer) es otro componente: abrirlo no vuelve a pintar la
| lista.
*/

beforeEach(function () {
    $this->world = ClosingWorld::create();
    $this->jefe = $this->world->head();
});

function closingJustifications(User $user, bool $withActions = true): Testable
{
    return Livewire::actingAs($user)->test('staffing::components.closing-justifications', ['loadId' => test()->world->load->id, 'withActions' => $withActions]);
}

/**
 * El texto del resultado de la última justificación en lote.
 */
function closingBulkResult(Testable $list): string
{
    return textOf(domOf($list)->querySelector('[data-testid="bulk-result"]'));
}

function closingPageFor(User $user): Testable
{
    return Livewire::actingAs($user)->test('staffing::pages.closing');
}

function closingJustifyDrawer(User $user): Testable
{
    return Livewire::actingAs($user)->test('staffing::components.closing-justify-drawer', ['loadId' => test()->world->load->id]);
}

/**
 * Las claves de los grupos que pinta la lista, en orden.
 *
 * @return list<string>
 */
function closingListedCodes(Testable $list): array
{
    return array_map(fn ($row): string => (string) $row->getAttribute('data-code'), iterator_to_array(domOf($list)->querySelectorAll('[data-code]')));
}

describe('la lista', function () {
    it('filtra por pestaña, por la búsqueda sin acentos y por «Sin docente · Sin salón»', function () {
        $noTeacher = $this->world->group(teacher: false);
        $noRoom = $this->world->group(withRoom: false);
        $justified = $this->world->justify($this->world->group(teacher: false), 'Contratación en trámite', $this->jefe);
        $this->world->group();

        $list = closingJustifications($this->jefe);
        expect(closingListedCodes($list))->toBe([$noTeacher->code, $noRoom->code]);

        $list->set('lack', 'teacher');
        expect(closingListedCodes($list))->toBe([$noTeacher->code]);

        $list->set('lack', '')->set('tab', 'all');
        expect(closingListedCodes($list))->toBe([$noTeacher->code, $noRoom->code, $justified->code]);

        $list->set('search', 'TOPICOS avanzados');
        expect(closingListedCodes($list))->toHaveCount(3);

        $list->set('search', $noRoom->code);
        expect(closingListedCodes($list))->toBe([$noRoom->code]);
    });

    it('muestra de 50 en 50', function () {
        foreach (range(1, 52) as $ignored) {
            $this->world->group(scheduled: false, teacher: false);
        }

        $list = closingJustifications($this->jefe);
        expect(closingListedCodes($list))->toHaveCount(50)
            ->and(textOf(domOf($list)->querySelector('[data-testid="page-shown"]')))->toBe('Mostrando 50 de 52');

        $list->call('showMore');
        expect(closingListedCodes($list))->toHaveCount(52);
    });

    it('con la carga cerrada no hay casillas ni «Justificar»: cada grupo se abre para leerlo', function () {
        $group = $this->world->justify($this->world->group(teacher: false), 'Contratación en trámite', $this->jefe);
        $this->world->load->update(['status' => LoadStatus::Closed]);

        closingJustifications($this->jefe)
            ->assertDontSeeHtml('data-testid="justify-'.$group->code.'"')
            ->assertDontSeeHtml('data-testid="select-'.$group->code.'"')
            ->assertSeeHtml('data-testid="view-'.$group->code.'"');
    });

    it('en el detalle de «Avance del cierre» solo se lee', function () {
        $group = $this->world->group(teacher: false);
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        closingJustifications($subdireccion, withActions: false)
            ->assertSeeHtml('data-code="'.$group->code.'"')
            ->assertDontSeeHtml('data-testid="justify-'.$group->code.'"')
            ->assertDontSeeHtml('data-testid="select-'.$group->code.'"')
            ->assertSee('Sin justificación');
    });

    it('el jefe de otro departamento no la ve', function () {
        $other = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();

        closingJustifications($other)->assertForbidden();
    });
});

describe('justificar en lote', function () {
    it('«Seleccionar los N de esta vista» respeta la pestaña y el filtro', function () {
        $first = $this->world->group(teacher: false);
        $second = $this->world->group(teacher: false);
        $this->world->group(withRoom: false);

        $list = closingJustifications($this->jefe)->set('lack', 'teacher')->assertSee('Seleccionar los 2 de esta vista');

        expect(json_decode((string) domOf($list)->querySelector('[data-view-ids]')->getAttribute('data-view-ids'), true))->toBe([$first->id, $second->id]);
    });

    it('justifica los elegidos con un motivo, cada uno con quién y cuándo, y avisa a la página', function () {
        $groups = [$this->world->group(teacher: false), $this->world->group(withRoom: false)];

        $list = closingJustifications($this->jefe)
            ->set('selected', array_map(fn (Group $group): string => $group->id, $groups))
            ->set('bulkReason', 'Contratación en trámite')
            ->call('justifySelected')
            ->assertHasNoErrors()
            ->assertReturned(true)
            ->assertSet('selected', [])
            ->assertDispatched('closing-justified');

        expect(closingBulkResult($list))->toBe('Justificados 2');

        foreach ($groups as $group) {
            expect($group->fresh()->only(['close_justification', 'close_justified_by']))->toBe(['close_justification' => 'Contratación en trámite', 'close_justified_by' => $this->jefe->id]);
        }
    });

    it('pide el motivo', function () {
        $group = $this->world->group(teacher: false);

        closingJustifications($this->jefe)
            ->set('selected', [$group->id])
            ->call('justifySelected')
            ->assertHasErrors(['bulkReason' => 'Escribe la justificación.']);

        expect($group->fresh()->close_justification)->toBeNull();
    });

    it('justifica hasta 200 grupos por vez', function () {
        $group = $this->world->group(teacher: false);

        closingJustifications($this->jefe)
            ->set('selected', [$group->id, ...array_map(fn (): string => (string) Str::uuid7(), range(1, 200))])
            ->set('bulkReason', 'Contratación en trámite')
            ->call('justifySelected')
            ->assertHasErrors(['selected' => 'Justifica hasta 200 grupos por vez.']);

        expect($group->fresh()->close_justification)->toBeNull();
    });

    it('un grupo de otro departamento en la selección no se justifica', function () {
        $own = $this->world->group(teacher: false);
        $foreign = $this->world->group(teacher: false, load: $this->world->loadIn($this->world->campus, department: Department::factory()->create()));

        $list = closingJustifications($this->jefe)
            ->set('selected', [$own->id, $foreign->id])
            ->set('bulkReason', 'Contratación en trámite')
            ->call('justifySelected');

        expect(closingBulkResult($list))->toBe('Justificados 1')
            ->and($own->fresh()->close_justification)->toBe('Contratación en trámite')
            ->and($foreign->fresh()->close_justification)->toBeNull();
    });

    it('dice los que no se justificaron, con su motivo', function () {
        $group = $this->world->group(teacher: false);
        $list = closingJustifications($this->jefe)->set('selected', [$group->id])->set('bulkReason', 'Contratación en trámite');
        $group->update(['teacher_id' => $this->world->teacher()->id]);

        $list->call('justifySelected');

        expect(closingBulkResult($list))->toStartWith('Justificados 0 · No se justificaron 1')
            ->and(textOf(domOf($list)->querySelector('[data-testid="bulk-skipped"] li')))->toBe($group->code.'El grupo ya tiene docente y salón: no necesita justificación.');
    });
});

describe('el drawer de un grupo', function () {
    it('el jefe justifica un grupo y luego quita la justificación; la página revisa otra vez y lo dice', function () {
        $group = $this->world->group(teacher: false);

        $drawer = closingJustifyDrawer($this->jefe)
            ->dispatch('open-justification', id: $group->id)
            ->assertSet('show', true)
            ->assertSee('Justificar')
            ->call('save')
            ->assertHasErrors(['justification' => 'Escribe la justificación, o quítala.'])
            ->set('justification', 'Docente de nuevo ingreso, contratación en trámite')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('show', false)
            ->assertDispatched('closing-justified', code: $group->code, removed: false, updated: false);

        expect($group->fresh()->close_justification)->toBe('Docente de nuevo ingreso, contratación en trámite');

        $page = closingPageFor($this->jefe)->dispatch('closing-justified', code: $group->code, removed: false, updated: false)->assertSee('Lista para cerrar');
        expect(lastToastOf($page))->toMatchArray(['title' => 'Grupo justificado', 'description' => "{$group->code} · la carga está lista para cerrar"]);

        $drawer->dispatch('open-justification', id: $group->id)
            ->assertSee('Justificó Laura Ramos')
            ->call('remove')
            ->assertDispatched('closing-justified', code: $group->code, removed: true, updated: false);

        expect($group->fresh()->close_justification)->toBeNull();

        $page = closingPageFor($this->jefe)->dispatch('closing-justified', code: $group->code, removed: true, updated: false)->assertSee('Falta 1 grupo para cerrar');
        expect(lastToastOf($page)['title'])->toBe('Justificación quitada');
    });

    it('con la carga cerrada la justificación se lee pero no se edita', function () {
        $group = $this->world->justify($this->world->group(teacher: false), 'Contratación en trámite', $this->jefe);
        $this->world->load->update(['status' => LoadStatus::Closed]);

        closingJustifyDrawer($this->jefe)
            ->dispatch('open-justification', id: $group->id)
            ->assertSee('La carga está en cerrada: las justificaciones ya no se editan.')
            ->assertDontSeeHtml('data-testid="save-justification"')
            ->set('justification', 'Otra cosa')
            ->call('save')
            ->assertHasErrors('justification');

        expect($group->fresh()->close_justification)->toBe('Contratación en trámite');
    });

    it('un grupo de otra carga no se abre', function () {
        $foreign = $this->world->group(teacher: false, load: $this->world->loadIn($this->world->campus, department: Department::factory()->create()));

        closingJustifyDrawer($this->jefe)->dispatch('open-justification', id: $foreign->id)->assertNotFound();
    });
});

it('la página pinta 50 grupos y, debajo de 1280 px, el historial antes de «Justificaciones»', function () {
    foreach (range(1, 52) as $ignored) {
        $this->world->group(scheduled: false, teacher: false);
    }

    $html = closingPageFor($this->jefe)->html();

    expect(substr_count($html, 'data-code="'))->toBe(50)
        ->and(strpos($html, 'data-testid="closing-history"'))->toBeLessThan(strpos($html, 'data-testid="closing-justifications"'));
});
