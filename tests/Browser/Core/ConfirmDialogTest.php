<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| El diálogo de confirmar (ADR-0023: nunca los diálogos del navegador). Un botón con `x-confirm` abre el `x-modal` de
| Core con su título, mensaje y botón; el foco abre en «Cancelar», cancelar no hace nada y confirmar corre la acción
| del mismo botón. Se prueba con «Desactivar» de Campus.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II', 'is_active' => true]);
    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
});

it('confirma en el diálogo de Nexus: cancelar no hace nada y confirmar corre la acción', function () {
    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/campus')
        ->assertSee('Campus II');

    $page->click('table [aria-label="Acciones de C2"]')
        ->click('table [role="menuitem"]:has-text("Desactivar")')
        ->assertSeeIn('#nexus-confirm', '¿Desactivar el campus C2?')
        ->assertSeeIn('#nexus-confirm', 'Nadie podrá trabajar en el campus C2 hasta que se reactive.')
        ->assertScript('document.activeElement?.dataset.testid === "confirm-cancel"')
        ->click('@confirm-cancel')
        ->assertScript('document.getElementById("nexus-confirm").open === false')
        ->assertNoJavaScriptErrors();

    expect($this->c2->fresh()->is_active)->toBeTrue();

    $page->click('table [aria-label="Acciones de C2"]')
        ->click('table [role="menuitem"]:has-text("Desactivar")')
        ->assertSeeIn('@confirm-accept', 'Desactivar')
        ->click('@confirm-accept')
        ->waitForText('Inactivo')
        ->assertNoJavaScriptErrors();

    expect($this->c2->fresh()->is_active)->toBeFalse();
});
