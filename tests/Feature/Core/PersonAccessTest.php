<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
| AC-03 y AC-04 en la ficha de la persona de «Personas y puestos»: el admin da acceso con «Dar acceso» a un usuario
| sin contraseña que no es docente (Subdirección, Servicios Escolares…), con la misma acción que en la ficha del docente.
| La ficha solo la ve el admin.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->rosa = User::factory()->withRole(Role::Subdireccion)->create(['username' => 'rosa', 'password' => null, 'must_change_password' => true]);
});

it('AC-04: la ficha muestra el estado del acceso; AC-03: sin contraseña, «Dar acceso» se lo da con la contraseña por defecto', function () {
    $dom = domOf($this->actingAs($this->admin)->get(route('core.people.show', $this->rosa->id))->assertOk());

    expect(textOf($dom->querySelector('[data-testid="user-access"]')))->toBe('Sin acceso')
        ->and($dom->querySelector('[data-testid="grant-access"]')?->getAttribute('data-confirm-title'))->toBe('¿Dar acceso a rosa?');

    $page = Livewire::actingAs($this->admin)->test('core::pages.person', ['user' => $this->rosa->id])->call('grantAccess');

    expect($this->rosa->fresh()->must_change_password)->toBeTrue()
        ->and(Hash::check(app(DefaultPassword::class)->value(), $this->rosa->fresh()->password))->toBeTrue()
        ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Acceso dado');
});

it('AC-03: a quien ya tiene contraseña no se le ofrece y la llamada no la cambia; a un usuario inactivo tampoco', function () {
    $this->rosa->forceFill(['password' => 'otra-clave-123', 'must_change_password' => false])->save();
    $before = $this->rosa->fresh()->password;

    $dom = domOf($this->actingAs($this->admin)->get(route('core.people.show', $this->rosa->id))->assertOk());

    expect($dom->querySelector('[data-testid="grant-access"]'))->toBeNull()
        ->and(textOf($dom->querySelector('[data-testid="user-access"]')))->toBe('Con acceso');

    $page = Livewire::actingAs($this->admin)->test('core::pages.person', ['user' => $this->rosa->id])->call('grantAccess');

    expect(lastToastOf($page))->type->toBe('error')
        ->and($this->rosa->fresh()->password)->toBe($before);

    $this->rosa->forceFill(['password' => null, 'is_active' => false])->save();

    $page = Livewire::actingAs($this->admin)->test('core::pages.person', ['user' => $this->rosa->id])->call('grantAccess');

    expect(lastToastOf($page))->type->toBe('error')->description->toBe('El usuario está inactivo.')
        ->and($this->rosa->fresh()->password)->toBeNull();
});

it('AC-03: quien no es admin (aquí un docente) no entra a la ficha ni da acceso, aunque llame la acción sobre una ficha que abrió el admin', function () {
    $jefe = User::factory()->withRole(Role::Docente)->create();

    $this->actingAs($jefe)->get(route('core.people.show', $this->rosa->id))->assertForbidden();

    $page = Livewire::actingAs($this->admin)->test('core::pages.person', ['user' => $this->rosa->id]);
    $this->actingAs($jefe);
    $page->call('grantAccess')->assertForbidden();

    expect($this->rosa->fresh()->password)->toBeNull();
});
