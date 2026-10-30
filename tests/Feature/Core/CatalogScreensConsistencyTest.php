<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Facades\Blade;

/*
| Las ocho pantallas de catálogo, de Campus a Planes de estudio más Edificios, Salones y Docentes, comparten la forma
| (DESIGN.md, "Anatomía de una pantalla de catálogo"): una barra de herramientas común en su propio
| renglón, con la búsqueda y los filtros a la izquierda y la acción principal a la derecha, y en cada
| fila «Editar» visible y el resto plegado en el menú «Acciones», donde lo deshabilitado se queda con
| su motivo escrito.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

/**
 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
 */
dataset('catalog screens', [
    'Campus' => ['core.campuses', 'C9', 'Nuevo campus', 'Buscar campus'],
    'Departamentos' => ['academic.departments', 'DEP9', 'Nuevo departamento', 'Buscar departamentos'],
    'Carreras' => ['academic.careers', 'CAR9', 'Nueva carrera', 'Buscar carreras'],
    'Materias' => ['academic.subjects', 'MAT9', 'Nueva materia', 'Buscar materias'],
    'Planes de estudio' => ['academic.plans', 'PLN9', 'Nuevo plan', 'Buscar planes'],
    'Edificios' => ['facilities.buildings', 'EDI9', 'Nuevo edificio', 'Buscar edificios'],
    'Salones' => ['facilities.rooms', 'SAL9', 'Nuevo salón', 'Buscar salones'],
    'Docentes' => ['faculty.teachers', '9000009', 'Nuevo docente', 'Buscar docentes'],
]);

function seedCatalogRow(string $route, string $code): void
{
    match ($route) {
        'core.campuses' => Campus::factory()->create(['code' => $code]),
        'academic.departments' => Department::factory()->create(['code' => $code]),
        'academic.careers' => Career::factory()->create(['code' => $code]),
        'academic.subjects' => Subject::factory()->create(['code' => $code]),
        'academic.plans' => StudyPlan::factory()->create(['code' => $code]),
        'facilities.buildings' => Building::factory()->create(['code' => $code]),
        'facilities.rooms' => Room::factory()->create(['code' => $code]),
        'faculty.teachers' => Teacher::factory()->adscribedTo(Department::factory()->create())->create(['employee_number' => $code]),
    };

    // Edificios y salones son de un campus: el admin, que no tiene uno por omisión, elige el de la fila.
    if (str_starts_with($route, 'facilities.')) {
        session([ScopedContext::ACTIVE_CAMPUS => ($route === 'facilities.rooms' ? Room::query() : Building::query())->where('code', $code)->value('campus_id')]);
    }
}

it('usa la barra de herramientas común: búsqueda, filtros a la izquierda y la acción principal a la derecha, fuera del encabezado', function (string $route, string $code, string $action, string $searchLabel) {
    seedCatalogRow($route, $code);

    $dom = domOf($this->actingAs($this->admin)->get(route($route))->assertOk());
    $toolbar = $dom->querySelector('[data-testid="catalog-toolbar"]');

    expect($toolbar)->not->toBeNull()
        ->and($dom->querySelectorAll('[data-testid="catalog-toolbar"]'))->toHaveCount(1)
        ->and($toolbar->querySelector('input[aria-label="'.$searchLabel.'"]'))->not->toBeNull()
        ->and($toolbar->querySelector('select[aria-label="Estado"]'))->not->toBeNull()
        ->and(textOf($toolbar->querySelector('.btn-primary')))->toBe($action)
        // El filtro de estado es el último de los filtros y la acción principal va después, a la derecha.
        ->and(strpos($toolbar->innerHTML, 'aria-label="Estado"'))->toBeLessThan(strpos($toolbar->innerHTML, $action))
        // Nada de la barra vive en el slot `actions` del encabezado.
        ->and($dom->querySelector('main h1')?->parentElement?->parentElement?->querySelector('input[type="search"], .btn-primary'))->toBeNull();
})->with('catalog screens');

