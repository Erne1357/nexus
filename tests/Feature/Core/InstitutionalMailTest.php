<?php

use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Actions\SendTestMail;
use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Core\Notifications\AllowsPersonalMail;
use App\Modules\Core\Notifications\InstitutionalMail;
use App\Modules\Core\Notifications\PasswordResetLink;
use App\Modules\Core\Notifications\PersonalMailExceptions;
use App\Modules\Core\Notifications\RoutesMailAddress;
use App\Modules\Core\Notifications\UserEmailChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Notifications\PriorChoiceHoursPending;
use App\Modules\Staffing\Notifications\SelectionTeacherNotice;
use App\Modules\Students\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;

/*
| CE-01 (confirmado el 26 de noviembre de 2026): Nexus manda correo solo a direcciones institucionales, salvo las
| excepciones declaradas (SEL-13 y SEL-15). El correo de una persona es su institucional (el de su usuario si lo es, el de
| su ficha de docente, el del alumno por su número de control) y, sin ninguno, no le sale correo. Una guardia revisa cada
| correo al salir: si el destino no es institucional y el aviso no es de una excepción declarada, no sale y queda en el
| registro de correos como bloqueado. Las pruebas mandan el correo de verdad (transporte `array`, cola `sync`).
*/

beforeEach(function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx', 'nexus.institutional_email_domains' => ['cdjuarez.tecnm.mx']]);
});

/**
 * A quiénes llegó correo en esta prueba.
 *
 * @return list<string>
 */
function ce01Recipients(): array
{
    return app('mail.manager')->mailer()->getSymfonyTransport()->messages()
        ->flatMap(fn (SentMessage $sent): array => array_map(fn (Address $address): string => $address->getAddress(), $sent->getOriginalMessage()->getTo()))
        ->values()
        ->all();
}

/**
 * Un aviso de persona sin dirección propia: sigue el correo institucional de la persona.
 */
function ce01PersonNotice(): Notification
{
    return new class extends Notification
    {
        /**
         * @return list<string>
         */
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('Aviso de persona')->line('Texto.');
        }
    };
}

/**
 * Un aviso que elige `$address` y, con `$rule`, dice que lo permite esa regla aunque no esté en la lista de excepciones.
 */
function ce01RoutedNotice(string $address, ?string $rule = null): Notification
{
    return $rule === null
        ? new class($address) extends Notification implements RoutesMailAddress
        {
            public function __construct(private readonly string $address) {}

            /**
             * @return list<string>
             */
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->subject('Aviso elegido')->line('Texto.');
            }

            public function mailAddressFor(User $user): ?string
            {
                return $this->address;
            }
        }
    : new class($address, $rule) extends Notification implements AllowsPersonalMail, RoutesMailAddress
    {
        public function __construct(private readonly string $address, private readonly string $rule) {}

        /**
         * @return list<string>
         */
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('Aviso que se dice exceptuado')->line('Texto.');
        }

        public function mailAddressFor(User $user): ?string
        {
            return $this->address;
        }

        public function personalMailRule(): ?string
        {
            return $this->rule;
        }
    };
}

