<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Students\Actions\CancelCredentialRequest;
use App\Modules\Students\Actions\DeliverCredentialRequest;
use App\Modules\Students\Actions\MarkCredentialRequestReady;
use App\Modules\Students\Actions\RejectCredentialRequest;
use App\Modules\Students\Actions\RequestCredential;
use App\Modules\Students\Actions\ScheduleCredentialRequest;
use App\Modules\Students\Actions\SetStudentPhoto;
use App\Modules\Students\Actions\TouchOpenPhotoRequests;
use App\Modules\Students\Enums\CredentialRequestStatus as S;
use App\Modules\Students\Enums\RepositionReason;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Notifications\CredentialRequestUpdated;
use App\Modules\Students\Queries\CredentialRequestQuery;
use App\Modules\Students\Rules\InstitutionalEmail;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function studentWithUser(array $attributes = []): array
{
    $user = User::factory()->withRole(Role::Alumno)->create();
    $student = Student::factory()->create($attributes);
    $student->forceFill(['user_id' => $user->id])->save();

    return [$student, $user];
}

test('CO-07: pide reposición y foto a la vez', function () {
    [$student, $user] = studentWithUser();

    $request = app(RequestCredential::class)->handle($student, true, true, $user, 'first_time', true, true);

    expect($request->status)->toBe(S::Solicitada)
        ->and($request->wants_reposition)->toBeTrue()
        ->and($request->wants_photo)->toBeTrue()
        ->and($request->requested_at)->not->toBeNull();
});

test('CO-07: pedir sin elegir un tipo lanza ValidationException', function () {
    [$student, $user] = studentWithUser();

    app(RequestCredential::class)->handle($student, false, false, $user);
})->throws(ValidationException::class);

test('CO-07: no deja dos abiertas del mismo tipo, también con dos peticiones concurrentes (índice parcial)', function () {
    [$student, $user] = studentWithUser();
    app(RequestCredential::class)->handle($student, true, false, $user, 'first_time', true, true);

    expect(fn () => app(RequestCredential::class)->handle($student, true, true, $user, 'first_time', true, true))->toThrow(ValidationException::class, 'Ya tienes una abierta.');

    // El otro tipo sí se puede pedir.
    app(RequestCredential::class)->handle($student, false, true, $user);

    // La concurrencia: la segunda petición pasó la revisión, y el índice parcial la detiene.
    expect(fn () => DB::transaction(fn () => CredentialRequest::factory()->for($student)->create(['wants_reposition' => true, 'wants_photo' => false])))
        ->toThrow(QueryException::class);

    // Una cerrada no cuenta.
    CredentialRequest::factory()->for($student)->create(['wants_reposition' => true, 'wants_photo' => false, 'status' => S::Rechazada]);
    expect(CredentialRequest::query()->count())->toBe(3);
});

test('CO-07: el plazo bloquea la solicitud y dice desde cuándo', function () {
    [$student, $user] = studentWithUser();
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'students.credential_photo_days', 'value' => 30]);
    CredentialRequest::factory()->for($student)->delivered(now()->subDays(5))->create(['wants_reposition' => false, 'wants_photo' => true]);

    $date = now()->subDays(5)->addDays(30)->setTimezone('America/Ciudad_Juarez')->locale('es')->isoFormat('D [de] MMMM [de] YYYY');

    expect(fn () => app(RequestCredential::class)->handle($student, false, true, $user))
        ->toThrow(ValidationException::class, "Podrás pedirla desde el {$date}.");
});

test('CO-S4: sin credencial vigente no puede pedir', function () {
    [$student, $user] = studentWithUser(['status' => StudentStatus::BajaDefinitiva]);

    expect(fn () => app(RequestCredential::class)->handle($student, true, false, $user, 'first_time', true, true))->toThrow(ValidationException::class);

    $egresado = Student::factory()->create(['status' => StudentStatus::Egresado]);
    expect(app(RequestCredential::class)->handle($egresado, true, false, $user, 'first_time', true, true)->status)->toBe(S::Solicitada);
});

/**
 * Cada acción desde cada estado: las válidas y las que lanzan ValidationException (CO-08).
 */
