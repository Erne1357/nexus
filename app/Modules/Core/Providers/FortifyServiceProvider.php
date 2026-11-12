<?php

namespace App\Modules\Core\Providers;

use App\Modules\Core\Actions\RecordLogin;
use App\Modules\Core\Actions\RequirePasswordChange;
use App\Modules\Core\Actions\UpdateUserPassword;
use App\Modules\Core\Actions\UpgradePasswordHash;
use App\Modules\Core\Http\Responses\PasswordUpdateResponse;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\LoginScreenQuery;
use App\Modules\Core\Support\DefaultPassword;
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
     * Enlaces para restablecer la contraseña que se pueden pedir para un mismo usuario, desde cualquier IP, en
     * RESET_LINK_USERNAME_DECAY_MINUTES minutos (AC-17, supuesto por confirmar).
     */
    public const int RESET_LINK_REQUESTS_PER_USERNAME = 3;

    public const int RESET_LINK_USERNAME_DECAY_MINUTES = 15;

    /**
     * Microsegundos mínimos de un intento fallido, para que la respuesta no delate si el
     * username existe o está inactivo (lo mismo que hace SessionGuard::attempt()).
     */
    private const int FAILED_LOGIN_MICROSECONDS = 200_000;

    /**
     * Hash señuelo contra el que se compara la contraseña cuando el username no existe. Se
     * calcula una vez por proceso con el hasher configurado (mismo algoritmo y costo que los
     * reales) y no cambia después; no depende de ninguna petición.
     */
    private ?string $decoyPasswordHash = null;

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

        // Dos límites (tarea 4.8): cinco intentos por minuto por usuario e IP, contra adivinar una contraseña, y un tope
        // por IP sola, contra probar una contraseña con muchos usuarios. El tope es holgado porque una red del Tec sale a
        // internet por una sola IP (supuesto por confirmar: `fortify.ip_attempts_per_minute`, 60 por omisión).
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(self::LOGIN_ATTEMPTS_PER_MINUTE)
                ->by(Str::transliterate(Str::lower($request->string(Fortify::username())->value()).'|'.$request->ip()))
                ->response($this->lockedOut(...)),
            Limit::perMinute((int) config('fortify.ip_attempts_per_minute', 60))
                ->by('ip|'.$request->ip())
                ->response($this->lockedOut(...)),
        ]);

        RateLimiter::for('password-reset-link', fn (Request $request): array => [
            Limit::perMinutes(self::RESET_LINK_USERNAME_DECAY_MINUTES, self::RESET_LINK_REQUESTS_PER_USERNAME)
                ->by('username|'.Str::transliterate(Str::lower(is_string($request->input('username')) ? $request->input('username') : '')))
                ->response($this->resetLinkLockedOut(...)),
            Limit::perMinute((int) config('fortify.password_reset_ip_requests_per_minute', 10))
                ->by('ip|'.$request->ip())
                ->response($this->resetLinkLockedOut(...)),
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
     * credencial incorrecta. La contraseña se compara siempre, contra un hash señuelo si el username
     * no existe o no tiene contraseña, para que el tiempo de respuesta no delate si el usuario existe,
     * está inactivo o no tiene acceso; el Timebox iguala el resto.
     *
     * Al alumno que sigue con el NIP del SII se le completa con ceros a 4 dígitos una entrada de 1 a 3 (AC-11,
     * SiiNip::forUser()); a nadie más. Si la contraseña coincide y su hash se guardó con un costo menor (el del NIP,
     * AC-14), se rehace con el normal (UpgradePasswordHash).
     */
    private function authenticate(Request $request): ?User
    {
        return (new Timebox)->call(function (Timebox $timebox) use ($request): ?User {
            $user = User::query()
                ->where('username', $request->string(Fortify::username())->value())
                ->first();

            $typed = $request->string('password')->value();
            $password = $user === null ? $typed : SiiNip::forUser($user, $typed);

            $passwordMatches = Hash::check($password, $user?->password ?? $this->decoyPasswordHash());

            if ($user === null || ! $user->is_active || $user->password === null || ! $passwordMatches) {
                return null;
            }

            if (app(DefaultPassword::class)->matches($typed)) {
                app(RequirePasswordChange::class)->handle($user);
            }

            app(UpgradePasswordHash::class)->handle($user, $password);

            $timebox->returnEarly();

            return $user;
        }, self::FAILED_LOGIN_MICROSECONDS);
    }

    private function decoyPasswordHash(): string
    {
        return $this->decoyPasswordHash ??= Hash::make(Str::random(40));
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

    /**
     * Respuesta al exceder el límite de enlaces (AC-17): de vuelta a la pantalla con el aviso, el mismo para el límite
     * por usuario y por IP, y para un usuario que exista o no.
     *
     * @param  array<string, int|string>  $headers
     */
    private function resetLinkLockedOut(Request $request, array $headers): RedirectResponse
    {
        $minutes = max(1, (int) ceil(((int) ($headers['Retry-After'] ?? 60)) / 60));

        return redirect()->route('password.request')
            ->withInput(is_string($request->input('username')) ? $request->only('username') : [])
            ->withErrors(['username' => 'Ya se pidieron varios enlaces. Intenta de nuevo en '.$minutes.($minutes === 1 ? ' minuto.' : ' minutos.')]);
    }
}
