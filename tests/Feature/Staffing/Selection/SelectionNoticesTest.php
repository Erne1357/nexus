<?php

use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Notifications\SelectionCancelled;
use App\Modules\Staffing\Notifications\SelectionExtraRoundScheduled;
use App\Modules\Staffing\Notifications\SelectionFinishedWithPending;
use App\Modules\Staffing\Notifications\SelectionNotice;
use App\Modules\Staffing\Notifications\SelectionTurnPublished;
use App\Modules\Staffing\Notifications\SelectionTurnStarting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\SentMessage;

/*
| Avisos de la orden de selección (diseño 6.9, SEL-09): van por la campana y por el correo institucional, por la cola y
| solo después del commit, así que una transacción revertida no deja ni campana ni correo. Nombres ficticios.
*/

beforeEach(function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);
    $this->user = User::factory()->create(['name' => 'Lucía Prueba Ficticia', 'email' => 'lucia.prueba@cdjuarez.tecnm.mx']);
    $this->at = CarbonImmutable::parse('2027-01-04 09:00', 'America/Ciudad_Juarez');
});

/**
 * Cada aviso de la selección con lo que debe decir su correo.
 *
 * @return array<string, array{0: Closure(): SelectionNotice, 1: array<string, string>, 2: list<string>}>
 */
function selectionNotices(): array
{
    $at = CarbonImmutable::parse('2027-01-04 09:00', 'America/Ciudad_Juarez');

    return [
        'su turno al publicar' => [fn () => new SelectionTurnPublished('term', 'modality', 'Sistemas y Computación', $at, $at->addMinutes(30)), [], ['lunes 4 de enero', '9:00', '9:30', '«Oferta»']],
        '10 minutos antes' => [fn () => new SelectionTurnStarting('term', 'modality', $at, false, $at->addMinutes(30)), [], ['10 minutos', '9:00']],
        'al empezar' => [fn () => new SelectionTurnStarting('term', 'modality', $at, true, $at->addMinutes(30)), [], ['Empezó tu turno', '9:30']],
        'la ronda extra' => [fn () => new SelectionExtraRoundScheduled('term', 'modality', $at), [], ['ronda extra', 'lunes 4 de enero', '9:00']],
        'los pendientes al jefe' => [fn () => new SelectionFinishedWithPending('term', 'modality', 'Sistemas y Computación', 'selection-id', ['Docente Uno Ficticio', 'Docente Dos Ficticio']), ['seleccion' => 'selection-id'], ['Docente Uno Ficticio', 'Docente Dos Ficticio', 'a mano']],
        'la cancelación' => [fn () => new SelectionCancelled('term', 'modality', 'Sistemas y Computación', $at), [], ['canceló', 'Sistemas y Computación']],
    ];
}

it('§6.9: cada aviso de la selección va por la campana y el correo, por la cola y después del commit', function (Closure $make, array $parameters, array $lines) {
    $notice = $make();
    $mail = $notice->toMail($this->user);
    $text = implode(' ', [$mail->subject, ...$mail->introLines, ...$mail->outroLines]);

    expect($notice)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($notice->via($this->user))->toBe(['database', 'mail'])
        ->and($notice->viaConnections())->toBe(['database' => 'sync'])
        ->and($notice->mailAddressFor($this->user))->toBe('lucia.prueba@cdjuarez.tecnm.mx')
        ->and($mail->greeting)->toBe('Hola, Lucía Prueba Ficticia')
        ->and($mail->actionUrl)->toBe(route('staffing.offer', $parameters))
        ->and($notice->message($this->user)->route)->toBe('staffing.offer')
        ->and(AcademicLoadVisibility::isLoadRoute($notice->message($this->user)->route))->toBeFalse();

    foreach ($lines as $line) {
        expect($text)->toContain($line);
    }
})->with(selectionNotices());

it('§6.9: sin correo institucional el aviso va solo a la campana', function () {
    $personal = User::factory()->create(['email' => 'lucia@gmail.com']);

    expect((new SelectionExtraRoundScheduled('term', 'modality', $this->at))->via($personal))->toBe(['database']);
});

it('§6.9: con la transacción confirmada sale la campana y el correo; revertida, ninguno de los dos', function () {
    $sent = fn (): int => app('mail.manager')->mailer()->getSymfonyTransport()->messages()
        ->filter(fn (SentMessage $message): bool => $message->getOriginalMessage()->getTo()[0]->getAddress() === 'lucia.prueba@cdjuarez.tecnm.mx')
        ->count();

    try {
        DB::transaction(function () {
            $this->user->notify(new SelectionTurnStarting('term', 'modality', $this->at, false, $this->at->addMinutes(30)));

            throw new RuntimeException('Se revierte el reloj.');
        });
    } catch (RuntimeException) {
    }

    expect(DatabaseNotification::query()->where('notifiable_id', $this->user->id)->count())->toBe(0)
        ->and($sent())->toBe(0);

    DB::transaction(fn () => $this->user->notify(new SelectionTurnStarting('term', 'modality', $this->at, false, $this->at->addMinutes(30))));

    expect(DatabaseNotification::query()->where('notifiable_id', $this->user->id)->count())->toBe(1)
        ->and($sent())->toBe(1);
});
