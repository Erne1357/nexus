<?php

use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use Illuminate\Support\Facades\Notification;

/*
| Core\Actions\NotifyHolders: manda el aviso a cada destinatario de RecipientsQuery::holders() con su puesto.
*/

final class HoldersNotice extends NexusNotification
{
    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: 'Aviso', body: 'x', icon: 'o-bell', route: 'home');
    }
}

it('avisa a cada destinatario con su puesto y da los usuarios avisados', function () {
    $ana = User::factory()->create(['name' => 'Ana']);
    $beto = User::factory()->create(['name' => 'Beto']);

    $notified = app(NotifyHolders::class)->handle([
        ['user' => $ana, 'role' => Role::JefeDepartamento],
        ['user' => $beto, 'role' => Role::Coordinador],
    ], new HoldersNotice);

    expect($notified->pluck('name')->all())->toBe(['Ana', 'Beto'])
        ->and($ana->notifications()->first()->data['role'])->toBe('jefe_departamento')
        ->and($beto->notifications()->first()->data['role'])->toBe('coordinador');
});

it('sin destinatarios no envía nada', function () {
    Notification::fake();

    expect(app(NotifyHolders::class)->handle([], new HoldersNotice))->toBeEmpty();

    Notification::assertNothingSent();
});