describe('el dominio institucional', function () {
    it('CE-01: solo cuenta cdjuarez.tecnm.mx por omisión; el dominio anterior itcj.edu.mx no', function () {
        expect(InstitutionalMail::isInstitutional('ana.perez@cdjuarez.tecnm.mx'))->toBeTrue()
            ->and(InstitutionalMail::isInstitutional('Ana.Perez@CDJuarez.TecNM.mx'))->toBeTrue()
            ->and(InstitutionalMail::isInstitutional('ana.perez@itcj.edu.mx'))->toBeFalse()
            ->and(InstitutionalMail::isInstitutional('ana.perez@gmail.com'))->toBeFalse()
            ->and(InstitutionalMail::isInstitutional('ana@cdjuarez.tecnm.mx.evil.test'))->toBeFalse()
            ->and(InstitutionalMail::isInstitutional('ana@sub.cdjuarez.tecnm.mx'))->toBeFalse()
            ->and(InstitutionalMail::isInstitutional('no-es-correo'))->toBeFalse()
            ->and(InstitutionalMail::isInstitutional(null))->toBeFalse();
    });

    it('CE-01: la lista de dominios sale de la configuración, y el dominio con que Nexus arma los correos siempre cuenta', function () {
        config(['nexus.institutional_email_domain' => 'docentes.itcj.example', 'nexus.institutional_email_domains' => [' Otro.TecNM.mx ', '', 'cdjuarez.tecnm.mx']]);

        expect(InstitutionalMail::domains())->toBe(['otro.tecnm.mx', 'cdjuarez.tecnm.mx', 'docentes.itcj.example'])
            ->and(InstitutionalMail::isInstitutional('ana@otro.tecnm.mx'))->toBeTrue()
            ->and(InstitutionalMail::isInstitutional('ana@docentes.itcj.example'))->toBeTrue()
            ->and(InstitutionalMail::isInstitutional('ana@itcj.edu.mx'))->toBeFalse();
    });

    it('CE-01: la variable de entorno es una lista separada por comas, por omisión solo cdjuarez.tecnm.mx', function () {
        $config = fn (?string $value): array => (function () use ($value): array {
            $previous = getenv('NEXUS_INSTITUTIONAL_EMAIL_DOMAINS');
            $value === null ? putenv('NEXUS_INSTITUTIONAL_EMAIL_DOMAINS') : putenv("NEXUS_INSTITUTIONAL_EMAIL_DOMAINS={$value}");
            unset($_ENV['NEXUS_INSTITUTIONAL_EMAIL_DOMAINS'], $_SERVER['NEXUS_INSTITUTIONAL_EMAIL_DOMAINS']);

            try {
                return (require base_path('config/nexus.php'))['institutional_email_domains'];
            } finally {
                $previous === false ? putenv('NEXUS_INSTITUTIONAL_EMAIL_DOMAINS') : putenv("NEXUS_INSTITUTIONAL_EMAIL_DOMAINS={$previous}");
            }
        })();

        expect($config(null))->toBe(['cdjuarez.tecnm.mx'])
            ->and($config('cdjuarez.tecnm.mx, otro.tecnm.mx'))->toBe(['cdjuarez.tecnm.mx', 'otro.tecnm.mx']);
    });
});

describe('el correo de la persona', function () {
    it('CE-01: es el de su usuario si es institucional', function () {
        $user = User::factory()->create(['email' => 'ana.perez@cdjuarez.tecnm.mx']);

        expect($user->routeNotificationFor('mail', ce01PersonNotice()))->toBe('ana.perez@cdjuarez.tecnm.mx');
    });

    it('CE-01: con un correo personal en su usuario, es el institucional de su ficha de docente', function () {
        $user = User::factory()->create(['email' => 'ana.perez@gmail.com']);
        Teacher::factory()->create(['user_id' => $user->id, 'email' => 'ana.perez@gmail.com', 'institutional_email' => 'ana.perez@cdjuarez.tecnm.mx']);

        expect($user->routeNotificationFor('mail', ce01PersonNotice()))->toBe('ana.perez@cdjuarez.tecnm.mx');
    });

    it('CE-01: el alumno sin correo institucional en su usuario recibe en el que sale de su número de control (AC-13)', function () {
        $user = User::factory()->withRole(Role::Alumno)->create(['email' => 'luis@hotmail.com']);
        Student::factory()->create(['user_id' => $user->id, 'control_number' => '21110001']);

        expect($user->routeNotificationFor('mail', ce01PersonNotice()))->toBe('l21110001@cdjuarez.tecnm.mx');
    });

    it('CE-01: sin ningún correo institucional no le sale correo', function () {
        $user = User::factory()->create(['email' => 'ana.perez@gmail.com']);
        Teacher::factory()->create(['user_id' => $user->id, 'email' => 'ana.perez@gmail.com', 'institutional_email' => null]);

        $user->notify(ce01PersonNotice());

        expect($user->routeNotificationFor('mail', ce01PersonNotice()))->toBeNull()
            ->and(ce01Recipients())->toBe([])
            ->and(MailLog::query()->count())->toBe(0);
    });

    it('CE-01: el restablecimiento de la contraseña (AC-16) va al institucional, nunca al personal', function () {
        $personal = User::factory()->create(['email' => 'ana.perez@gmail.com']);
        $institutional = User::factory()->create(['email' => 'luis.diaz@cdjuarez.tecnm.mx']);

        $personal->notify(new PasswordResetLink($personal->username, $personal->name, 'https://nexus.test/reset', 60));
        $institutional->notify(new PasswordResetLink($institutional->username, $institutional->name, 'https://nexus.test/reset', 60));

        expect(ce01Recipients())->toBe(['luis.diaz@cdjuarez.tecnm.mx']);
    });
});

