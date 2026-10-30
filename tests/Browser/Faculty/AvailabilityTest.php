<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;

/*
| Disponibilidad docente en el navegador (DESIGN.md, "Cuadrícula día x hora"): el jefe de Sistemas y Computación
| abre la pestaña Disponibilidad de la ficha, entra al editor, pinta tres horas del lunes como no disponibles
| arrastrando, elige «Preferido» con la tecla P y marca dos horas del miércoles con clic, guarda, recarga y las ve.
| Al volver con Atrás desde la ficha, el editor muestra lo guardado (no la página en caché) y otro guardado no deshace
| el anterior. Salir con bloques sin guardar pide confirmar; un error de red avisa; los días y las horas se recorren
| con las flechas.
*/

/** Expresión de JS con el tipo de la celda (día y bloque, desde 0) de la cuadrícula del editor; '' es disponible. */
function availabilityKindAt(int $day, int $block): string
{
    return "document.querySelector('[data-d=\"{$day}\"][data-h=\"{$block}\"]').dataset.kind ?? ''";
}

/**
 * @return list<string>
 */
function availabilityRowsOf(Teacher $teacher): array
{
    return TeacherAvailability::query()->where('teacher_id', $teacher->id)->orderBy('day')->orderBy('starts_at')->get()
        ->map(fn (TeacherAvailability $row): string => $row->day.' '.substr($row->starts_at, 0, 5).'-'.substr($row->ends_at, 0, 5).' '.$row->kind->value)
        ->all();
}

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    $this->ana = Teacher::factory()->adscribedTo($this->sc)->create(['employee_number' => '1000001', 'name' => 'Ana Pérez']);

    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])
        ->create(['username' => 'jefe.sc', 'name' => 'Julia Jefa']);
});

it('el jefe marca tres horas como no disponibles y dos como preferidas, guarda, recarga y las ve', function () {
    $page = visit('/login')
        ->fill('username', 'jefe.sc')
        ->fill('password', 'password')
        ->press('Entrar')
        ->navigate('/faculty/teachers/'.$this->ana->id.'?tab=disponibilidad')
        ->waitForText('Editar disponibilidad')
        ->click('[data-testid="edit-availability"]')
        ->waitForText('Por día')
        ->assertPathIs('/faculty/teachers/'.$this->ana->id.'/availability')
        ->drag('[data-d="0"][data-h="0"]', '[data-d="0"][data-h="2"]')
        ->keys('[data-d="0"][data-h="2"]', 'p')
        ->click('[data-d="2"][data-h="3"]')
        ->click('[data-d="2"][data-h="4"]')
        ->assertSee('5 bloques sin guardar')
        ->assertScript(availabilityKindAt(0, 1), 'unavailable')
        ->assertScript(availabilityKindAt(2, 4), 'preferred')
        ->assertScript("document.querySelector('[data-testid=availability-figures]').textContent.replace(/\\s+/g, ' ').trim()", 'Disponible 67 h de 70 Preferido 2 h No disponible 3 h Horas base · Ene-Jun 2027 0 h · 0 plazas')
        ->click('[data-testid="save-availability"]')
        ->waitForText('Disponibilidad guardada')
        ->assertDontSee('bloques sin guardar')
        ->refresh()
        ->waitForText('Por día')
        ->assertScript(availabilityKindAt(0, 0), 'unavailable')
        ->assertScript(availabilityKindAt(0, 2), 'unavailable')
        ->assertScript(availabilityKindAt(0, 3), '')
        ->assertScript(availabilityKindAt(2, 3), 'preferred')
        ->assertScript(availabilityKindAt(2, 4), 'preferred')
        ->assertSee('7:00–10:00')
        ->assertSee('10:00–12:00')
        ->assertNoJavaScriptErrors();

    expect(TeacherAvailability::query()->orderBy('day')->get(['day', 'starts_at', 'ends_at', 'kind'])->map(fn (TeacherAvailability $row): string => "{$row->day} {$row->starts_at}-{$row->ends_at} {$row->kind->value}")->all())
        ->toBe(['1 07:00:00-10:00:00 unavailable', '3 10:00:00-12:00:00 preferred']);
});

