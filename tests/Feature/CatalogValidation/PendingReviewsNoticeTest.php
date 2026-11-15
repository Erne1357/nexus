<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Actions\NotifyPendingReviews;
use App\Modules\CatalogValidation\Actions\RemindPendingReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\CatalogValidation\Queries\ValidationWindowQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\TermCalendarQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
| CV11 (D3 y D6, confirmados el 14 de noviembre de 2026): la apertura y el recordatorio de la validación llevan la fecha límite
| (el fin de la ventana general de la etapa «Validación de catálogos» del periodo) y llegan también al correo del jefe;
| el recordatorio tiene su propio título y cuerpo, con lo que le falta. Sin correo institucional el aviso va solo a la
| campana; a qué correo va (el de la ficha del jefe o el de su usuario) lo prueba PendingReviewsMailAddressTest (CV11,
| confirmado el 15 de noviembre de 2026). El aviso ya no lleva campus: la validación es de todo el Tec.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'America/Ciudad_Juarez'));
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);

    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create(['name' => 'Ana Soto', 'email' => 'ana.soto@cdjuarez.tecnm.mx']);
    $this->actor = User::factory()->withRole(Role::Subdireccion, [])->create();

    app(CreateTermReviews::class)->handle($this->term, [Catalog::Teachers, Catalog::Subjects]);
});

function noticeWindow(string $startsOn, string $endsOn, ?string $modalityId = null): void
{
    TermStage::factory()->create(['term_id' => test()->term->id, 'stage' => Stage::CatalogValidation, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'modality_id' => $modalityId]);
    Cache::forget(TermCalendarQuery::key(test()->term->id));
}

describe('CV11: la fecha límite (D6)', function () {
    it('es el fin de la ventana general de la etapa, no la de una modalidad', function () {
        expect(app(ValidationWindowQuery::class)->deadline($this->term))->toBeNull();

        noticeWindow('2026-11-16', '2026-11-27');
        noticeWindow('2026-11-16', '2026-12-04', modalityOf('PRES')->id);

        expect(app(ValidationWindowQuery::class)->deadline($this->term)?->toDateString())->toBe('2026-11-27');
    });

    it('la apertura la dice en la campana; sin ventana no hay fecha', function () {
        Notification::fake();

        app(NotifyPendingReviews::class)->handle($this->term);
        Notification::assertSentTo($this->jefe, CatalogReviewsPending::class, fn (CatalogReviewsPending $notice) => $notice->message($this->jefe)->body === 'Ene-Jun 2027 · Sistemas y Computación: docentes y materias');

        noticeWindow('2026-11-16', '2026-11-27');
        Notification::fake();

        app(NotifyPendingReviews::class)->handle($this->term);
        Notification::assertSentTo($this->jefe, CatalogReviewsPending::class, function (CatalogReviewsPending $notice) {
            $message = $notice->message($this->jefe);

            return $message->title === 'Tienes catálogos por validar'
                && $message->body === 'Ene-Jun 2027 · Sistemas y Computación: docentes y materias · fecha límite 27 nov'
                && $message->campusId === null
                && $message->parameters === ['department' => $this->sis->id];
        });
    });
});

describe('CV11: el recordatorio (D3)', function () {
    it('tiene su propio título y dice cuánto lleva revisado de cada catálogo que falta', function () {
        noticeWindow('2026-11-16', '2026-11-27');
        $subjects = Subject::factory()->count(3)->create(['department_id' => $this->sis->id]);
        $review = CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Subjects)->sole();
        CatalogReviewMark::factory()->create(['catalog_review_id' => $review->id, 'record_id' => $subjects[0]->id, 'reviewed_by' => $this->jefe->id]);
        Notification::fake();

        app(RemindPendingReviews::class)->handle($this->term, $this->actor);

        Notification::assertSentTo($this->jefe, CatalogReviewsPending::class, function (CatalogReviewsPending $notice) {
            $message = $notice->message($this->jefe);

            return $message->title === 'Recordatorio: te faltan catálogos por validar'
                && $message->body === 'Ene-Jun 2027 · Sistemas y Computación: docentes (sin registros) y materias (1 de 3 revisados) · fecha límite 27 nov';
        });
    });

    it('no cuenta lo ya validado', function () {
        CatalogReview::query()->where('catalog', Catalog::Teachers)->update(['status' => ReviewStatus::Validated, 'validated_at' => now()]);
        Notification::fake();

        app(RemindPendingReviews::class)->handle($this->term, $this->actor);

        Notification::assertSentTo($this->jefe, CatalogReviewsPending::class, fn (CatalogReviewsPending $notice) => $notice->message($this->jefe)->body === 'Ene-Jun 2027 · Sistemas y Computación: materias (sin registros)');
    });
});

