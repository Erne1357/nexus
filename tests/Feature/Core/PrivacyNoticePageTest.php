<?php

use App\Modules\Core\Models\LoginShortcut;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\PrivacyNotice;

/*
| AC-23 (tarea 1.65): el aviso de privacidad del TecNM, simplificado e integral, en una página pública que se lee sin
| sesión, con la fuente y los PDF oficiales; la pantalla de acceso y la de cambio de contraseña la enlazan.
*/

it('AC-23: muestra los dos avisos del TecNM sin sesión, con la fecha de realización', function () {
    $this->assertGuest();

    $this->get('/aviso-de-privacidad')
        ->assertOk()
        ->assertSee('Aviso de privacidad')
        ->assertSee('AVISO DE PRIVACIDAD SIMPLIFICADO')
        ->assertSee('AVISO DE PRIVACIDAD INTEGRAL')
        ->assertSee('Finalidades para recabar sus datos personales son las siguientes:')
        ->assertSee('1.- Fundamento legal del Tecnológico Nacional de México')
        ->assertSee('3.- Datos que se recabarán')
        ->assertSee('5.- Medios y procedimientos para ejercer sus Derechos ARCO')
        ->assertSee('7.- Cambios al Aviso de Privacidad')
        ->assertSee('unidaddeenlace@nube.sep.gob.mx')
        ->assertSee('FECHA DE REALIZACIÓN: 03/MARZO/2026.')
        ->assertSee('id="simplificado"', false)
        ->assertSee('id="integral"', false)
        ->assertSee('Iniciar sesión');
});

it('AC-23: copia el texto tal cual, sin los encabezados ni los pies de página del PDF', function () {
    $text = preg_replace('/\s+/u', ' ', strip_tags($this->get(route('privacy-notice'))->getContent()));

    expect($text)
        ->toContain('El Tecnológico Nacional de México (TecNM), a través de sus oficinas centrales adscritas a la Dirección General, con domicilio en Av. Universidad 1200, Quinto Piso,')
        ->toContain('Elaboración de una identificación vigente con fotografía, toda vez que, es indispensable, para ingresar al Instituto Tecnológico correspondiente,')
        ->toContain('Elaboración de una identificación vigente con fotografía, toda vez que es indispensable, para ingresar al Instituto Tecnológico correspondiente,')
        ->toContain('Datos sensibles: Grupo sanguíneo, padecimientos y alergias.')
        ->toContain('El domicilio de la Unidad de Transparencia en la Secretaría de Educación Pública está ubicado en la Calle de Donceles número 100, Colonia Centro, Alcaldía Cuauhtémoc, C.P. 06020, Ciudad de México.')
        ->not->toContain('Tel. (55) 3600-2511')
        ->not->toContain('d_juridica@tecnm.mx')
        ->not->toContain('col. Xoco');

    expect(substr_count($text, 'FECHA DE REALIZACIÓN: 03/MARZO/2026.'))->toBe(2);
});

it('AC-23: dice de dónde sale el aviso y enlaza los PDF oficiales del TecNM', function () {
    $this->get(route('privacy-notice'))
        ->assertOk()
        ->assertSee('href="'.PrivacyNotice::SOURCE_URL.'"', false)
        ->assertSee('href="'.e(PrivacyNotice::TECNM_PAGE_URL).'"', false)
        ->assertSee('href="'.e(PrivacyNotice::SIMPLIFIED_PDF_URL).'"', false)
        ->assertSee('href="'.e(PrivacyNotice::INTEGRAL_PDF_URL).'"', false)
        ->assertSee('Protección de Datos Personales');
});

it('AC-23: con sesión, la banda lleva a Nexus en lugar de iniciar sesión', function () {
    $this->actingAs(User::factory()->create(['name' => 'Ana Torres']))
        ->get(route('privacy-notice'))
        ->assertOk()
        ->assertSee('Ana Torres')
        ->assertSee('Ir a Nexus')
        ->assertDontSee('Iniciar sesión');
});

it('AC-23: quien todavía debe cambiar su contraseña también puede leer el aviso', function () {
    $this->actingAs(User::factory()->create(['must_change_password' => true]))
        ->get(route('privacy-notice'))
        ->assertOk()
        ->assertSee('AVISO DE PRIVACIDAD SIMPLIFICADO');
});

it('AC-23: la pantalla de acceso siempre enlaza el aviso de privacidad, fuera de la credencial que voltean los atajos', function (bool $withShortcuts) {
    if ($withShortcuts) {
        LoginShortcut::factory()->create(['title' => 'Sitio del TecNM', 'url' => 'https://www.tecnm.mx']);
    }

    $response = $this->get(route('login'))->assertOk()->assertSee('Aviso de privacidad');
    $link = domOf($response)->querySelector('[data-testid="login-privacy-notice"]');

    expect($link)->not->toBeNull()
        ->and($link->getAttribute('href'))->toBe(route('privacy-notice'))
        ->and($link->closest('main'))->not->toBeNull()
        ->and($link->closest('[data-login-badge]'))->toBeNull();
})->with(['sin atajos' => false, 'con atajos' => true]);

it('AC-23: la pantalla de cambio de contraseña enlaza el aviso, en el primer acceso y en un cambio voluntario', function (bool $firstAccess) {
    $this->actingAs(User::factory()->create(['must_change_password' => $firstAccess]))
        ->get(route('user-password.edit'))
        ->assertOk()
        ->assertSee('href="'.route('privacy-notice').'"', false)
        ->assertSee('Aviso de privacidad');
})->with(['primer acceso' => true, 'cambio voluntario' => false]);