/** Entra como el jefe de SC y abre el editor de Ana desde su ficha (con wire:navigate, como lo hace la gente). */
function openAvailabilityEditor(Teacher $teacher): mixed
{
    return visit('/login')
        ->fill('username', 'jefe.sc')
        ->fill('password', 'password')
        ->press('Entrar')
        ->navigate('/faculty/teachers/'.$teacher->id.'?tab=disponibilidad')
        ->waitForText('Editar disponibilidad')
        ->click('[data-testid="edit-availability"]')
        ->waitForText('Por día');
}

it('al volver con Atrás muestra lo guardado y otro guardado no deshace el anterior', function () {
    openAvailabilityEditor($this->ana)
        ->click('[data-d="0"][data-h="0"]')
        ->click('[data-testid="save-availability"]')
        ->waitForText('1 h no disponibles')
        ->click('a[href*="tab=disponibilidad"]')
        ->waitForText('Editar disponibilidad')
        ->back()
        ->waitForText('Por día')
        ->wait(1)
        ->assertPathIs('/faculty/teachers/'.$this->ana->id.'/availability')
        ->assertScript(availabilityKindAt(0, 0), 'unavailable')
        ->assertScript("document.querySelector('[data-testid=save-bar]').offsetParent === null", true)
        ->click('[data-d="1"][data-h="1"]')
        ->click('[data-testid="save-availability"]')
        ->waitForText('2 h no disponibles')
        ->assertNoJavaScriptErrors();

    expect(availabilityRowsOf($this->ana))->toBe(['1 07:00-08:00 unavailable', '2 08:00-09:00 unavailable']);
});

it('pide confirmar antes de salir con bloques sin guardar', function () {
    $page = openAvailabilityEditor($this->ana)->click('[data-d="0"][data-h="0"]');
    $unload = "(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; })()";

    expect($page->script($unload))->toBeTrue();

    $page->script('(() => { window.confirm = () => false; return true; })()');
    $page->click('a[href*="tab=disponibilidad"]')
        ->wait(0.5)
        ->assertPathIs('/faculty/teachers/'.$this->ana->id.'/availability')
        ->assertSee('1 bloque sin guardar');

    $page->script('(() => { window.confirm = () => true; return true; })()');
    $page->click('a[href*="tab=disponibilidad"]')
        ->waitForText('Editar disponibilidad')
        ->assertPathIs('/faculty/teachers/'.$this->ana->id);

    expect(TeacherAvailability::query()->count())->toBe(0);
});

it('sin cambios pendientes no pide confirmar al salir', function () {
    $page = openAvailabilityEditor($this->ana);

    expect($page->script("(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; })()"))->toBeFalse();
});

it('si el servidor no responde al guardar, lo avisa y deja los bloques pendientes', function () {
    $page = openAvailabilityEditor($this->ana)->click('[data-d="0"][data-h="0"]');
    $page->script("(() => { window.fetch = () => Promise.reject(new TypeError('Failed to fetch')); return true; })()");

    $page->click('[data-testid="save-availability"]')
        ->waitForText('No hubo respuesta del servidor')
        ->assertSee('1 bloque sin guardar');

    expect(TeacherAvailability::query()->count())->toBe(0);
});

