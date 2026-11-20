<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Actions\SyncStudentAccounts;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\StudentCredentialQuery;
use App\Modules\Students\Support\CredentialToken;
use App\Modules\Students\Support\StudentPhotos;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Credencial del alumno con la plantilla del ITCJ, foto y QR verificable (AC-20, tarea 1.27). Decisiones del usuario del
| 20 de noviembre de 2026: el QR cambia cada 30 s y abre una página pública de verificación; los egresados dicen
| «Egresado»; las fotos van en `fotos/<AAAA>/<número de control>.jpg`. Las pruebas usan bytes de prueba, nunca fotos reales.
*/

/** Unos bytes que empiezan como JPEG: basta para servir y comparar, sin fotos reales en el repositorio. */
const CREDENTIAL_FAKE_JPEG = "\xFF\xD8\xFF\xE0credencial-de-prueba";

beforeEach(function () {
    Storage::fake('student_photos');
    $campus = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $career = Career::factory()->for(Department::factory())->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $plan = StudyPlan::factory()->for($career)->create();
    $attributes = ['career_id' => $career->id, 'study_plan_id' => $plan->id, 'campus_id' => $campus->id, 'current_semester' => 9];
    $this->student = Student::factory()->create(['control_number' => '21111182', 'name' => 'Ana López Ruiz', 'status' => StudentStatus::Activo, ...$attributes]);
    $this->other = Student::factory()->create(['control_number' => '22110005', 'name' => 'Beto Soto', 'status' => StudentStatus::Activo, ...$attributes]);
    app(SyncStudentAccounts::class)->handle(['21111182' => '4821', '22110005' => '1357']);
    $this->user = $this->student->fresh()->user;
});

/**
 * El token que trae el QR del reverso, sacado de su URL.
 */
function credentialTokenIn(string $html): string
{
    preg_match('#data-qr-url="[^"]*/credencial/verificar/([A-Za-z0-9_-]+)"#', $html, $match);

    return $match[1] ?? '';
}

it('AC-20: la carpeta de la foto es el año de los dos primeros dígitos del número de control', function (string $control, ?string $folder) {
    expect(StudentPhotos::yearFolder($control))->toBe($folder);
})->with([
    ['21111182', '2021'],
    ['C19110045', '2019'],
    ['M2511', '2025'],
    ['ABC', null],
    ['', null],
]);

it('AC-20: la foto se busca en <AAAA>/<número de control> con jpg, jpeg, png o webp', function () {
    expect(app(StudentPhotos::class)->locate('21111182'))->toBeNull();

    Storage::disk('student_photos')->put('2021/21111182.png', CREDENTIAL_FAKE_JPEG);
    expect(app(StudentPhotos::class)->locate('21111182'))->toBe('2021/21111182.png');

    Storage::disk('student_photos')->put('2021/21111182.jpg', CREDENTIAL_FAKE_JPEG);
    expect(app(StudentPhotos::class)->locate('21111182'))->toBe('2021/21111182.jpg')
        ->and(app(StudentPhotos::class)->read('21111182'))->toBe(['bytes' => CREDENTIAL_FAKE_JPEG, 'mime' => 'image/jpeg'])
        ->and(app(StudentPhotos::class)->locate('../2021/21111182'))->toBeNull();
});