describe('CV11: por correo (D3)', function () {
    it('la apertura y el recordatorio van a la campana y al correo institucional del jefe', function (string $kind) {
        noticeWindow('2026-11-16', '2026-11-27');
        Notification::fake();

        $kind === 'apertura' ? app(NotifyPendingReviews::class)->handle($this->term) : app(RemindPendingReviews::class)->handle($this->term, $this->actor);

        Notification::assertSentTo($this->jefe, CatalogReviewsPending::class, fn (CatalogReviewsPending $notice, array $channels) => $channels === ['database', 'mail']);
    })->with(['apertura', 'recordatorio']);

    it('el correo dice lo que falta, la fecha límite y lleva a validar', function () {
        noticeWindow('2026-11-16', '2026-11-27');
        Notification::fake();

        app(RemindPendingReviews::class)->handle($this->term, $this->actor);

        Notification::assertSentTo($this->jefe, CatalogReviewsPending::class, function (CatalogReviewsPending $notice) {
            $mail = $notice->toMail($this->jefe);
            $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

            return $mail->subject === 'Recordatorio: te faltan catálogos por validar'
                && $mail->greeting === 'Hola, Ana Soto'
                && str_contains($text, 'En Sistemas y Computación te falta validar en Ene-Jun 2027:')
                && str_contains($text, 'Materias: sin registros')
                && str_contains($text, 'La fecha límite es el 27 de noviembre.')
                && $mail->actionText === 'Ir a validar'
                && $mail->actionUrl === route('catalog-validation.review', ['department' => $this->sis->id]);
        });
    });

    it('CV11: sin correo institucional el aviso va solo a la campana', function (?string $email) {
        $this->jefe->update(['email' => $email]);
        Notification::fake();

        app(NotifyPendingReviews::class)->handle($this->term);

        Notification::assertSentTo($this->jefe, CatalogReviewsPending::class, fn (CatalogReviewsPending $notice, array $channels) => $channels === ['database']);
    })->with(['sin correo' => null, 'de otro dominio' => 'ana@itcj.edu.mx']);

    it('el correo sale de la cola después del commit y la campana en el momento', function () {
        Queue::fake();

        app(NotifyPendingReviews::class)->handle($this->term);

        expect(new CatalogReviewsPending($this->term, $this->sis, [Catalog::Teachers]))->toBeInstanceOf(ShouldQueue::class);
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->channels === ['mail'] && $job->connection === null && $job->afterCommit === true);
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->channels === ['database'] && $job->connection === 'sync');
    });
});

it('CV-S15: «Ir a validar» del correo abre la revisión del departamento sin periodo, en el activo de quien entra; la campana lleva el periodo del aviso', function () {
    Notification::fake();

    app(NotifyPendingReviews::class)->handle($this->term);

    Notification::assertSentTo($this->jefe, CatalogReviewsPending::class, function (CatalogReviewsPending $notice) {
        $mail = $notice->toMail($this->jefe);
        $bell = $notice->message($this->jefe);

        return $mail->actionUrl === route('catalog-validation.review', ['department' => $this->sis->id])
            && $bell->parameters === ['department' => $this->sis->id]
            && $bell->termId === $this->term->id;
    });
});
