<?php

namespace App\Modules\Core\Providers;

use App\Modules\Core\Actions\RecordLogin;
use App\Modules\Core\Actions\UpdateUserPassword;
use App\Modules\Core\Http\Responses\PasswordUpdateResponse;
use App\Modules\Core\Models\User;
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
     * Microsegundos mínimos de un intento fallido, para que la respuesta no delate si el
     * username existe o está inactivo (lo mismo que hace SessionGuard::attempt()).
     */
    private const int FAILED_LOGIN_MICROSECONDS = 200_000;

    public function register(): void
    {
        $this->app->singleton(PasswordUpdateResponseContract::class, PasswordUpdateResponse::class);
    }

    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(8)->letters()->numbers());

        Fortify::loginView(fn () => view('core::auth.login'));
        Fortify::authenticateUsing($this->authenticate(...));
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(self::LOGIN_ATTEMPTS_PER_MINUTE)
            ->by(Str::transliterate(Str::lower($request->string(Fortify::username())->value()).'|'.$request->ip()))
            ->response($this->lockedOut(...)));

        Event::listen(function (Login $event): void {
            if ($event->user instanceof User) {
                app(RecordLogin::class)->handle($event->user);
            }
        });
    }

    /**
     * Usuario activo cuyo username y contraseña coinciden, o null. Un usuario inactivo recibe
     * el mismo mensaje genérico (`auth.failed`) que una credencial incorrecta.
     */
    private function authenticate(Request $request): ?User
    {
        return (new Timebox)->call(function (Timebox $timebox) use ($request): ?User {
            $user = User::query()
                ->where('username', $request->string(Fortify::username())->value())
                ->where('is_active', true)
                ->first();

            if ($user === null || ! Hash::check($request->string('password')->value(), $user->password)) {
                return null;
            }

            $timebox->returnEarly();

            return $user;
        }, self::FAILED_LOGIN_MICROSECONDS);
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
