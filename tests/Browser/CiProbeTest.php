<?php

it('sonda de CI: esta prueba de browser debe fallar', function () {
    visit('/')->assertSee('TextoQueNoExiste');
});