dataset('transitions', function () {
    $at = CarbonImmutable::parse('2027-05-12 10:00');
    $actions = [
        'cancel' => fn ($r, $u) => app(CancelCredentialRequest::class)->handle($r, $u),
        'schedule' => fn ($r, $u) => app(ScheduleCredentialRequest::class)->handle($r, $at, 'Edificio A', $u),
        'ready' => fn ($r, $u) => app(MarkCredentialRequestReady::class)->handle($r, $u),
        'deliver' => fn ($r, $u) => app(DeliverCredentialRequest::class)->handle($r, $u),
        'reject' => fn ($r, $u) => app(RejectCredentialRequest::class)->handle($r, 'Sin foto legible', $u),
    ];
    // Reposición sola; las demás combinaciones se prueban aparte.
    $valid = [
        'cancel' => [S::Solicitada],
        'schedule' => [S::Solicitada, S::Citada],
        'ready' => [S::Citada],
        'deliver' => [S::Lista],
        'reject' => [S::Solicitada, S::Citada, S::Lista],
    ];
    $result = ['cancel' => S::Cancelada, 'schedule' => S::Citada, 'ready' => S::Lista, 'deliver' => S::Entregada, 'reject' => S::Rechazada];

    foreach ($actions as $name => $run) {
        foreach (S::cases() as $status) {
            yield "{$name} desde {$status->value}" => [$run, $status, in_array($status, $valid[$name], true), $result[$name]];
        }
    }
});

test('CO-08: transiciones válidas y las que lanzan ValidationException', function (Closure $run, S $from, bool $valid, S $to) {
    $by = User::factory()->create();
    $request = CredentialRequest::factory()->create(['wants_reposition' => true, 'wants_photo' => false, 'status' => $from]);

    if ($valid) {
        $run($request, $by);
        expect($request->fresh()->status)->toBe($to);
    } else {
        expect(fn () => $run($request, $by))->toThrow(ValidationException::class);
        expect($request->fresh()->status)->toBe($from);
    }
})->with('transitions');

test('CO-08: marcar lista exige reposición; entregar solo foto sale de citada', function () {
    $by = User::factory()->create();

    $photoOnly = CredentialRequest::factory()->create(['wants_reposition' => false, 'wants_photo' => true, 'status' => S::Citada, 'photo_updated_at' => now()]);
    expect(fn () => app(MarkCredentialRequestReady::class)->handle($photoOnly, $by))->toThrow(ValidationException::class);

    app(DeliverCredentialRequest::class)->handle($photoOnly, $by);
    expect($photoOnly->fresh()->status)->toBe(S::Entregada)->and($photoOnly->fresh()->delivered_at)->not->toBeNull();

    $both = CredentialRequest::factory()->create(['wants_reposition' => true, 'wants_photo' => true, 'status' => S::Citada, 'photo_updated_at' => now()]);
    expect(fn () => app(DeliverCredentialRequest::class)->handle($both, $by))->toThrow(ValidationException::class);
});

test('CO-08: rechazar pide motivo', function () {
    $request = CredentialRequest::factory()->create(['status' => S::Solicitada]);

    expect(fn () => app(RejectCredentialRequest::class)->handle($request, '  ', User::factory()->create()))->toThrow(ValidationException::class);
});

test('CO-S2: el alumno cancela solo solicitada', function () {
    [$student, $user] = studentWithUser();
    $request = CredentialRequest::factory()->for($student)->create(['status' => S::Solicitada]);
    $other = User::factory()->withRole(Role::Alumno)->create();

    expect(Gate::forUser($other)->allows('cancel', $request))->toBeFalse()
        ->and(Gate::forUser($user)->allows('cancel', $request))->toBeTrue();

    app(CancelCredentialRequest::class)->handle($request, $user);
    expect($request->fresh()->status)->toBe(S::Cancelada)->and($request->fresh()->cancelled_at)->not->toBeNull();

    $citada = CredentialRequest::factory()->for($student)->create(['status' => S::Citada, 'wants_reposition' => false, 'wants_photo' => true]);
    expect(Gate::forUser($user)->allows('cancel', $citada))->toBeFalse();
});

test('CO-S8: entregar con foto exige photo_updated_at, que pone SetStudentPhoto', function () {
    $by = User::factory()->create();
    $student = Student::factory()->create();
    $request = CredentialRequest::factory()->for($student)->create(['wants_reposition' => false, 'wants_photo' => true, 'status' => S::Citada]);
    $reposition = CredentialRequest::factory()->for($student)->create(['wants_reposition' => true, 'wants_photo' => false, 'status' => S::Citada]);

    expect(fn () => app(DeliverCredentialRequest::class)->handle($request, $by))->toThrow(ValidationException::class);

    app(TouchOpenPhotoRequests::class)->handle($student);

    expect($request->fresh()->photo_updated_at)->not->toBeNull()
        ->and($reposition->fresh()->photo_updated_at)->toBeNull();

    app(DeliverCredentialRequest::class)->handle($request->fresh(), $by);
    expect($request->fresh()->status)->toBe(S::Entregada);
});

