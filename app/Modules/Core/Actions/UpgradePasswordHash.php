<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Rehace con el costo normal el hash de quien acaba de entrar, si su hash lo pide (`Hash::needsRehash`): el NIP del
 * SII se guarda con un costo menor (AC-14). Es la misma contraseña, así que no cambia su origen, no rota el remember
 * token ni deja bitácora. La llama el login después de comprobar la contraseña, con lo que el usuario escribió ya
 * normalizado (SiiNip::forUser()).
 *
 * Escribe solo si la contraseña sigue siendo la que se comprobó (si otra petición la cambió mientras, no la pisa). Es
 * oportunista: si la escritura falla, el login sigue y se reporta un error fijo con el SQLSTATE, sin la
 * QueryException, cuyo mensaje traería el hash nuevo (y el de un NIP se descifra en segundos).
 */
final class UpgradePasswordHash
{
    public function handle(User $user, #[\SensitiveParameter] string $plain): void
    {
        if ($user->password === null || ! Hash::needsRehash($user->password)) {
            return;
        }

        $old = $user->password;
        $new = Hash::make($plain);

        try {
            $updated = DB::transaction(fn (): int => User::query()->whereKey($user->getKey())->where('password', $old)->update(['password' => $new]));
        } catch (QueryException $exception) {
            report(new RuntimeException('No se pudo rehacer el hash de una contraseña (SQLSTATE '.$exception->getCode().').'));

            return;
        }

        if ($updated === 1) {
            $user->forceFill(['password' => $new])->syncOriginalAttribute('password');
        }
    }
}
