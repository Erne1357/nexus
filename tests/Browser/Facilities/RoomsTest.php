<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;

/*
| Salones en el navegador: la Subdirección de C2 (su único campus, así que es el activo) entra por el
| menú, da de alta un salón, lo edita y le agrega un bloqueo en el panel lateral; un coordinador no ve la
| entrada. Salones es del área Académico, la del inicio.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->edificio = Building::factory()->for($this->c2)->create(['code' => 'A', 'name' => 'Edificio A']);
    Room::factory()->for($this->edificio)->create(['code' => 'A-101']);

    User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create(['username' => 'subdireccion', 'name' => 'Sara Subdirección']);
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create(['username' => '19110001', 'name' => 'Carlos Coordinador']);
});

function signInToRooms(string $username)
{
    return visit('/login')
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

it('la subdirección de C2 da de alta un salón, lo edita y le agrega un bloqueo desde la UI', function () {
    $page = signInToRooms('subdireccion')
        ->click('#nexus-menu a[data-label="Salones"]')
        ->assertPathIs('/facilities/rooms')
        ->assertSee('A-101')
        ->press('Nuevo salón')
        ->select('building_id', $this->edificio->id)
        ->fill('code', 'a-102')
        ->select('type', 'laboratorio')
        ->fill('capacity', '24')
        ->fill('features', 'Proyector, Pizarrón')
        ->press('Guardar')
        ->waitForText('Salón creado')
        ->assertSee('A-102')
        ->assertNoJavaScriptErrors();

    expect(Room::where('code', 'A-102')->sole())->type->toBe(RoomType::Laboratorio)->capacity->toBe(24)->features->toBe(['Proyector', 'Pizarrón']);

    $page->click('@edit-A-102')
        ->assertValue('code', 'A-102')
        ->fill('capacity', '28')
        ->press('Guardar')
        ->waitForText('Salón actualizado')
        ->assertNoJavaScriptErrors();

    expect(Room::where('code', 'A-102')->sole()->capacity)->toBe(28);

    $page->click('table [aria-label="Acciones de A-102"]')
        ->click('@blocks-A-102')
        ->waitForText('Bloqueos de')
        ->select('day', '2')
        ->fill('starts_at', '09:00')
        ->fill('ends_at', '11:00')
        ->fill('reason', 'Mantenimiento')
        ->press('Agregar bloqueo')
        ->waitForText('Bloqueo agregado')
        ->assertSee('09:00 a 11:00')
        ->assertNoJavaScriptErrors();

    expect(Room::where('code', 'A-102')->sole()->blocks()->count())->toBe(1);
});

it('un coordinador no ve la opción Salones en el menú', function () {
    signInToRooms('19110001')
        ->assertDontSee('Salones')
        ->assertNoJavaScriptErrors();
});