describe('la guardia del correo', function () {
    it('CE-01: un aviso que elige un correo personal no sale y queda bloqueado en el registro con el motivo', function () {
        $user = User::factory()->create(['email' => 'ana.perez@cdjuarez.tecnm.mx']);

        $user->notify(ce01RoutedNotice('ana.perez@gmail.com'));

        $log = MailLog::query()->sole();

        expect(ce01Recipients())->toBe([])
            ->and($log->status)->toBe(MailStatus::Failed)
            ->and($log->is_blocked)->toBeTrue()
            ->and($log->to)->toBe('ana.perez@gmail.com')
            ->and($log->subject)->toBe('Aviso elegido')
            ->and($log->error)->toStartWith('Bloqueado (CE-01): ')->toContain('no es institucional');
    });

    it('CE-01: un aviso a una dirección suelta que no es institucional no sale', function () {
        NotificationFacade::route('mail', 'alguien@gmail.com')->notify(ce01PersonNotice());

        expect(ce01Recipients())->toBe([])
            ->and(MailLog::query()->sole())->status->toBe(MailStatus::Failed)->to->toBe('alguien@gmail.com');
    });

    it('CE-01: a una dirección institucional sale como siempre y queda enviado', function () {
        NotificationFacade::route('mail', 'alguien@cdjuarez.tecnm.mx')->notify(ce01PersonNotice());

        expect(ce01Recipients())->toBe(['alguien@cdjuarez.tecnm.mx'])
            ->and(MailLog::query()->sole()->status)->toBe(MailStatus::Sent);
    });

    it('CE-01: una ruta con varias direcciones se bloquea completa si una no es institucional', function () {
        NotificationFacade::route('mail', ['alguien@cdjuarez.tecnm.mx' => 'Alguien', 'otro@gmail.com' => 'Otro'])->notify(ce01PersonNotice());

        expect(ce01Recipients())->toBe([])
            ->and(MailLog::query()->sole())->status->toBe(MailStatus::Failed)->to->toBe('alguien@cdjuarez.tecnm.mx, otro@gmail.com');
    });

    it('CE-01: un aviso que dice tener una excepción pero no está en la lista declarada no sale', function () {
        $user = User::factory()->create();

        $user->notify(ce01RoutedNotice('ana.perez@gmail.com', 'SEL-15'));

        expect(ce01Recipients())->toBe([])
            ->and(MailLog::query()->sole()->status)->toBe(MailStatus::Failed);
    });

    it('CE-01: el aviso de cambio de correo (CF-S3) sale al correo anterior solo si era institucional', function () {
        $notice = fn (): UserEmailChanged => new UserEmailChanged('ana', 'Ana', 'Admin', CarbonImmutable::now(), 'nueva@cdjuarez.tecnm.mx');

        NotificationFacade::route('mail', 'anterior@gmail.com')->notify($notice());
        NotificationFacade::route('mail', 'anterior@cdjuarez.tecnm.mx')->notify($notice());

        expect(ce01Recipients())->toBe(['anterior@cdjuarez.tecnm.mx'])
            ->and(MailLog::query()->where('error', 'like', 'Bloqueado (CE-01)%')->sole()->to)->toBe('anterior@gmail.com');
    });

    it('CE-01: la invitación de acceso automática a un correo personal no sale', function () {
        $user = User::factory()->create(['email' => null, 'password' => null]);

        app(SendAccessInvitation::class)->handle($user, 'luis@gmail.com');

        expect(ce01Recipients())->toBe([])
            ->and(MailLog::query()->sole())->status->toBe(MailStatus::Failed)->kind->toBe('AccessInvitation');
    });
});

