<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/*
| Elegir salón tocándolo en el mapa 3D (tarea 14 de la orden de selección, diseño §6.5). El cajón de la oferta llega con la
| tarea 13: aquí el componente `scheduling::components.map-picker` va solo, en una ruta que existe solo en esta prueba.
*/

it('el docente en su turno elige un salón tocándolo en el mapa', function () {
    config(['nexus.campus_map_3d' => true]);
    $campus = Campus::factory()->create(['code' => 'C2']);
    User::factory()->withRole(Role::Docente, ['campus_id' => $campus->id])->create(['username' => '19110002']);

    Route::middleware(['web', 'auth'])->get('/_test/map-picker', fn () => Blade::render(
        '<!doctype html><html lang="es"><head><meta charset="utf-8">@vite([\'resources/css/app.css\', \'resources/js/app.js\'])</head><body>'
        .'<livewire:scheduling::components.map-picker :eligible="$eligible" :rejected="$rejected" /></body></html>',
        [
            'eligible' => ['Edificio Rivera Lara|105', 'Edificio Rivera Lara|106'],
            'rejected' => ['Edificio Rivera Lara|108' => 'Ocupado en ese horario'],
        ],
    ));

    $page = visit('/login')
        ->fill('username', '19110002')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/_test/map-picker')
        ->assertPresent('[data-testid="map-picker-stage"]')
        ->wait(4);

    $page->script('if (!window.__picked) { window.__picked = []; window.addEventListener("room-picked", e => window.__picked.push(e.detail.key)); } window.UbicaTec.probe("Edificio Rivera Lara|105")');
    $page->wait(2);

    $tap = <<<'JS'
        (key) => {
            const p = window.UbicaTec.probe(key);
            const stage = document.querySelector('three-d-stage');
            const o = { clientX: p.x, clientY: p.y, pointerType: 'mouse', bubbles: true };
            stage.dispatchEvent(new PointerEvent('pointerdown', o));
            stage.dispatchEvent(new PointerEvent('pointerup', o));
            return window.__picked.length;
        }
        JS;

    // Un salón rechazado no se elige: dice por qué y no emite nada.
    $page->script('('.$tap.')("Edificio Rivera Lara|108")');
    $page->assertScript('window.__picked.length', 0)
        ->assertScript('document.querySelector("#toast")?.textContent.includes("Ocupado en ese horario")', true);

    // Uno elegible emite `room-picked` con su llave de mapa.
    $page->script('('.$tap.')("Edificio Rivera Lara|105")');
    $page->assertScript('window.__picked', ['Edificio Rivera Lara|105'])
        ->assertNoJavaScriptErrors();
});
