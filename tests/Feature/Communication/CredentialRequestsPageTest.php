<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Students\Enums\CredentialRequestStatus as Status;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Rules\CredentialRequestSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| «Solicitudes de credencial» (`/comunicacion/solicitudes`, communication::pages.credential-requests). CO-07..CO-09:
| Comunicación atiende la bandeja (cita, sube foto, marca lista, entrega, rechaza) y fija los plazos; nadie más entra.
| Alumnos e imágenes ficticios.
*/

beforeEach(function () {
    Storage::fake('student_photos');
    Notification::fake();
    $campus = Campus::factory()->create(['code' => 'C1']);
    $career = Career::factory()->for(Department::factory())->create();
    $plan = StudyPlan::factory()->for($career)->create();
    $attributes = ['career_id' => $career->id, 'study_plan_id' => $plan->id, 'campus_id' => $campus->id];
    $this->ana = Student::factory()->create(['control_number' => '21111182', 'name' => 'Ana Ficticia', ...$attributes]);
    $this->beto = Student::factory()->create(['control_number' => '22110005', 'name' => 'Beto Inventado', ...$attributes]);
    $this->head = communicationHead();
});

it('CO-07: Comunicación cita, sube foto, marca lista, entrega y rechaza con motivo desde el drawer', function () {
    $request = CredentialRequest::factory()->create(['student_id' => $this->ana->id, 'wants_reposition' => true, 'wants_photo' => true]);
    $other = CredentialRequest::factory()->create(['student_id' => $this->beto->id]);

    $page = Livewire::actingAs($this->head)->test('communication::pages.credential-requests')
        ->assertOk()->assertSee('Ana Ficticia')
        ->call('openRequest', $request->id)
        ->assertSet('showRequest', true)
        ->set('scheduleDate', '2027-01-20')->set('scheduleTime', '10:30')->set('place', 'Edificio de Difusión')
        ->call('schedule')->assertHasNoErrors();

    expect($request->fresh())->status->toBe(Status::Citada)->place->toBe('Edificio de Difusión');

    $page->set('photo', UploadedFile::fake()->image('ana.jpg', 700, 900))->call('savePhoto')->assertHasNoErrors();
    expect(Storage::disk('student_photos')->exists('2021/21111182.jpg'))->toBeTrue();

    $page->call('markReady')->assertHasNoErrors();
    expect($request->fresh()->status)->toBe(Status::Lista);

    $page->call('deliver')->assertHasNoErrors();
    expect($request->fresh()->status)->toBe(Status::Entregada);

    $page->call('openRequest', $other->id)->call('openReject')->assertSet('showReject', true)
        ->call('reject')->assertHasErrors('reason')
        ->set('reason', 'No es alumno vigente')->call('reject')->assertHasNoErrors();
    expect($other->fresh())->status->toBe(Status::Rechazada)->rejection_reason->toBe('No es alumno vigente');
});

it('CO-07: Plazos guarda los días por tipo', function () {
    Livewire::actingAs($this->head)->test('communication::pages.credential-requests')
        ->call('openSettings')->assertSet('showSettings', true)
        ->set('repositionDays', 90)->set('photoDays', 180)
        ->call('saveSettings')->assertHasNoErrors();

    $settings = CredentialRequestSettings::fromSettings();
    expect($settings->repositionDays)->toBe(90)->and($settings->photoDays)->toBe(180)
        ->and(Setting::query()->count())->toBe(2);
});

it('CO-07: busca por número de control o nombre y separa por pestaña', function () {
    CredentialRequest::factory()->create(['student_id' => $this->ana->id]);
    CredentialRequest::factory()->create(['student_id' => $this->beto->id, 'status' => Status::Citada]);

    Livewire::actingAs($this->head)->test('communication::pages.credential-requests')
        ->assertSee('Ana Ficticia')->assertDontSee('Beto Inventado')
        ->set('tab', 'citada')->assertSee('Beto Inventado')->assertDontSee('Ana Ficticia')
        ->set('search', '22110005')->assertSee('Beto Inventado')
        ->set('tab', 'solicitada')->assertDontSee('Ana Ficticia');
});

it('CO-07: otro jefe y el personal de otra unidad no ven la bandeja', function () {
    communicationUnit();
    $hr = OrgUnit::factory()->create(['code' => 'RECURSOS-HUMANOS', 'name' => 'Departamento de Recursos Humanos', 'kind' => OrgUnitKind::Departamento]);
    $hrHead = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $hr->id])->create();
    $hrStaff = User::factory()->withRole(Role::Personal, ['org_unit_id' => $hr->id])->create();
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->ana->campus_id, 'career_id' => $this->ana->career_id])->create();

    foreach ([$hrHead, $hrStaff] as $user) {
        Livewire::actingAs($user)->test('communication::pages.credential-requests')->assertForbidden();
    }
    Livewire::actingAs($coordinator)->test('communication::pages.credential-requests')->assertForbidden();
    $this->actingAs($coordinator)->get(route('communication.credential-requests'))->assertForbidden();
    $this->actingAs(communicationStaff())->get(route('communication.credential-requests'))->assertOk();
});

it('CO-07: Inicio ofrece la bandeja y las fotos a Comunicación y no a quien no las ve', function () {
    Livewire::actingAs(communicationStaff())->test('core::pages.home')
        ->assertSee('Solicitudes de credencial')->assertSee('Fotos de alumnos')->assertSee('Fotos del personal');

    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->ana->campus_id, 'career_id' => $this->ana->career_id])->create();
    Livewire::actingAs($coordinator)->test('core::pages.home')->assertDontSee('Solicitudes de credencial');
});