describe('las excepciones declaradas', function () {
    it('CE-01: Core junta las excepciones de los módulos por su clase completa y rechaza una que no implementa la interfaz', function () {
        $provider = new class(app()) extends ModuleServiceProvider
        {
            public function personalMailExceptions(): array
            {
                return [PasswordResetLink::class => 'SEL-13'];
            }
        };

        expect(app(PersonalMailExceptions::class)->declared())->toBe([AccessInvitation::class => 'SEL-13', SelectionTeacherNotice::class => 'SEL-15'])
            ->and(fn () => PersonalMailExceptions::fromModules([$provider]))->toThrow(LogicException::class, 'no es válida (CE-01)');
    });

    it('SEL-13: la invitación de la orden de selección sí sale al correo personal', function () {
        $user = User::factory()->create(['email' => null, 'password' => null]);

        app(SendAccessInvitation::class)->handle($user, 'luis@gmail.com', personalMailRule: 'SEL-13');

        expect(ce01Recipients())->toBe(['luis@gmail.com'])
            ->and(MailLog::query()->sole()->status)->toBe(MailStatus::Sent);
    });

    it('SEL-13: solo la regla declarada para la invitación la exceptúa', function () {
        NotificationFacade::route('mail', 'luis@gmail.com')->notify(new AccessInvitation('luis', 'Luis', 'https://nexus.test/i', CarbonImmutable::now()->addDay(), personalMailRule: 'SEL-15'));

        expect(ce01Recipients())->toBe([]);
    });

    it('SEL-15: los avisos de la orden de selección al docente sí caen a su correo personal', function () {
        $user = User::factory()->create(['email' => null]);
        Teacher::factory()->create(['user_id' => $user->id, 'email' => 'mario.personal@gmail.com', 'institutional_email' => null]);

        $notice = new PriorChoiceHoursPending('term', 'modality', 'Sistemas y Computación', 20, 12);

        $user->notify($notice);

        expect(ce01Recipients())->toBe(['mario.personal@gmail.com'])
            ->and($notice)->toBeInstanceOf(SelectionTeacherNotice::class)
            ->and($notice->personalMailRule())->toBe('SEL-15');
    });
});

it('CF-S9 y CE-01: el correo de prueba va al correo de quien lo pide solo si es institucional; si no, a la cuenta de envío', function () {
    $connection = MailConnection::factory()->create(['account_email' => 'nexus@cdjuarez.tecnm.mx']);
    $personal = User::factory()->admin()->create(['email' => 'admin@gmail.com']);
    $institutional = User::factory()->admin()->create(['email' => 'admin@cdjuarez.tecnm.mx']);
    config(['mail.mailers.microsoft-graph' => ['transport' => 'array']]);

    expect(app(SendTestMail::class)->handle($personal, $connection))->toBe('nexus@cdjuarez.tecnm.mx')
        ->and(app(SendTestMail::class)->handle($institutional, $connection))->toBe('admin@cdjuarez.tecnm.mx');
});

it('CF-S9 y CE-01: si ni el correo de quien lo pide ni la cuenta de envío son institucionales, no lo manda y lo dice', function () {
    $connection = MailConnection::factory()->create(['account_email' => 'pruebas@outlook.com', 'access_token' => 'at-vigente']);
    $admin = User::factory()->admin()->create(['email' => 'admin@gmail.com']);
    config(['mail.mailers.microsoft-graph' => ['transport' => 'array']]);

    expect(fn () => app(SendTestMail::class)->handle($admin, $connection))->toThrow(ValidationException::class, SendTestMail::NO_INSTITUTIONAL);

    $page = Livewire\Livewire::actingAs($admin)->test('core::pages.mail-and-queues')->call('sendTestMail');

    expect(lastToastOf($page)['title'])->toBe('No salió el correo de prueba')
        ->and(lastToastOf($page)['description'])->toContain('institucionales')
        ->and(ce01Recipients())->toBe([])
        ->and(MailLog::query()->count())->toBe(0);
});

it('CE-01: «Correos y colas» muestra como «Bloqueado» el correo fallido que bloqueó la guardia; los demás fallidos siguen igual', function () {
    MailLog::factory()->failed('Bloqueado (CE-01): ana@gmail.com no es institucional y este aviso no tiene una excepción declarada.')->create(['subject' => 'Aviso bloqueado']);
    MailLog::factory()->failed('Timeout')->create(['subject' => 'Aviso que falló']);

    Livewire\Livewire::actingAs(User::factory()->admin()->create())->test('core::pages.mail-and-queues')
        ->assertSeeInOrder(['Aviso bloqueado', 'Bloqueado'])
        ->assertSeeInOrder(['Aviso que falló', 'Fallido'])
        ->set('status', 'failed')
        ->assertSee('Aviso bloqueado');

    expect(MailStatus::cases())->toBe([MailStatus::Sent, MailStatus::Failed]);
});