it('con cuatro filtros o con «Más acciones», la búsqueda es md:w-56, los filtros md:w-40 y la opción «todos» de cada filtro dice solo su nombre; los demás, md:w-64 y md:w-52', function (string $route, string $code) {
    seedCatalogRow($route, $code);

    $toolbar = domOf($this->actingAs($this->admin)->get(route($route))->assertOk())->querySelector('[data-testid="catalog-toolbar"]');
    $filters = $toolbar->querySelector('[data-testid="catalog-filters"]');
    $selects = iterator_to_array($filters->querySelectorAll('select'));
    $narrow = count($selects) >= 4 || $toolbar->querySelector('button[aria-label="Más acciones"]') !== null;

    expect($toolbar->querySelector('[data-testid="catalog-search"]')->getAttribute('class'))->toContain($narrow ? 'md:w-56' : 'md:w-64')
        ->and($filters->getAttribute('class'))->toContain($narrow ? 'md:[&>*]:w-40' : 'md:[&>*]:w-52');

    if ($narrow) {
        foreach ($selects as $select) {
            expect(textOf($select->querySelector('option')))->toBe($select->getAttribute('aria-label'));
        }
    }
})->with('catalog screens');

it('cada fila lleva «Editar» visible y el botón «Acciones» con su menú', function (string $route, string $code) {
    seedCatalogRow($route, $code);

    $dom = domOf($this->actingAs($this->admin)->get(route($route))->assertOk());
    $row = collect($dom->querySelectorAll('table tbody tr'))->first(fn ($tr): bool => str_contains(textOf($tr), $code));

    expect($row)->not->toBeNull()
        ->and($row->querySelector('button[aria-label="Editar '.$code.'"]'))->not->toBeNull()
        ->and($row->querySelector('button[aria-label="Acciones de '.$code.'"][aria-haspopup="menu"]'))->not->toBeNull()
        ->and($row->querySelector('[role="menu"][popover]'))->not->toBeNull()
        ->and(textOf($row->querySelector('[role="menu"]')))->toMatch('/Desactivar/');
})->with('catalog screens');

it('en el menú, lo deshabilitado se queda con su motivo escrito en un segundo renglón', function (string $label, string $disabledLabel, string $reason, Closure $arrange) {
    $route = $arrange();

    $menu = collect(domOf($this->actingAs($this->admin)->get(route($route))->assertOk())->querySelectorAll('table tbody [role="menu"]'))->first();
    $item = collect($menu->querySelectorAll('[role="menuitem"][aria-disabled="true"]'))->first(fn ($i): bool => str_contains(textOf($i), $disabledLabel));

    expect($item)->not->toBeNull()
        ->and(textOf($item))->toBe($disabledLabel.' '.$reason)
        ->and($item->hasAttribute('wire:click'))->toBeFalse();
})->with([
    'Departamentos: borrar con carreras' => ['Departamentos', 'Borrar', 'Solo se borra un departamento sin carreras ni materias.', function (): string {
        Career::factory()->create();

        return 'academic.departments';
    }],
    'Carreras: borrar con planes' => ['Carreras', 'Borrar', 'Solo se borra una carrera sin planes de estudio.', function (): string {
        StudyPlan::factory()->create();

        return 'academic.careers';
    }],
    'Carreras: ofrecer sin campus activo' => ['Carreras', 'Ofrecer en el campus', 'Elige un campus en tu credencial.', function (): string {
        Career::factory()->create();

        return 'academic.careers';
    }],
]);

it('«Borrar» va en text-error y Planes muestra «Retícula» y «Editar» antes de «Acciones»', function () {
    Department::factory()->create(['code' => 'DEP9']);
    StudyPlan::factory()->create(['code' => 'PLN9']);
    Department::query()->where('code', '!=', 'DEP9')->delete();

    $departments = domOf($this->actingAs($this->admin)->get(route('academic.departments')));
    $plans = domOf($this->actingAs($this->admin)->get(route('academic.plans')));

    $delete = collect($departments->querySelectorAll('table tbody [role="menuitem"]'))->first(fn ($i): bool => str_starts_with(textOf($i), 'Borrar'));
    $labels = array_map(fn ($b): string => $b->getAttribute('aria-label') ?? '', iterator_to_array($plans->querySelectorAll('table tbody tr:first-child button, table tbody tr:first-child a')));

    expect($delete->getAttribute('class'))->toContain('text-error')
        ->and(array_values(array_filter($labels)))->toBe(['Retícula de PLN9', 'Editar PLN9', 'Acciones de PLN9']);
});

it('un menú «Acciones» sin nombre ni etiqueta se anuncia como «Acciones»', function () {
    $html = Blade::render('<x-core::row-menu><x-core::row-menu-item label="Algo" /></x-core::row-menu>');

    expect(domOf($html)->querySelector('button[aria-haspopup="menu"]')->getAttribute('aria-label'))->toBe('Acciones');
});
