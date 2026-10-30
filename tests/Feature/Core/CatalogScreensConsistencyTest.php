<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| Las cinco pantallas de catálogo de Académico, de Campus a Planes de estudio, comparten la forma
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
]);

function seedCatalogRow(string $route, string $code): void
{
    match ($route) {
        'core.campuses' => Campus::factory()->create(['code' => $code]),
        'academic.departments' => Department::factory()->create(['code' => $code]),
        'academic.careers' => Career::factory()->create(['code' => $code]),
        'academic.subjects' => Subject::factory()->create(['code' => $code]),
        'academic.plans' => StudyPlan::factory()->create(['code' => $code]),
    };
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
