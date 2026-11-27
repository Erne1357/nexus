<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Students\Enums\CredentialRequestStatus as S;
use App\Modules\Students\Enums\StudentStatus;
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
        ->set('repositionReason', 'first_time')
        ->set('siisaePhotoVisible', 'si')
        ->set('siisaeDataCorrect', 'si')
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

it('CO-S4: sin credencial vigente el alumno no ve «Solicitar»', function () {
    [$student, $user] = credentialStudent();

    credentialPage($user)->assertSeeHtml('data-testid="credential-request-open"');

    $student->forceFill(['status' => StudentStatus::BajaTemporal])->save();

    credentialPage($user)->assertDontSeeHtml('data-testid="credential-request-open"');
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

    credentialPage($user)->set('wantsReposition', true)->set('repositionReason', 'first_time')->set('siisaePhotoVisible', 'si')->set('siisaeDataCorrect', 'si')
        ->call('requestCredential')->assertHasErrors(['reposition']);

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

it('CO-12: marcar la reposición abre el motivo y, al elegirlo, las preguntas del SIISAE', function () {
    [, $user] = credentialStudent();

    credentialPage($user)
        ->assertDontSeeHtml('data-testid="reposition-questions"')
        ->set('wantsReposition', true)
        ->assertSeeHtml('data-testid="reposition-questions"')
        ->assertSee('Por primera vez')
        ->assertSee('Por daño o extravío (no era tarjeta Santander)')
        ->assertSee('Por daño o extravío (ya contaba con tarjeta Santander)')
        ->assertDontSeeHtml('data-testid="siisae-block"')
        ->set('repositionReason', 'first_time')
        ->assertSeeHtml('data-testid="siisae-block"')
        ->assertSee('Sistema Integral de Servicios Administrativos y Estudiantiles del TecNM-ITCJ')
        ->assertSeeHtml('href="http://siisae.cdjuarez.tecnm.mx/login/login"')
        ->assertSee('¿En el SIISAE puedes ver tu fotografía?')
        ->assertSee('¿En el SIISAE tus datos son correctos?')
        ->assertSee('Contesta el motivo y las dos preguntas del SIISAE.')
        ->set('wantsReposition', false)
        ->assertDontSeeHtml('data-testid="reposition-questions"')
        ->assertSet('repositionReason', null);
});

it('CO-12: sin contestar no pide y el servidor dice qué falta', function () {
    [$student, $user] = credentialStudent();

    credentialPage($user)->set('wantsReposition', true)->call('requestCredential')
        ->assertHasErrors(['reason', 'photo_visible', 'data_correct']);

    expect(CredentialRequest::query()->where('student_id', $student->id)->count())->toBe(0);
});

it('CO-12: con los datos incorrectos muestra el aviso con el enlace, deshabilita Solicitar y el servidor no pide', function () {
    [$student, $user] = credentialStudent();

    credentialPage($user)
        ->set('wantsReposition', true)
        ->set('repositionReason', 'lost_not_santander')
        ->set('siisaePhotoVisible', 'si')
        ->set('siisaeDataCorrect', 'no')
        ->assertSeeHtml('data-testid="data-blocked"')
        ->assertSee('Todavía no puedes pedir la reposición.')
        ->assertSee('Si no te aparece la opción «Validar datos», espera a que se publique la convocatoria para validar datos en el SIISAE.')
        ->assertSeeHtml('data-testid="siisae-link-blocked"')
        ->assertSee('Primero valida tus datos en el SIISAE.')
        ->assertSeeHtml('data-testid="credential-request-submit"')
        ->call('requestCredential')
        ->assertHasErrors(['data_correct']);

    expect(CredentialRequest::query()->where('student_id', $student->id)->count())->toBe(0);
});

it('CO-12: sin foto en el SIISAE marca la foto y la bloquea, y la pide con la reposición', function () {
    [$student, $user] = credentialStudent();

    $page = credentialPage($user)
        ->set('wantsReposition', true)
        ->set('repositionReason', 'lost_was_santander')
        ->set('siisaeDataCorrect', 'si')
        ->set('siisaePhotoVisible', 'no')
        ->assertSet('wantsPhoto', true)
        ->assertSeeHtml('data-testid="photo-added"')
        ->assertSeeHtml('data-testid="reason-photo-locked"')
        ->assertSee('Se agregó porque no ves tu foto en el SIISAE.');

    // Si cambia su respuesta, la foto que marcó el cuestionario se desmarca.
    $page->set('siisaePhotoVisible', 'si')->assertSet('wantsPhoto', false)->assertDontSeeHtml('data-testid="photo-added"');

    $page->set('siisaePhotoVisible', 'no')->call('requestCredential')->assertHasNoErrors();

    $request = CredentialRequest::query()->where('student_id', $student->id)->sole();
    expect($request->wants_reposition)->toBeTrue()->and($request->wants_photo)->toBeTrue()
        ->and($request->reposition_reason->value)->toBe('lost_was_santander');
});

it('CO-12 (supuesto por confirmar): sin foto en el SIISAE y la foto no disponible, avisa que no se pudo agregar y sigue la reposición', function () {
    [$student, $user] = credentialStudent();
    CredentialRequest::factory()->for($student)->create(['wants_reposition' => false, 'wants_photo' => true]);

    credentialPage($user)
        ->set('wantsReposition', true)
        ->set('repositionReason', 'first_time')
        ->set('siisaeDataCorrect', 'si')
        ->set('siisaePhotoVisible', 'no')
        ->assertSet('wantsPhoto', false)
        ->assertSeeHtml('data-testid="photo-not-added"')
        ->assertSee('No pudimos agregar la foto')
        ->call('requestCredential')
        ->assertHasNoErrors();

    expect(CredentialRequest::query()->where('student_id', $student->id)->where('wants_reposition', true)->sole()->wants_photo)->toBeFalse();
});

it('CO-12 (supuesto por confirmar): la foto sola no pregunta nada del SIISAE', function () {
    [$student, $user] = credentialStudent();

    credentialPage($user)->set('wantsPhoto', true)
        ->assertDontSeeHtml('data-testid="siisae-block"')
        ->call('requestCredential')->assertHasNoErrors();

    expect(CredentialRequest::query()->where('student_id', $student->id)->sole()->siisae_data_correct)->toBeNull();
});

it('CO-12: el alumno ve el motivo en su solicitud abierta y en su historial', function () {
    [$student, $user] = credentialStudent();
    CredentialRequest::factory()->for($student)->create(['reposition_reason' => 'first_time']);

    credentialPage($user)
        ->assertSeeHtml('data-testid="open-request-reason"')
        ->assertSeeHtml('data-testid="credential-request-reason"')
        ->assertSee('Motivo: Por primera vez');
});

it('1.89: la casilla de la foto es en vivo y, con solo la foto marcada, el pie ya no trae el motivo', function () {
    [$student, $user] = credentialStudent();

    credentialPage($user)
        ->assertSeeHtml('wire:model.live="wantsPhoto"')
        ->assertSeeHtml('data-testid="request-why"')
        ->set('wantsPhoto', true)
        ->assertDontSeeHtml('data-testid="request-why"')
        ->assertSeeHtml('data-testid="request-summary"')
        ->call('requestCredential')->assertHasNoErrors();

    expect(CredentialRequest::query()->where('student_id', $student->id)->sole()->wants_photo)->toBeTrue();
});
