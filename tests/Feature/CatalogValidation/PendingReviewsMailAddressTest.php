<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Actions\NotifyPendingReviews;
use App\Modules\CatalogValidation\Actions\RemindPendingReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;

/*
| CV11 (confirmado el 15 de noviembre de 2026): el correo del aviso de apertura y del recordatorio de la validación va
| al correo institucional de la ficha de docente del jefe; si la ficha no tiene, al de su usuario, si es del dominio
| institucional; sin ninguno, el aviso va solo a la campana. Nunca va al correo personal. Las pruebas mandan el correo
| de verdad (transporte `array`, cola `sync`) y miran a quién llegó.
*/

beforeEach(function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);

    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create(['name' => 'Ana Soto', 'email' => 'ana.soto@gmail.com']);
    $this->actor = User::factory()->withRole(Role::Subdireccion, [])->create();

    app(CreateTermReviews::class)->handle($this->term, [Catalog::Teachers, Catalog::Subjects]);
});

/**
 * Manda el aviso de apertura o el recordatorio a los jefes.
 */
function noticeHeads(string $kind): void
{
    $kind === 'apertura'
        ? app(NotifyPendingReviews::class)->handle(test()->term)
        : app(RemindPendingReviews::class)->handle(test()->term, test()->actor);
}

/**
 * A quiénes llegó correo en esta prueba.
 *
 * @return list<string>
 */
function mailedTo(): array
{
    return app('mail.manager')->mailer()->getSymfonyTransport()->messages()
        ->flatMap(fn (SentMessage $sent): array => array_map(fn (Address $address): string => $address->getAddress(), $sent->getOriginalMessage()->getTo()))
        ->values()
        ->all();
}

/**
 * La ficha de docente del jefe, con su correo institucional (o sin él).
 */
function headRecord(?string $institutionalEmail): Teacher
{
    return Teacher::factory()->create(['user_id' => test()->jefe->id, 'institutional_email' => $institutionalEmail]);
}

it('CV11: el aviso va al correo institucional de la ficha del jefe, no al personal de su usuario', function (string $kind) {
    headRecord('ana.soto@cdjuarez.tecnm.mx');

    noticeHeads($kind);

    expect(mailedTo())->toBe(['ana.soto@cdjuarez.tecnm.mx'])
        ->and($this->jefe->notifications()->count())->toBe(1);
})->with(['apertura', 'recordatorio']);

it('CV11: la ficha gana aunque su usuario también tenga un correo institucional', function (string $kind) {
    $this->jefe->update(['email' => 'asoto@cdjuarez.tecnm.mx']);
    headRecord('ana.soto@cdjuarez.tecnm.mx');

    noticeHeads($kind);

    expect(mailedTo())->toBe(['ana.soto@cdjuarez.tecnm.mx']);
})->with(['apertura', 'recordatorio']);

it('CV11: si la ficha no tiene correo institucional, va al institucional de su usuario', function (string $kind, bool $hasRecord) {
    $this->jefe->update(['email' => 'ana.soto@cdjuarez.tecnm.mx']);

    if ($hasRecord) {
        headRecord(null);
    }

    noticeHeads($kind);

    expect(mailedTo())->toBe(['ana.soto@cdjuarez.tecnm.mx']);
})->with(['apertura', 'recordatorio'])->with(['con ficha sin correo' => true, 'sin ficha' => false]);

it('CV11: con solo un correo personal no le llega ningún correo, solo la campana', function (string $kind, bool $hasRecord) {
    if ($hasRecord) {
        headRecord(null);
    }

    noticeHeads($kind);

    expect(mailedTo())->toBe([])
        ->and($this->jefe->notifications()->count())->toBe(1);
})->with(['apertura', 'recordatorio'])->with(['con ficha sin correo' => true, 'sin ficha' => false]);

it('CV11: sin ficha ni correo, el aviso va solo a la campana', function (string $kind) {
    $this->jefe->update(['email' => null]);
    Notification::fake();

    noticeHeads($kind);

    Notification::assertSentTo($this->jefe, CatalogReviewsPending::class, fn (CatalogReviewsPending $notice, array $channels) => $channels === ['database']
        && $this->jefe->routeNotificationFor('mail', $notice) === null);
})->with(['apertura', 'recordatorio']);

it('CV11: el canal de correo se decide con la misma dirección que recibe el correo', function () {
    headRecord('ana.soto@cdjuarez.tecnm.mx');
    Notification::fake();

    noticeHeads('apertura');

    Notification::assertSentTo($this->jefe, CatalogReviewsPending::class, fn (CatalogReviewsPending $notice, array $channels) => $channels === ['database', 'mail']
        && $this->jefe->routeNotificationFor('mail', $notice) === 'ana.soto@cdjuarez.tecnm.mx');
});

it('CV11: una ficha borrada o con un correo que no es del dominio institucional no cuenta (supuesto por confirmar)', function (string $case) {
    $this->jefe->update(['email' => 'ana.soto@cdjuarez.tecnm.mx']);
    $record = headRecord($case === 'borrada' ? 'asoto@cdjuarez.tecnm.mx' : 'asoto@itcj.edu.mx');

    if ($case === 'borrada') {
        $record->delete();
    }

    noticeHeads('apertura');

    expect(mailedTo())->toBe(['ana.soto@cdjuarez.tecnm.mx']);
})->with(['borrada', 'de otro dominio']);
