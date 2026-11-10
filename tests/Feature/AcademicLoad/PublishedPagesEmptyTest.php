<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/*
| La consulta pública sin nada publicado (tarea 4.4): el estado vacío, sin errores ni selectores. Va aparte de
| PublishedPagesTest porque las versiones publicadas son inmutables y esa prueba las crea todas en su preparación.
*/

it('sin publicaciones dice que todavía no hay horarios y no ofrece selectores', function () {
    Term::factory()->create(['code' => '20271']);
    Campus::factory()->create(['code' => 'C2']);

    $this->get(route('published.index'))
        ->assertOk()
        ->assertSee('Todavía no hay horarios publicados.')
        ->assertDontSee('Periodo')
        ->assertSee('Iniciar sesión');
});
