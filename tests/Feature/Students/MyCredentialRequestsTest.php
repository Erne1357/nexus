<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Students\Enums\CredentialRequestStatus as S;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Models\Student;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
| «Mis datos › Credencial» (CO-07, CO-S2): el alumno pide desde un drawer, ve su solicitud abierta con estado y cita,
| la cancela mientras está solicitada y ve su historial.
*/

function credentialStudent(): array
{
    $user = User::factory()->withRole(Role::Alumno)->create();
    $student = Student::factory()->create();
    $student->forceFill(['user_id' => $user->id])->save();

    return [$student, $user];
}

function credentialPage(User $user)
{
    return Livewire::actingAs($user)->withQueryParams(['tab' => 'credencial'])->test('students::pages.my-record');
}

it('CO-07: el alumno pide desde el drawer y ve su solicitud con estado y cita', function () {
    [$student, $user] = credentialStudent();

    credentialPage($user)
        ->assertSeeHtml('data-testid="credential-request-open"')
        ->assertSeeHtml('data-testid="request-drawer"')
        ->set('wantsReposition', true)
        ->set('wantsPhoto', true)
        ->call('requestCredential')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-testid="open-request"')
        ->assertSee('Solicitada')
        ->assertDontSeeHtml('data-testid="credential-request-open"');

    $request = CredentialRequest::query()->where('student_id', $student->id)->sole();
    expect($request->wants_reposition)->toBeTrue()->and($request->wants_photo)->toBeTrue();

    $request->forceFill(['status' => S::Citada, 'scheduled_at' => CarbonImmutable::parse('2027-05-12 10:30', 'America/Ciudad_Juarez'), 'place' => 'Ventanilla de Comunicación'])->save();

    credentialPage($user)
        ->assertSee('Citada')
        ->assertSee('12 de mayo de 2027')
        ->assertSee('10:30')
        ->assertSee('Ventanilla de Comunicación')
        ->assertDontSeeHtml('data-testid="cancel-request"');
});

it('CO-07: una casilla no disponible va deshabilitada y muestra el motivo de availability()', function () {
    [$student, $user] = credentialStudent();
    CredentialRequest::factory()->for($student)->create(['wants_reposition' => true, 'wants_photo' => false, 'status' => S::Citada]);

    credentialPage($user)
        ->assertSeeHtml('data-testid="reason-reposition"')
        ->assertSee('Ya tienes una abierta.')
        ->assertDontSeeHtml('data-testid="reason-photo"');

    Setting::query()->updateOrCreate(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'students.credential_photo_days'], ['value' => 30]);
    CredentialRequest::factory()->for($student)->delivered(now()->subDay())->create(['wants_reposition' => false, 'wants_photo' => true]);

    credentialPage($user)->assertSeeHtml('data-testid="reason-photo"')->assertSee('Podrás pedirla desde el');
});

it('CO-07: pedir un tipo no disponible no crea nada y dice por qué', function () {
    [$student, $user] = credentialStudent();
    CredentialRequest::factory()->for($student)->create(['wants_reposition' => true, 'wants_photo' => false]);

    credentialPage($user)->set('wantsReposition', true)->call('requestCredential')->assertHasErrors(['reposition']);

    expect(CredentialRequest::query()->where('student_id', $student->id)->count())->toBe(1);
});

it('CO-S2: cancela mientras está solicitada', function () {
    [$student, $user] = credentialStudent();
    $request = CredentialRequest::factory()->for($student)->create();

    credentialPage($user)->assertSeeHtml('data-testid="cancel-request"')->call('cancelRequest', $request->id)->assertDontSeeHtml('data-testid="open-request"');

    expect($request->fresh()->status)->toBe(S::Cancelada);
});

it('CO-S2: no cancela una citada ni la de otro alumno', function () {
    [$student, $user] = credentialStudent();
    [$otherStudent] = credentialStudent();
    $citada = CredentialRequest::factory()->for($student)->create(['status' => S::Citada]);
    $theirs = CredentialRequest::factory()->for($otherStudent)->create();

    credentialPage($user)->call('cancelRequest', $citada->id)->assertForbidden();
    credentialPage($user)->call('cancelRequest', $theirs->id)->assertNotFound();

    expect($citada->fresh()->status)->toBe(S::Citada)->and($theirs->fresh()->status)->toBe(S::Solicitada);
});

it('CO-10: el historial lista las solicitudes del alumno, no las de otros', function () {
    [$student, $user] = credentialStudent();
    [$other] = credentialStudent();
    CredentialRequest::factory()->for($student)->delivered()->create(['wants_reposition' => false, 'wants_photo' => true]);
    CredentialRequest::factory()->for($student)->create(['status' => S::Rechazada, 'rejection_reason' => 'Foto borrosa']);
    CredentialRequest::factory()->for($other)->create(['status' => S::Rechazada, 'rejection_reason' => 'Motivo ajeno']);

    credentialPage($user)
        ->assertSeeHtml('data-testid="credential-requests-history"')
        ->assertSee('Entregada')
        ->assertSee('Foto borrosa')
        ->assertDontSee('Motivo ajeno');
});

it('CO-07 (C4): con una reposición abierta puede pedir la foto, y la reposición queda deshabilitada con su motivo', function () {
    [$student, $user] = credentialStudent();
    CredentialRequest::factory()->for($student)->create(['wants_reposition' => true, 'wants_photo' => false]);

    credentialPage($user)
        ->assertSeeHtml('data-testid="credential-request-open"')
        ->assertSee('Ya tienes una abierta.')
        ->set('wantsPhoto', true)
        ->call('requestCredential')
        ->assertHasNoErrors();

    expect(CredentialRequest::query()->where('student_id', $student->id)->where('wants_photo', true)->count())->toBe(1);
});

it('CO-S2: con dos abiertas cancela la solicitada y no la citada', function () {
    [$student, $user] = credentialStudent();
    $citada = CredentialRequest::factory()->for($student)->create(['wants_reposition' => true, 'wants_photo' => false, 'status' => S::Citada]);
    $solicitada = CredentialRequest::factory()->for($student)->create(['wants_reposition' => false, 'wants_photo' => true]);

    credentialPage($user)->assertSeeHtml('data-testid="cancel-request"')->call('cancelRequest', $solicitada->id);

    expect($solicitada->fresh()->status)->toBe(S::Cancelada)->and($citada->fresh()->status)->toBe(S::Citada);
});

it('CO-07: enviar el drawer sin marcar nada muestra «Elige qué quieres pedir.»', function () {
    [, $user] = credentialStudent();

    credentialPage($user)->call('requestCredential')->assertHasErrors(['request'])->assertSee('Elige qué quieres pedir.');
});
