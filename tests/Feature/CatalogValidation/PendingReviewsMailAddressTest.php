<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Actions\NotifyPendingReviews;
use App\Modules\CatalogValidation\Actions\RemindPendingReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\Core\Actions\SavePositionMailbox;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;

/*
| CE-02 (confirmado el 26 de noviembre de 2026): el aviso de apertura y el recordatorio de la validación competen a la
| jefatura, así que su correo va al buzón del puesto (`position_mailboxes`) y no al de la persona; sin buzón, la campana
| llega y el correo espera hasta que el admin lo capture. CV11 (confirmado el 15 de noviembre de 2026) queda para el
| aviso que no se manda a un puesto: la ficha de docente del jefe, luego su usuario, nunca el personal. Las pruebas
| mandan el correo de verdad (transporte `array`, cola `sync`) y miran a quién llegó.
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

/**
 * El aviso de la validación sin puesto: sigue la regla de CV11.
 */
function personalReviewNotice(): CatalogReviewsPending
{
    return new CatalogReviewsPending(test()->term, test()->sis, [Catalog::Teachers]);
}

describe('CE-02: el buzón de la jefatura', function () {
    it('con buzón, el aviso va al buzón del puesto y no a la ficha ni al usuario del jefe', function (string $kind) {
        $this->jefe->update(['email' => 'asoto@cdjuarez.tecnm.mx']);
        headRecord('ana.soto@cdjuarez.tecnm.mx');
        PositionMailbox::factory()->create(['org_unit_id' => $this->sis->id, 'email' => 'jefatura_syc@cdjuarez.tecnm.mx']);

        noticeHeads($kind);

        expect(mailedTo())->toBe(['jefatura_syc@cdjuarez.tecnm.mx'])
            ->and($this->jefe->notifications()->count())->toBe(1);
    })->with(['apertura', 'recordatorio']);

    it('sin buzón, la campana llega y el correo espera aunque el jefe tenga correo institucional', function (string $kind) {
        headRecord('ana.soto@cdjuarez.tecnm.mx');

        noticeHeads($kind);

        expect(mailedTo())->toBe([])
            ->and($this->jefe->notifications()->count())->toBe(1)
            ->and(HeldPositionMail::query()->sole())->org_unit_id->toBe($this->sis->id)->kind->toBe('CatalogReviewsPending');
    })->with(['apertura', 'recordatorio']);

    it('al capturar el buzón sale el correo que esperaba, con lo que falta', function () {
        noticeHeads('apertura');

        app(SavePositionMailbox::class)->handle(['role' => 'jefe_departamento', 'org_unit_id' => $this->sis->id, 'email' => 'jefatura_syc@cdjuarez.tecnm.mx']);

        $sent = app('mail.manager')->mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();

        expect(mailedTo())->toBe(['jefatura_syc@cdjuarez.tecnm.mx'])
            ->and($sent->getSubject())->toBe('Tienes catálogos por validar')
            ->and(HeldPositionMail::query()->sole()->released_at)->not->toBeNull();
    });
});

describe('CV11: el aviso que no se manda a un puesto', function () {
    it('va al correo institucional de la ficha del jefe, que gana al de su usuario', function () {
        $this->jefe->update(['email' => 'asoto@cdjuarez.tecnm.mx']);
        headRecord('ana.soto@cdjuarez.tecnm.mx');

        expect($this->jefe->routeNotificationFor('mail', personalReviewNotice()))->toBe('ana.soto@cdjuarez.tecnm.mx')
            ->and(personalReviewNotice()->via($this->jefe))->toBe(['database', 'mail']);
    });

    it('si la ficha no tiene, va al institucional de su usuario; con solo un personal, a ninguno', function () {
        headRecord(null);

        expect($this->jefe->routeNotificationFor('mail', personalReviewNotice()))->toBeNull()
            ->and(personalReviewNotice()->via($this->jefe))->toBe(['database']);

        $this->jefe->update(['email' => 'ana.soto@cdjuarez.tecnm.mx']);

        expect($this->jefe->routeNotificationFor('mail', personalReviewNotice()))->toBe('ana.soto@cdjuarez.tecnm.mx');
    });

    it('una ficha borrada o con un correo que no es del dominio institucional no cuenta (supuesto por confirmar)', function (string $case) {
        $this->jefe->update(['email' => 'ana.soto@cdjuarez.tecnm.mx']);
        $record = headRecord($case === 'borrada' ? 'asoto@cdjuarez.tecnm.mx' : 'asoto@itcj.edu.mx');

        if ($case === 'borrada') {
            $record->delete();
        }

        expect($this->jefe->routeNotificationFor('mail', personalReviewNotice()))->toBe('ana.soto@cdjuarez.tecnm.mx');
    })->with(['borrada', 'de otro dominio']);

    it('con la ficha inactiva, sin borrar, va igual a su correo institucional (confirmado el 15 de noviembre de 2026)', function () {
        Teacher::factory()->create(['user_id' => $this->jefe->id, 'institutional_email' => 'ana.soto@cdjuarez.tecnm.mx', 'is_active' => false]);

        expect($this->jefe->routeNotificationFor('mail', personalReviewNotice()))->toBe('ana.soto@cdjuarez.tecnm.mx');
    });
});
