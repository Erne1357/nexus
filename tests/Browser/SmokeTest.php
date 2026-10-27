<?php

it('abre la página de acceso en Chromium', function () {
    $page = visit('/login');

    $page->assertSee('Nexus')
        ->assertSee('Entrar')
        ->assertNoJavaScriptErrors();
});
