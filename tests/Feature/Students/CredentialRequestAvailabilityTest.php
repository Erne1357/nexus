<?php

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Students\Enums\CredentialRequestStatus;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Rules\CredentialRequestRules;
use Carbon\CarbonImmutable;

function credentialSetting(string $key, int $days): void
{
    Setting::query()->updateOrCreate(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => $key], ['value' => $days]);
}

test('CO-07: el plazo cuenta desde la última entregada; 0 es sin límite; el mensaje dice desde qué fecha', function () {
    $student = Student::factory()->create();
    $now = CarbonImmutable::parse('2027-03-01 10:00', 'America/Ciudad_Juarez');

    CredentialRequest::factory()->for($student)->delivered($now->subDays(60))->create(['wants_reposition' => true, 'wants_photo' => false]);

    // Plazo 0: sin límite.
    expect(CredentialRequestRules::availability($student, $now))->toBe(['reposition' => null, 'photo' => null]);

    credentialSetting('students.credential_reposition_days', 180);

    $availability = CredentialRequestRules::availability($student, $now);

    expect($availability['reposition'])->toBe('Podrás pedirla desde el 29 de junio de 2027.')
        ->and($availability['photo'])->toBeNull()
        ->and(CredentialRequestRules::availability($student, $now->addDays(121))['reposition'])->toBeNull();
});

test('CO-07: una abierta del mismo tipo bloquea ese tipo y no el otro', function () {
    $student = Student::factory()->create();

    CredentialRequest::factory()->for($student)->create(['wants_reposition' => true, 'wants_photo' => false, 'status' => CredentialRequestStatus::Citada]);

    expect(CredentialRequestRules::availability($student, CarbonImmutable::now()))
        ->toBe(['reposition' => 'Ya tienes una abierta.', 'photo' => null]);
});

test('CO-07: el plazo es por día calendario de Ciudad Juárez: se puede pedir desde las 00:00 del día, no desde la hora de la entrega', function () {
    $student = Student::factory()->create();
    credentialSetting('students.credential_reposition_days', 1);
    $delivered = CarbonImmutable::parse('2027-03-01 15:00', 'America/Ciudad_Juarez');

    CredentialRequest::factory()->for($student)->delivered($delivered)->create(['wants_reposition' => true, 'wants_photo' => false]);

    expect(CredentialRequestRules::availability($student, CarbonImmutable::parse('2027-03-01 23:59', 'America/Ciudad_Juarez'))['reposition'])
        ->toBe('Podrás pedirla desde el 2 de marzo de 2027.')
        ->and(CredentialRequestRules::availability($student, CarbonImmutable::parse('2027-03-02 00:00', 'America/Ciudad_Juarez'))['reposition'])->toBeNull();
});
