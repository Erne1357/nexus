<?php

it('abre la página de inicio en Chromium', function () {
    $page = visit('/');

    $page->assertSee('Nexus')
        ->assertNoJavaScriptErrors();
});