test('CO-09: citar, reprogramar, lista, entregada y rechazada mandan la campana y el correo al institucional; cancelar no manda nada', function () {
    Notification::fake();
    [$student, $user] = studentWithUser();
    $by = communicationStaff();
    $request = CredentialRequest::factory()->for($student)->create(['wants_reposition' => true, 'wants_photo' => false, 'status' => S::Solicitada]);

    $schedule = app(ScheduleCredentialRequest::class);
    $schedule->handle($request, CarbonImmutable::parse('2027-05-12 10:00'), 'Edificio A', $by);
    $schedule->handle($request->fresh(), CarbonImmutable::parse('2027-05-13 11:00'), 'Edificio B', $by);
    app(MarkCredentialRequestReady::class)->handle($request->fresh(), $by);
    app(DeliverCredentialRequest::class)->handle($request->fresh(), $by);

    $rejected = CredentialRequest::factory()->for($student)->create(['wants_reposition' => false, 'wants_photo' => true, 'status' => S::Solicitada]);
    app(RejectCredentialRequest::class)->handle($rejected, 'La foto no sirve', $by);

    Notification::assertSentToTimes($user, CredentialRequestUpdated::class, 5);
    Notification::assertSentTo($user, CredentialRequestUpdated::class, function (CredentialRequestUpdated $notification) use ($user, $student): bool {
        return $notification->via($user) === ['database', 'mail']
            && $notification->mailAddressFor($user) === InstitutionalEmail::of($student->control_number)
            && $notification->message($user)->route === 'students.my-record'
            && $notification->message($user)->parameters === ['tab' => 'credencial'];
    });

    Notification::fake();
    $again = CredentialRequest::factory()->for($student)->create(['wants_reposition' => true, 'wants_photo' => false, 'status' => S::Solicitada]);
    app(CancelCredentialRequest::class)->handle($again, $user);
    Notification::assertNothingSent();
});

test('CO-09 y CE-01: el aviso va al correo de su usuario solo si es institucional; si no, al de su número de control', function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);
    [$student, $user] = studentWithUser(['control_number' => '21110002']);
    $notice = new CredentialRequestUpdated(CredentialRequest::factory()->for($student)->create(), CredentialRequestUpdated::READY);

    $user->forceFill(['email' => 'alumno@gmail.com'])->save();
    expect($notice->mailAddressFor($user))->toBe('l21110002@cdjuarez.tecnm.mx');

    $user->forceFill(['email' => 'Otro.Correo@cdjuarez.tecnm.mx'])->save();
    expect($notice->mailAddressFor($user))->toBe('otro.correo@cdjuarez.tecnm.mx');
});

test('CO-01: otro jefe no administra solicitudes', function () {
    $request = CredentialRequest::factory()->create();

    $comunicacion = communicationHead();
    $staff = communicationStaff();
    $otherHead = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => OrgUnit::factory()->create()->id])->create();
    $student = User::factory()->withRole(Role::Alumno)->create();

    expect(Gate::forUser($comunicacion)->allows('manage', CredentialRequest::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('manage', CredentialRequest::class))->toBeTrue()
        ->and(Gate::forUser($otherHead)->allows('manage', CredentialRequest::class))->toBeFalse()
        ->and(Gate::forUser($student)->allows('manage', CredentialRequest::class))->toBeFalse();
});

test('CO-S1: la bandeja cuenta y filtra por estado y busca por nombre o número de control', function () {
    $ana = Student::factory()->create(['name' => 'Ana Pérez', 'control_number' => '27110001']);
    $luis = Student::factory()->create(['name' => 'Luis Gómez', 'control_number' => '27110002']);
    CredentialRequest::factory()->for($ana)->create(['status' => S::Solicitada]);
    CredentialRequest::factory()->for($luis)->create(['status' => S::Lista]);
    CredentialRequest::factory()->for($luis)->create(['status' => S::Entregada, 'delivered_at' => now()]);

    $query = app(CredentialRequestQuery::class);

    expect($query->counts())->toMatchArray(['solicitada' => 1, 'lista' => 1, 'entregada' => 1, 'citada' => 0])
        ->and($query->tray(S::Lista, '')->total())->toBe(1)
        ->and($query->tray(null, 'Ana')->total())->toBe(1)
        ->and($query->tray(null, '27110002')->total())->toBe(2)
        ->and($query->history($luis))->toHaveCount(2);
});