it('AC-20: el alumno ve su propia foto por su ruta y sin foto recibe 404', function () {
    $this->actingAs($this->user)->get(route('students.my-record.photo'))->assertNotFound();

    Storage::disk('student_photos')->put('2021/21111182.jpg', CREDENTIAL_FAKE_JPEG);

    $response = $this->actingAs($this->user)->get(route('students.my-record.photo'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // La URL es la misma para todos: sin caché, en una computadora compartida el siguiente alumno no ve la foto del anterior.
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->streamedContent())->toBe(CREDENTIAL_FAKE_JPEG);

});

it('AC-20: la foto de la credencial no la pide el personal sin puesto de alumno ni un invitado', function () {
    Storage::disk('student_photos')->put('2021/21111182.jpg', CREDENTIAL_FAKE_JPEG);

    $this->actingAs(User::factory()->withRole(Role::Docente)->create())->get(route('students.my-record.photo'))->assertForbidden();
});

it('AC-20: un invitado que pide la foto va al login', function () {
    $this->get(route('students.my-record.photo'))->assertRedirect(route('login'));
});

it('AC-20: el egresado ve «Egresado», la carrera abreviada como la plantilla y su foto en lugar de las iniciales', function () {
    // Hoy la cuenta del egresado se desactiva (SyncStudentAccounts); la credencial igual dice «Egresado» (20 de noviembre de 2026).
    $this->student->forceFill(['status' => StudentStatus::Egresado])->save();

    Livewire::actingAs($this->user)->withQueryParams(['tab' => 'credencial'])->test('students::pages.my-record')
        ->assertSee('Ana López Ruiz / 21111182')
        ->assertSee('Ing. Sistemas Computacionales / Egresado')
        ->assertDontSeeHtml('credential-photo-img');

    Storage::disk('student_photos')->put('2021/21111182.jpg', CREDENTIAL_FAKE_JPEG);

    Livewire::actingAs($this->user)->withQueryParams(['tab' => 'credencial'])->test('students::pages.my-record')
        ->assertSeeHtml('data-testid="credential-photo-img"')
        ->assertSeeHtml(route('students.my-record.photo').'?v=');
});

it('AC-20: la carrera se abrevia como la plantilla y el estatus solo da credencial al activo y al egresado', function () {
    expect(StudentCredentialQuery::shortCareer('Ingeniería en Sistemas Computacionales'))->toBe('Ing. Sistemas Computacionales')
        ->and(StudentCredentialQuery::shortCareer('Ingeniería Industrial'))->toBe('Ing. Industrial')
        ->and(StudentCredentialQuery::shortCareer('Licenciatura en Administración'))->toBe('Lic. Administración')
        ->and(StudentCredentialQuery::shortCareer('Contador Público'))->toBe('Contador Público')
        ->and(StudentCredentialQuery::statusLabel(StudentStatus::Activo))->toBe('Estudiante')
        ->and(StudentCredentialQuery::statusLabel(StudentStatus::Egresado))->toBe('Egresado')
        ->and(StudentCredentialQuery::statusLabel(StudentStatus::BajaTemporal))->toBeNull()
        ->and(StudentCredentialQuery::statusLabel(StudentStatus::BajaDefinitiva))->toBeNull()
        ->and(StudentCredentialQuery::statusLabel(null))->toBeNull();
});

it('AC-20: el token cifra al alumno y su ventana de 30 s, vale en su ventana y la siguiente, y no se puede alterar', function () {
    $tokens = app(CredentialToken::class);
    $id = (string) $this->student->id;
    $now = 1_800_000_010;
    $token = $tokens->issue($id, $now);

    expect($token)->toMatch('/^[A-Za-z0-9_-]{60,70}$/')
        ->not->toContain('21111182')
        ->not->toContain(str_replace('-', '', $id))
        ->and($tokens->issue($id, $now))->not->toBe($token)
        ->and($tokens->verify($token, $now))->toBe(['student' => $id, 'window' => CredentialToken::window($now)])
        ->and($tokens->verify($token, $now + 30)['student'] ?? null)->toBe($id)
        ->and($tokens->verify($token, $now + 60))->toBe('expired')
        ->and($tokens->verify($token, $now - 30))->toBe('expired')
        ->and($tokens->verify(substr($token, 0, -2).(str_ends_with($token, 'AA') ? 'BB' : 'AA'), $now))->toBeNull()
        ->and($tokens->verify('no-es-un-token-de-nexus-1234567890', $now))->toBeNull()
        ->and(CredentialToken::secondsLeft($now))->toBe(20);
});

it('AC-20: el reverso lleva el QR con la URL pública de verificación y cambia al pasar 30 s', function () {
    $this->travelTo(now()->startOfMinute());
    $first = Livewire::actingAs($this->user)->test('students::components.credential-qr', ['studentId' => (string) $this->student->id]);
    $token = credentialTokenIn($first->html());

    expect($token)->not->toBe('')
        ->and(app(CredentialToken::class)->verify($token, now()->getTimestamp())['student'] ?? null)->toBe((string) $this->student->id);
    $first->assertSeeHtml('data-testid="credential-qr"')->assertSeeHtml('<svg')->assertSee('Se renueva en');

    $this->travel(31)->seconds();
    $next = credentialTokenIn($first->call('$refresh')->html());

    expect($next)->not->toBe('')->not->toBe($token);
});

it('AC-20: nadie pinta el QR de otro alumno, y un alumno de baja no tiene QR', function () {
    $otherUser = $this->other->fresh()->user;

    Livewire::actingAs($otherUser)->test('students::components.credential-qr', ['studentId' => (string) $this->student->id])->assertForbidden();

    $this->student->forceFill(['status' => StudentStatus::BajaDefinitiva])->save();

    Livewire::actingAs($this->user)->test('students::components.credential-qr', ['studentId' => (string) $this->student->id])->assertForbidden();
    Livewire::actingAs($this->user)->withQueryParams(['tab' => 'credencial'])->test('students::pages.my-record')
        ->assertSee('Credencial no vigente')
        ->assertDontSeeHtml('data-testid="credential-qr"');
});

it('AC-20: la verificación pública con el código vigente muestra foto, nombre, número de control, carrera y estatus', function () {
    $this->student->forceFill(['status' => StudentStatus::Egresado])->save();
    Storage::disk('student_photos')->put('2021/21111182.jpg', CREDENTIAL_FAKE_JPEG);
    $token = app(CredentialToken::class)->issue((string) $this->student->id, now()->getTimestamp());

    $this->get(route('credential.verify', ['token' => $token]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertCookieMissing(config('session.cookie'))
        ->assertSee('Credencial vigente')
        ->assertSee('Ana López Ruiz')
        ->assertSee('21111182')
        ->assertSee('Ingeniería en Sistemas Computacionales')
        ->assertSee('Egresado')
        ->assertSee('data:image/jpeg;base64,'.base64_encode(CREDENTIAL_FAKE_JPEG), false);
});

it('AC-20: con el código vencido, alterado o de una credencial de baja no muestra ningún dato', function () {
    $token = app(CredentialToken::class)->issue((string) $this->student->id, now()->getTimestamp());

    $this->travel(61)->seconds();
    $this->get(route('credential.verify', ['token' => $token]))
        ->assertOk()->assertSee('Este código ya venció')->assertDontSee('Ana López Ruiz')->assertDontSee('21111182');

    $this->get(route('credential.verify', ['token' => str_repeat('A', 64)]))
        ->assertOk()->assertSee('Código no reconocido')->assertDontSee('Ana López Ruiz');

    $this->student->forceFill(['status' => StudentStatus::BajaTemporal])->save();
    $fresh = app(CredentialToken::class)->issue((string) $this->student->id, now()->getTimestamp());
    $this->get(route('credential.verify', ['token' => $fresh]))
        ->assertOk()->assertSee('Credencial no vigente')->assertDontSee('Ana López Ruiz')->assertDontSee('21111182');

    $this->get('/credencial/verificar/'.urlencode('../../etc'))->assertNotFound();
});

it('AC-20: la verificación pública tiene límite por dirección y código, y otro código de la misma dirección sigue', function () {
    $token = app(CredentialToken::class)->issue((string) $this->student->id, now()->getTimestamp());

    foreach (range(1, 30) as $ignored) {
        $this->get(route('credential.verify', ['token' => $token]))->assertOk();
    }

    $this->get(route('credential.verify', ['token' => $token]))->assertTooManyRequests();

    $other = app(CredentialToken::class)->issue((string) $this->other->id, now()->getTimestamp());
    $this->get(route('credential.verify', ['token' => $other]))->assertOk();
});

it('AC-20: el token de un alumno que no existe, de otra APP_KEY, de una ventana futura o de largo malo no da datos', function () {
    $tokens = app(CredentialToken::class);
    $now = now()->getTimestamp();

    expect($tokens->verify($tokens->issue('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $now), $now))->not->toBeNull();
    $this->get(route('credential.verify', ['token' => $tokens->issue('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $now)]))
        ->assertOk()->assertSee('Código no reconocido');

    $future = $tokens->issue((string) $this->student->id, $now + 60);
    expect($tokens->verify($future, $now))->toBe('expired');

    $raw = base64_decode(strtr($tokens->issue((string) $this->student->id, $now), '-_', '+/').'==');
    $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    expect(strlen($raw))->toBe(48)
        ->and($tokens->verify($encode(substr($raw, 0, 47)), $now))->toBeNull()
        ->and($tokens->verify($encode($raw."\x00"), $now))->toBeNull();

    $token = $tokens->issue((string) $this->student->id, $now);
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->forgetInstance('encrypter');
    expect(app(CredentialToken::class)->verify($token, $now))->toBeNull();
});

it('AC-20: el QR apunta a APP_URL aunque se entre por otro host, y una foto más pesada que el tope no se usa', function () {
    config(['app.url' => 'https://nexus.cdjuarez.tecnm.mx']);
    $qr = app(StudentCredentialQuery::class)->qrFor($this->student, now()->getTimestamp());

    expect($qr['url'])->toStartWith('https://nexus.cdjuarez.tecnm.mx/credencial/verificar/');

    Storage::disk('student_photos')->put('2021/21111182.jpg', str_repeat('x', StudentPhotos::MAX_BYTES + 1));
    expect(app(StudentPhotos::class)->locate('21111182'))->toBeNull()
        ->and(app(StudentPhotos::class)->read('21111182'))->toBeNull();
});
