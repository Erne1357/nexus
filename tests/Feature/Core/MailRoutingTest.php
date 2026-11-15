<?php

use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PasswordResetLink;
use App\Modules\Core\Notifications\RoutesMailAddress;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;

/*
| A qué correo le llega un aviso a un usuario (CV11, tarea 1.21): una notificación que implementa RoutesMailAddress
| elige la dirección, y null es que ese usuario no recibe el correo; las demás siguen yendo a `users.email`, como
| PasswordResetLink. La invitación de acceso no pasa por el usuario: va al correo que se le da (AC-07).
*/

/**
 * Un correo de prueba que va a `$address`, la dirección que elige.
 */
function mailRoutedTo(?string $address): Notification
{
    return new class($address) extends Notification implements RoutesMailAddress
    {
        public function __construct(private readonly ?string $address) {}

        /**
         * @return list<string>
         */
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('Aviso')->line('Texto del aviso.');
        }

        public function mailAddressFor(User $user): ?string
        {
            return $this->address;
        }
    };
}

/**
 * A quiénes llegó correo en esta prueba (transporte `array`).
 *
 * @return list<string>
 */
function routedRecipients(): array
{
    return app('mail.manager')->mailer()->getSymfonyTransport()->messages()
        ->flatMap(fn (SentMessage $sent): array => array_map(fn (Address $address): string => $address->getAddress(), $sent->getOriginalMessage()->getTo()))
        ->values()
        ->all();
}

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ana.perez@gmail.com']);
});

it('CV11: un aviso que elige su dirección llega a esa y no a la del usuario', function () {
    $this->user->notify(mailRoutedTo('ana.perez@cdjuarez.tecnm.mx'));

    expect($this->user->routeNotificationFor('mail', mailRoutedTo('ana.perez@cdjuarez.tecnm.mx')))->toBe('ana.perez@cdjuarez.tecnm.mx')
        ->and(routedRecipients())->toBe(['ana.perez@cdjuarez.tecnm.mx']);
});

it('CV11: si el aviso no elige ninguna dirección, no sale correo', function () {
    $this->user->notify(mailRoutedTo(null));

    expect(routedRecipients())->toBe([]);
});

it('los demás correos siguen yendo al correo del usuario: el restablecimiento (AC-16)', function () {
    $reset = new PasswordResetLink($this->user->username, $this->user->name, 'https://nexus.test/reset', 60);

    expect($this->user->routeNotificationFor('mail', $reset))->toBe('ana.perez@gmail.com')
        ->and($this->user->routeNotificationFor('mail'))->toBe('ana.perez@gmail.com');
});

it('la invitación de acceso (AC-07) sigue yendo al correo que se le da', function () {
    $invited = User::factory()->create(['email' => null, 'password' => null]);

    app(SendAccessInvitation::class)->handle($invited, 'luis.diaz@cdjuarez.tecnm.mx');

    expect(routedRecipients())->toBe(['luis.diaz@cdjuarez.tecnm.mx']);
});