test('CO-S8: subir la foto con SetStudentPhoto marca la solicitud abierta y deja entregarla', function () {
    Storage::fake('student_photos');
    $by = communicationStaff();
    $student = Student::factory()->create(['control_number' => '27110099']);
    $request = CredentialRequest::factory()->for($student)->create(['wants_reposition' => false, 'wants_photo' => true, 'status' => S::Citada]);
    $closed = CredentialRequest::factory()->for($student)->create(['wants_reposition' => true, 'wants_photo' => false, 'status' => S::Citada]);

    expect(fn () => app(DeliverCredentialRequest::class)->handle($request, $by))->toThrow(ValidationException::class);

    app(SetStudentPhoto::class)->handle($student, UploadedFile::fake()->image('f.jpg', 300, 400), $by);

    expect($request->fresh()->photo_updated_at)->not->toBeNull()
        ->and($closed->fresh()->photo_updated_at)->toBeNull();

    app(DeliverCredentialRequest::class)->handle($request->fresh(), $by);
    expect($request->fresh()->status)->toBe(S::Entregada);
});

it('CO-S9: no se cita en el pasado', function () {
    Notification::fake();
    [$student] = studentWithUser();
    $request = CredentialRequest::factory()->for($student)->create(['wants_reposition' => true, 'status' => S::Solicitada]);

    expect(fn () => app(ScheduleCredentialRequest::class)->handle($request, CarbonImmutable::now()->subDay(), 'Edificio A', communicationStaff()))
        ->toThrow(ValidationException::class, 'La cita debe ser posterior a este momento.');
    expect($request->fresh()->status)->toBe(S::Solicitada);
});

test('CO-12: la reposición exige el motivo y las dos respuestas del SIISAE', function (?string $reason, ?bool $photoVisible, ?bool $dataCorrect, string $field) {
    [$student, $user] = studentWithUser();

    try {
        app(RequestCredential::class)->handle($student, true, false, $user, $reason, $photoVisible, $dataCorrect);
        $this->fail('Debió lanzar ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(CredentialRequest::query()->count())->toBe(0);
})->with([
    'sin motivo' => [null, true, true, 'reason'],
    'motivo inventado' => ['otro', true, true, 'reason'],
    'sin respuesta de la foto' => ['first_time', null, true, 'photo_visible'],
    'sin respuesta de los datos' => ['first_time', true, null, 'data_correct'],
]);

test('CO-12: guarda el motivo y las respuestas, con los tres motivos', function (RepositionReason $reason) {
    [$student, $user] = studentWithUser();

    $request = app(RequestCredential::class)->handle($student, true, false, $user, $reason->value, true, true);

    expect($request->fresh()->reposition_reason)->toBe($reason)
        ->and($request->siisae_photo_visible)->toBeTrue()
        ->and($request->siisae_data_correct)->toBeTrue()
        ->and($request->wants_photo)->toBeFalse();
})->with(RepositionReason::cases());

test('CO-12: con los datos incorrectos no se pide la reposición, ni sola ni con la foto', function (bool $photo) {
    [$student, $user] = studentWithUser();

    expect(fn () => app(RequestCredential::class)->handle($student, true, $photo, $user, 'first_time', true, false))
        ->toThrow(ValidationException::class, 'Valida tus datos en el SIISAE');

    expect(CredentialRequest::query()->count())->toBe(0);
})->with([false, true]);

test('CO-12: sin foto en el SIISAE la reposición lleva también la foto', function () {
    [$student, $user] = studentWithUser();

    $request = app(RequestCredential::class)->handle($student, true, false, $user, 'lost_was_santander', false, true);

    expect($request->wants_reposition)->toBeTrue()
        ->and($request->wants_photo)->toBeTrue()
        ->and($request->siisae_photo_visible)->toBeFalse()
        ->and($request->photoAddedBySiisae())->toBeTrue();
});

test('CO-12 (supuesto por confirmar): sin foto en el SIISAE pero con la foto no disponible, la reposición sigue sin la foto', function () {
    [$student, $user] = studentWithUser();
    CredentialRequest::factory()->for($student)->create(['wants_reposition' => false, 'wants_photo' => true]);

    $request = app(RequestCredential::class)->handle($student, true, false, $user, 'first_time', false, true);

    expect($request->wants_reposition)->toBeTrue()->and($request->wants_photo)->toBeFalse();
});

test('CO-12 (supuesto por confirmar): la foto sola no pregunta nada del SIISAE y no guarda respuestas', function () {
    [$student, $user] = studentWithUser();

    $request = app(RequestCredential::class)->handle($student, false, true, $user, 'first_time', false, false);

    expect($request->reposition_reason)->toBeNull()
        ->and($request->siisae_photo_visible)->toBeNull()
        ->and($request->siisae_data_correct)->toBeNull();
});

test('CO-12: la base solo admite los tres motivos', function () {
    [$student] = studentWithUser();

    $request = CredentialRequest::factory()->for($student)->create();

    expect(fn () => DB::transaction(fn () => DB::table('credential_requests')->where('id', $request->id)->update(['reposition_reason' => 'otro'])))
        ->toThrow(QueryException::class);
});
