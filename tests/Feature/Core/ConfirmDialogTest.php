<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| ADR-0023: el layout trae una sola vez el diálogo de confirmar de Core, y Campus confirma con él y no con
| `wire:confirm` (el diálogo del navegador).
*/

it('el layout trae el diálogo de confirmar una sola vez y Campus lo usa en lugar de wire:confirm', function () {
    $this->actingAs(User::factory()->admin()->create());
    Campus::factory()->create(['code' => 'C2']);

    $html = $this->get('/campus')->assertOk()->getContent();

    expect(substr_count($html, 'id="nexus-confirm"'))->toBe(1)
        ->and($html)->toContain('x-confirm="Nadie podrá trabajar en el campus C2 hasta que se reactive."')
        ->and($html)->toContain('data-confirm-tone="error"')
        ->and($html)->not->toContain('wire:confirm');
});

it('ya no queda wire:confirm en las vistas de Core, Academic, CatalogValidation, Facilities, Faculty y Scheduling', function () {
    $files = [];

    foreach (['Academic', 'CatalogValidation', 'Core', 'Facilities', 'Faculty', 'Scheduling'] as $module) {
        $dir = base_path("app/Modules/{$module}");
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_ends_with($path, '.blade.php')
                || str_contains($path, 'Academic/Views/plans/')
                || str_contains($path, 'pending-placements')
                || str_ends_with($path, 'Core/Views/components/confirm-dialog.blade.php')
                || str_ends_with($path, 'Core/Views/components/row-menu-item.blade.php')) {
                continue;
            }

            if (str_contains(file_get_contents($path), 'wire:confirm')) {
                $files[] = $path;
            }
        }
    }

    expect($files)->toBe([]);
});

it('el JS de la aplicación ya no abre diálogos del navegador (salvo el aviso de beforeunload)', function () {
    $js = file_get_contents(resource_path('js/app.js'));

    expect($js)->not->toMatch('/(?<![\w.$-])(window\.)?(confirm|alert|prompt)\(/');
});
