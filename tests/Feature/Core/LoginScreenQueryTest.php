<?php

use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;
use App\Modules\Core\Queries\LoginScreenQuery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

/*
| Avisos y atajos de la pantalla de acceso (tarea 1.17): LG-01 y LG-02.
*/

it('LG-01: solo da los avisos activos y vigentes ahora, en su orden', function () {
    $this->travelTo('2026-11-05 10:00');
    LoginNotice::factory()->create(['title' => 'Segundo', 'sort_order' => 2]);
    LoginNotice::factory()->create(['title' => 'Primero', 'sort_order' => 1, 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-10']);
    LoginNotice::factory()->create(['title' => 'Inactivo', 'is_active' => false]);
    LoginNotice::factory()->create(['title' => 'Vencido', 'ends_at' => '2026-11-05 09:59']);
    LoginNotice::factory()->create(['title' => 'Futuro', 'starts_at' => '2026-11-06']);
    LoginNotice::factory()->create(['title' => 'Borrado'])->delete();

    expect(array_column(app(LoginScreenQuery::class)->notices(), 'title'))->toBe(['Primero', 'Segundo']);
});

it('LG-01: da la foto del aviso como URL del disco público', function () {
    LoginNotice::factory()->create(['image_path' => 'login/avisos/edificio.webp', 'link_url' => 'https://www.tecnm.mx']);

    expect(app(LoginScreenQuery::class)->notices()[0])->toMatchArray([
        'image_url' => Storage::disk('public')->url('login/avisos/edificio.webp'),
        'link_url' => 'https://www.tecnm.mx',
    ]);
});

it('LG-02: solo da los atajos activos y vigentes, con su destino según el tipo', function () {
    $this->travelTo('2026-11-05 10:00');
    LoginShortcut::factory()->create(['title' => 'Sitio', 'url' => 'https://www.tecnm.mx', 'sort_order' => 1]);
    LoginShortcut::factory()->download('login/atajos/referencia.pdf')->create(['title' => 'Referencia', 'sort_order' => 2]);
    LoginShortcut::factory()->create(['title' => 'Apagado', 'is_active' => false]);
    LoginShortcut::factory()->create(['title' => 'Vencido', 'ends_at' => '2026-11-01']);

    expect(app(LoginScreenQuery::class)->shortcuts())->toBe([
        ['title' => 'Sitio', 'description' => null, 'href' => 'https://www.tecnm.mx', 'download' => false],
        ['title' => 'Referencia', 'description' => null, 'href' => Storage::disk('public')->url('login/atajos/referencia.pdf'), 'download' => true],
    ]);
});

it('no guarda un atajo de enlace sin URL ni una vigencia que termina antes de empezar', function () {
    expect(fn () => LoginShortcut::factory()->create(['url' => null]))->toThrow(QueryException::class);
    expect(fn () => LoginNotice::factory()->create(['starts_at' => '2026-11-05', 'ends_at' => '2026-11-04']))->toThrow(QueryException::class);
});
