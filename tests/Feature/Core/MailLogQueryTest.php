<?php

use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\MailLogQuery;
use Carbon\CarbonImmutable;

/*
| MailLogQuery (CF-S8): el registro de correos, del más reciente al más viejo, con búsqueda en el destinatario o el
| asunto y filtros de tipo, estado y fechas; paginado.
*/

beforeEach(function () {
    $this->a = MailLog::factory()->create(['to' => 'ana@itcj.test', 'subject' => 'Tu acceso a Nexus', 'kind' => 'AccessInvitation', 'sent_at' => '2026-11-10 09:00:00']);
    $this->b = MailLog::factory()->failed()->create(['to' => 'beto@itcj.test', 'subject' => 'Restablece tu contraseña', 'kind' => 'PasswordResetLink', 'sent_at' => '2026-11-12 09:00:00']);
    $this->c = MailLog::factory()->create(['to' => 'carla@itcj.test', 'subject' => 'Aviso', 'kind' => 'NexusNotification', 'sent_at' => '2026-11-14 09:00:00']);
});

it('lista del más reciente al más viejo', function () {
    expect(app(MailLogQuery::class)->forTable()->pluck('id')->all())->toBe([$this->c->id, $this->b->id, $this->a->id]);
});

it('busca por destinatario o por asunto, sin distinguir mayúsculas', function () {
    $query = app(MailLogQuery::class);

    expect($query->forTable('BETO')->pluck('id')->all())->toBe([$this->b->id])
        ->and($query->forTable('acceso')->pluck('id')->all())->toBe([$this->a->id]);
});

it('filtra por tipo', function () {
    expect(app(MailLogQuery::class)->forTable(kind: 'NexusNotification')->pluck('id')->all())->toBe([$this->c->id]);
});

it('filtra por estado', function () {
    expect(app(MailLogQuery::class)->forTable(status: MailStatus::Failed)->pluck('id')->all())->toBe([$this->b->id]);
});

it('filtra por fechas, con el día final completo', function () {
    $query = app(MailLogQuery::class);

    expect($query->forTable(from: CarbonImmutable::parse('2026-11-11'))->pluck('id')->all())->toBe([$this->c->id, $this->b->id])
        ->and($query->forTable(to: CarbonImmutable::parse('2026-11-12'))->pluck('id')->all())->toBe([$this->b->id, $this->a->id])
        ->and($query->forTable(from: CarbonImmutable::parse('2026-11-12'), to: CarbonImmutable::parse('2026-11-12'))->pluck('id')->all())->toBe([$this->b->id]);
});

it('pagina', function () {
    $page = app(MailLogQuery::class)->forTable(perPage: 2);

    expect($page->total())->toBe(3)->and($page->count())->toBe(2)->and($page->hasPages())->toBeTrue();
});

it('da los tipos que hay, sin repetir', function () {
    MailLog::factory()->create(['kind' => 'AccessInvitation']);

    expect(app(MailLogQuery::class)->kinds())->toBe(['AccessInvitation', 'NexusNotification', 'PasswordResetLink']);
});

it('conserva el usuario del correo si se reconoce', function () {
    $user = User::factory()->create();
    $log = MailLog::factory()->create(['user_id' => $user->id, 'sent_at' => '2026-11-15 09:00:00']);

    expect(app(MailLogQuery::class)->forTable()->first()->user->id)->toBe($user->id)
        ->and($log->user->is($user))->toBeTrue();
});
