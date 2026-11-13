<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;

/*
| Acuerdos en el navegador (tarea 3.7, maqueta B): el jefe del departamento dueño del salón entra por el menú, abre un
| préstamo pendiente, lo rechaza con motivo (el campo del motivo recibe el foco) y la sesión de quien lo pidió queda sin
| salón.
*/

beforeEach(function () {
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id]);
    $sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    // Carga en curso (MN-04): sin ella, el jefe no ve Acuerdos en el menú.
    AcademicLoad::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id, 'department_id' => $sc->id]);
    $group = Group::factory()->create([
        'term_id' => $term->id, 'campus_id' => $campus->id, 'code' => '3A',
        'subject_id' => Subject::factory()->create(['code' => 'SCC-1013', 'name' => 'Redes', 'department_id' => $ii->id])->id,
    ]);
    $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $campus->id])->id, 'department_id' => $sc->id, 'code' => 'LC-201']);
    $this->session = GroupSession::factory()->for($group)->on(1, '09:00', '11:00')->inRoom($room)->create();
    $this->ids = ['term_id' => $term->id, 'campus_id' => $campus->id, 'group_id' => $group->id, 'from_department_id' => $ii->id, 'to_department_id' => $sc->id];
    $this->loan = InterdepartmentalAgreement::factory()->create([
        'term_id' => $term->id, 'campus_id' => $campus->id, 'group_id' => $group->id, 'room_id' => $room->id,
        'day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'from_department_id' => $ii->id, 'to_department_id' => $sc->id,
    ]);

    User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $campus->id, 'department_id' => $sc->id])->create(['username' => '19110005']);
});

it('el jefe dueño abre un préstamo pendiente desde el menú y lo rechaza con el motivo enfocado; la sesión queda sin salón', function () {
    visit('/login')
        ->fill('username', '19110005')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('@hare')
        ->click('a[data-label="Acuerdos"]')
        ->assertPathIs('/scheduling/agreements')
        ->assertSee('Por decidir')
        ->assertSee('LC-201 · Lun 9:00–11:00')
        ->click('[data-agreement="'.$this->loan->id.'"]')
        ->assertSee('Préstamo de LC-201')
        ->assertSee('Pedido')
        ->click('@agreement-reject')
        ->assertSee('Motivo del rechazo (obligatorio)')
        ->assertSee('se queda sin salón')
        ->assertScript('document.activeElement?.dataset.testid', 'agreement-comment')
        ->fill('@agreement-comment', 'Se cruza con una práctica.')
        ->click('@agreement-reject-confirm')
        ->assertSee('Rechazaste LC-201')
        ->assertSee('No tienes acuerdos por decidir.')
        ->assertNoJavaScriptErrors();

    expect($this->loan->fresh()->status)->toBe(AgreementStatus::Rejected)
        ->and($this->session->fresh()->room_id)->toBeNull();
});

it('elegir varios cambia la cuenta y la barra en el navegador, sin ir al servidor', function () {
    $share = InterdepartmentalAgreement::factory()->sharedGroup(3)->create($this->ids);

    $page = visit('/login')
        ->fill('username', '19110005')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/');

    $page->navigate('/scheduling/agreements')
        ->assertSee('Elegir los 2 que puedes aceptar')
        ->assertMissing('@bulk-bar');
    $page->script('window.__requests = 0; const send = window.fetch; window.fetch = (...args) => { if (String(args[0]?.url ?? args[0]).includes(`livewire`)) { window.__requests++ } return send(...args) }');

    $page->click('[data-select="'.$share->id.'"]')
        ->assertSee('1 elegido')
        ->click('@agreements-select-all')
        ->assertSee('2 elegidos')
        ->assertSee('Aceptar 2')
        ->assertSee('Quitar la selección')
        ->click('@bulk-clear')
        ->assertMissing('@bulk-bar')
        ->assertScript('window.__requests', 0)
        ->click('[data-agreement="'.$share->id.'"]')
        ->assertSee('Cupo de')
        ->assertScript('window.__requests > 0', true)
        ->assertNoJavaScriptErrors();

    expect($share->fresh()->status)->toBe(AgreementStatus::Proposed);
});
