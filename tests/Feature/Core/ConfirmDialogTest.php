<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;

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

it('ya no queda wire:confirm en las vistas de Core, Academic, Facilities y Faculty', function () {
    $files = [];

    foreach (['Academic', 'Core', 'Facilities', 'Faculty'] as $module) {
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

it('el diálogo se anuncia como alertdialog con su título y su consecuencia', function () {
    $this->actingAs(User::factory()->admin()->create());

    $html = $this->get('/campus')->assertOk()->getContent();

    expect($html)->toContain('role="alertdialog"')
        ->and($html)->toContain('aria-labelledby="nexus-confirm-title"')
        ->and($html)->toContain('aria-describedby="nexus-confirm-message"')
        ->and($html)->toContain('id="nexus-confirm-title"')
        ->and($html)->toContain('id="nexus-confirm-message"');
});

it('un elemento de menú con href no acepta x-confirm, porque wire:navigate navega antes del clic', function () {
    Blade::render('<x-core::row-menu-item label="Ver" href="/campus" x-confirm="No alcanza a confirmar." />');
})->throws(ViewException::class, 'x-confirm va solo en botones');

it('un elemento de menú sin href sí lleva x-confirm', function () {
    $html = Blade::render('<x-core::row-menu-item label="Desactivar" x-confirm="Nadie podrá trabajar en el campus." />');

    expect($html)->toContain('x-confirm="Nadie podrá trabajar en el campus."')
        ->and($html)->toContain('type="button"');
});
