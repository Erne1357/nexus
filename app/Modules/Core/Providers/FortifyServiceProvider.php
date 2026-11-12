<?php

namespace App\Modules\Core\Providers;

use App\Modules\Core\Actions\RecordLogin;
use App\Modules\Core\Actions\RequirePasswordChange;
use App\Modules\Core\Actions\UpdateUserPassword;
use App\Modules\Core\Actions\UpgradePasswordHash;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Http\Responses\PasswordUpdateResponse;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\LoginScreenQuery;
use App\Modules\Core\Support\DefaultPassword;
use App\Modules\Core\Support\LoginThrottle;
use App\Modules\Core\Support\SiiNip;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
use Laravel\Fortify\Fortify;

/**
 * Login de Core con Fortify (ADR-0004): por username, sin registro público, con límite de
 * intentos y cambio obligatorio de contraseña en el primer acceso (supuesto 2 de PROJECT.md).
 * Las features y rutas activas están en config/fortify.php; el middleware que obliga a cambiar
 * la contraseña se agrega al grupo `web` en bootstrap/app.php.
 *
 * Todo lo que se registra aquí es configuración fija del arranque: no guarda estado por
 * petición (Octane).
 */
final class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Intentos de login permitidos por minuto para la misma combinación de username e IP.
     */
    public const int LOGIN_ATTEMPTS_PER_MINUTE = 5;

    /**
     * Microsegundos mínimos de un intento fallido, para que la respuesta no delate si el
     * username existe o está inactivo (lo mismo que hace SessionGuard::attempt()). El piso real es el mayor entre
     * este y 1.5 veces lo que tardan las dos comparaciones de un fallo en este equipo (failureFloor()).
     */
    private const int FAILED_LOGIN_MICROSECONDS = 200_000;

    /**
     * Hashes señuelo por costo de bcrypt: el normal y el del NIP del SII (AC-14). Se calculan una vez por proceso con el
     * hasher configurado y no cambian después; no dependen de ninguna petición (Octane).
     *
     * @var array<int, string>
     */
    private array $decoyHashes = [];

    /** Piso del Timebox de un intento fallido, medido una vez por proceso (failureFloor()). */
    private ?int $failureFloor = null;

    public function register(): void
    {
        $this->app->singleton(PasswordUpdateResponseContract::class, PasswordUpdateResponse::class);
    }

    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(8)->letters()->numbers());

        // La pantalla de acceso G (tarea 1.17): avisos del carrusel (LG-01) y atajos (LG-02).
        Fortify::loginView(function () {
            $screen = app(LoginScreenQuery::class);

            return view('core::auth.login', ['notices' => $screen->notices(), 'shortcuts' => $screen->shortcuts()]);
        });
        Fortify::confirmPasswordView(fn () => view('core::auth.confirm-password'));
        Fortify::authenticateUsing($this->authenticate(...));
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        // Tres límites por petición (tarea 4.8 y AC-14): cinco intentos por minuto por usuario e IP, contra adivinar una
        // contraseña; un tope por IP sola, contra probar una contraseña con muchos usuarios, y otro por red (/24 en IPv4,
        // /64 en IPv6), contra repartir los intentos entre las IP de una misma red. Los topes son holgados porque una red
        // del Tec sale a internet por una sola IP (supuestos por confirmar: `fortify.ip_attempts_per_minute`, 60, y
        // `fortify.network_attempts_per_minute`, 120). Los fallos por usuario sin importar la IP los frena LoginThrottle.
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(self::LOGIN_ATTEMPTS_PER_MINUTE)
                ->by(Str::transliterate(Str::lower($request->string(Fortify::username())->value()).'|'.$request->ip()))
                ->response($this->lockedOut(...)),
            Limit::perMinute((int) config('fortify.ip_attempts_per_minute', 60))
                ->by('ip|'.$request->ip())
                ->response($this->lockedOut(...)),
            Limit::perMinute((int) config('fortify.network_attempts_per_minute', 120))
                ->by('net|'.self::network((string) $request->ip()))
                ->response($this->lockedOut(...)),
        ]);

        Event::listen(function (Login $event): void {
            if ($event->user instanceof User) {
                app(RecordLogin::class)->handle($event->user);
            }
        });
    }

    /**
     * Usuario activo cuyo username y contraseña coinciden, o null. Un usuario inactivo o sin
     * contraseña (sin acceso, AC-01) recibe el mismo mensaje genérico (`auth.failed`) que una
     * credencial incorrecta.
     *
     * Para que el tiempo no delate si el usuario existe, está inactivo, no tiene acceso o sigue con el NIP del SII (AC-14),
     * todo intento fallido hace exactamente una comparación de cada costo de bcrypt (el normal y el del NIP): la real y
     * un señuelo del otro costo, o dos señuelos si no hay contraseña que comparar; y el Timebox lo lleva a un piso de 1.5
     * veces lo que tardan esas dos comparaciones en este equipo (al menos 200 ms).
     *
     * Antes de comparar, LoginThrottle frena: si el username (exista o no) acumula fallos, responde «Demasiados intentos»
     * con los segundos que faltan; si los inicios con el NIP del SII están en pausa, la cuenta con NIP falla como una
     * contraseña incorrecta. Un fallo suma a la cuenta del username y, si es de una cuenta con NIP, al tope global; entrar
     * bien la borra.
     *
     * Al alumno que sigue con el NIP del SII se le completa con ceros a 4 dígitos una entrada de 1 a 3 (AC-11,
     * SiiNip::forUser()); a nadie más. Si la contraseña coincide y su hash se guardó con un costo menor (el del NIP,
     * AC-14), se rehace con el normal (UpgradePasswordHash).
     *
     * @throws ValidationException si el username tiene que esperar
     */
    private function authenticate(Request $request): ?User
    {
        $floor = $this->failureFloor();

        return (new Timebox)->call(function (Timebox $timebox) use ($request): ?User {
            $throttle = app(LoginThrottle::class);
            $username = $request->string(Fortify::username())->value();
            $wait = $throttle->waitFor($username);

            if ($wait > 0) {
                throw ValidationException::withMessages([Fortify::username() => __('auth.throttle', ['seconds' => $wait])]);
            }

            $user = User::query()->where('username', $username)->first();
            $typed = $request->string('password')->value();
            $password = $user === null ? $typed : SiiNip::forUser($user, $typed);
            $usesNip = $user?->password_origin === PasswordOrigin::SiiNip;
            $paused = $usesNip && $throttle->nipPaused();
            $compared = [];
            $matches = false;

            if ($user?->password !== null && ! $paused) {
                $matches = Hash::check($password, $user->password);
                $compared[] = $this->costOf($user->password);
            }

            if (! $matches || ! $user->is_active) {
                foreach ([SiiNip::rounds(), $this->normalRounds()] as $rounds) {
                    if (! in_array($rounds, $compared, true)) {
                        Hash::check($typed, $this->decoyHash($rounds));
                        $compared[] = $rounds;
                    }
                }

                if (! $paused) {
                    $throttle->failed($username);
                }

                if ($usesNip && ! $paused) {
                    $throttle->nipFailed();
                }

                return null;
            }

            $throttle->succeeded($username);

            if (app(DefaultPassword::class)->matches($typed)) {
                app(RequirePasswordChange::class)->handle($user);
            }

            app(UpgradePasswordHash::class)->handle($user, $password);

            $timebox->returnEarly();

            return $user;
        }, $floor);
    }

    /**
     * El hash señuelo del costo `$rounds`, de una contraseña al azar.
     */
    private function decoyHash(int $rounds): string
    {
        return $this->decoyHashes[$rounds] ??= Hash::make(Str::random(40), ['rounds' => $rounds]);
    }

    /**
     * El piso del Timebox de un fallo: 1.5 veces lo que tardan en este equipo una comparación de cada costo (las dos de
     * un fallo), o FAILED_LOGIN_MICROSECONDS si es mayor. Se mide una vez por proceso, con password_verify() directo: es
     * lo mismo que hace el hasher de bcrypt, y así la medición no cuenta como una comparación del login.
     */
    private function failureFloor(): int
    {
        if ($this->failureFloor !== null) {
            return $this->failureFloor;
        }

        $costs = array_unique([SiiNip::rounds(), $this->normalRounds()]);
        $hashes = array_map($this->decoyHash(...), $costs);
        $start = hrtime(true);

        foreach ($hashes as $hash) {
            password_verify(Str::random(8), $hash);
        }

        $measured = (int) ((hrtime(true) - $start) / 1_000);

        return $this->failureFloor = max(self::FAILED_LOGIN_MICROSECONDS, (int) ceil($measured * 1.5));
    }

    private function normalRounds(): int
    {
        return (int) config('hashing.bcrypt.rounds', 12);
    }

    private function costOf(string $hash): ?int
    {
        $cost = password_get_info($hash)['options']['cost'] ?? null;

        return is_int($cost) ? $cost : null;
    }

    /**
     * La red de una IP para el límite por red: `/24` en IPv4 y `/64` en IPv6; la IP tal cual si no es válida.
     */
    private static function network(string $ip): string
    {
        $packed = @inet_pton($ip);

        return match (true) {
            $packed === false => $ip,
            strlen($packed) === 4 => implode('.', array_slice(explode('.', $ip), 0, 3)).'.0/24',
            default => bin2hex(substr($packed, 0, 8)).'/64',
        };
    }

    /**
     * Respuesta al exceder el límite: de vuelta al formulario con el aviso en español, en lugar
     * de la página de error 429.
     *
     * @param  array<string, int|string>  $headers
     */
    private function lockedOut(Request $request, array $headers): RedirectResponse
    {
        return back()
            ->withInput($request->only(Fortify::username(), 'remember'))
            ->withErrors([Fortify::username() => __('auth.throttle', ['seconds' => $headers['Retry-After'] ?? 60])]);
    }
}