it('las flechas llevan de una celda a los botones del día y de la hora, que pintan con Enter', function () {
    openAvailabilityEditor($this->ana)
        ->keys('[data-d="0"][data-h="0"]', 'ArrowLeft')
        ->assertScript('document.activeElement.dataset.hour', '0')
        ->keys('[data-hour="0"]', 'ArrowUp')
        ->assertScript('document.activeElement.dataset.hour', '0')
        ->keys('[data-hour="0"]', 'ArrowRight')
        ->keys('[data-d="0"][data-h="0"]', 'ArrowUp')
        ->assertScript('document.activeElement.dataset.day', '0')
        ->keys('[data-day="0"]', 'Enter')
        ->assertScript(availabilityKindAt(0, 13), 'unavailable')
        ->assertScript("[...document.querySelectorAll('[data-testid=availability-grid] button')].filter((button) => button.tabIndex === 0).length", 1)
        ->assertNoJavaScriptErrors();
});

it('al volver con Atrás trae lo guardado de nuevo y conserva lo pintado sin guardar como pendiente', function () {
    $page = openAvailabilityEditor($this->ana)
        ->click('[data-d="0"][data-h="0"]')
        ->click('[data-testid="save-availability"]')
        ->waitForText('1 h no disponibles')
        ->click('[data-d="1"][data-h="1"]')
        ->click('[data-d="1"][data-h="2"]')
        ->assertSee('2 bloques sin guardar');

    $page->script('(() => { window.confirm = () => true; return true; })()');
    $page->click('a[href*="tab=disponibilidad"]')->waitForText('Editar disponibilidad');

    // Mientras tanto, otra sesión guarda el jueves a las 7:00.
    TeacherAvailability::factory()->for($this->ana)->for($this->term)->preferred()->create(['day' => 4, 'starts_at' => '07:00', 'ends_at' => '08:00']);

    $page->back()
        ->waitForText('Por día')
        ->wait(1)
        ->assertScript(availabilityKindAt(0, 0), 'unavailable')
        ->assertScript(availabilityKindAt(3, 0), 'preferred')
        ->assertScript(availabilityKindAt(1, 1), 'unavailable')
        ->assertScript(availabilityKindAt(1, 2), 'unavailable')
        ->assertScript("[...document.querySelectorAll('[data-testid=availability-grid] [data-changed]')].map((cell) => cell.dataset.d + '-' + cell.dataset.h).join(',')", '1-1,1-2')
        ->assertSee('2 bloques sin guardar')
        ->click('[data-testid="save-availability"]')
        ->waitForText('3 h no disponibles y 1 h preferidas')
        ->assertNoJavaScriptErrors();

    expect(availabilityRowsOf($this->ana))->toBe(['1 07:00-08:00 unavailable', '2 08:00-10:00 unavailable', '4 07:00-08:00 preferred']);
});

it('al volver del bfcache del navegador conserva lo pintado sin guardar y trae lo guardado', function () {
    $page = openAvailabilityEditor($this->ana)->click('[data-d="1"][data-h="1"]');
    TeacherAvailability::factory()->for($this->ana)->for($this->term)->preferred()->create(['day' => 4, 'starts_at' => '07:00', 'ends_at' => '08:00']);

    $page->script("(() => { window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })); return true; })()");
    $page->wait(1)
        ->assertScript(availabilityKindAt(3, 0), 'preferred')
        ->assertScript(availabilityKindAt(1, 1), 'unavailable')
        ->assertSee('1 bloque sin guardar');
});

it('no deja pintar mientras se guarda', function () {
    $page = openAvailabilityEditor($this->ana)->click('[data-d="0"][data-h="0"]');
    $page->script('(() => { const send = window.fetch; window.fetch = (...args) => new Promise((resolve) => setTimeout(() => resolve(send(...args)), 1500)); return true; })()');

    $page->click('[data-testid="save-availability"]')
        ->click('[data-d="2"][data-h="2"]')
        ->assertScript(availabilityKindAt(2, 2), '')
        ->waitForText('1 h no disponibles')
        ->wait(0.3)
        ->assertScript(availabilityKindAt(2, 2), '')
        ->assertDontSee('bloque sin guardar');

    expect(availabilityRowsOf($this->ana))->toBe(['1 07:00-08:00 unavailable']);
});
