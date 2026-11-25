<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Actions\GrantDefaultAccess;
use App\Modules\Core\Actions\SetStaffPhoto;
use App\Modules\Core\Actions\UpdateUserProfile;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Activa cuentas del personal desde un CSV del servidor (`usuario,nombre,foto`), con las Actions (bitácora): corrige el
 * nombre, pone la foto si cambió y da la contraseña por defecto con cambio obligatorio a quien no eligió la suya
 * (CO-11, C8, C9; excepción a AC-03). Los datos viven solo en el archivo, fuera del repositorio; las fotos, junto al
 * CSV. Idempotente. Un error en una fila no frena las demás. Nunca imprime contraseñas. Con `--dry-run` solo cuenta.
 *
 *     docker compose exec app php artisan core:provision-staff storage/app/imports/comunicacion/cuentas.csv --dry-run
 */
final class ProvisionStaffCommand extends Command
{
    protected $signature = 'core:provision-staff {file : CSV con las columnas usuario,nombre,foto} {--dry-run : Solo cuenta lo que haría, sin escribir}';

    protected $description = 'Activa cuentas del personal desde un CSV: nombre, foto y contraseña por defecto con cambio obligatorio';

    public function handle(UpdateUserProfile $profile, SetStaffPhoto $photo, GrantDefaultAccess $access, UserAccountRules $accounts, DefaultPassword $defaultPassword): int
    {
        $file = (string) $this->argument('file');
        $dry = (bool) $this->option('dry-run');

        if (! is_file($file) || ($handle = fopen($file, 'r')) === false) {
            $this->error("No se encontró el archivo: {$file}");

            return self::FAILURE;
        }

        try {
            $defaultPassword->value();
        } catch (Throwable $e) {
            $this->error('Falta NEXUS_DEFAULT_PASSWORD en el .env.');

            return self::FAILURE;
        }

        $dir = dirname((string) realpath($file));
        $header = fgetcsv($handle, escape: '');
        $header = array_map(fn ($h): string => mb_strtolower(trim(ltrim((string) $h, "\xEF\xBB\xBF"))), $header ?: []);

        if (array_diff(['usuario', 'nombre', 'foto'], $header) !== []) {
            fclose($handle);
            $this->error('El CSV debe traer el encabezado usuario,nombre,foto.');

            return self::FAILURE;
        }

        $counts = ['rows' => 0, 'errors' => 0, 'names' => 0, 'photos' => 0, 'access' => 0, 'noPosition' => 0];

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            if ($cells === [null]) {
                continue;
            }

            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $counts['rows']++;
            $username = trim((string) $row['usuario']);

            try {
                $user = User::query()->whereRaw('lower(username) = ?', [mb_strtolower($username)])->first();

                if ($user === null) {
                    throw new \RuntimeException('el usuario no existe');
                }

                if ($accounts->isStudent($user)) {
                    throw new \RuntimeException('es alumno, no personal');
                }

                if (! $user->is_active || $user->deactivated_at !== null) {
                    throw new \RuntimeException('la cuenta está inactiva');
                }

                $path = null;

                if (($photoName = trim((string) $row['foto'])) !== '') {
                    $path = $dir.DIRECTORY_SEPARATOR.basename($photoName);

                    if (! is_file($path)) {
                        throw new \RuntimeException('no se encontró el archivo de la foto');
                    }
                }

                $newName = trim((string) $row['nombre']);
                $changesName = $newName !== '' && $newName !== $user->name;
                $changesPhoto = $path !== null && ! $this->samePhoto($user, $path);
                $grants = $this->needsDefault($user, $defaultPassword);

                if (! $dry) {
                    DB::transaction(function () use ($user, $profile, $photo, $access, $changesName, $changesPhoto, $newName, $path): void {
                        if ($changesName) {
                            $profile->handle($user, ['name' => $newName]);
                        }

                        if ($changesPhoto) {
                            $photo->handle($user, $path);
                        }

                        $access->handle($user);
                    });
                }

                $counts['names'] += (int) $changesName;
                $counts['photos'] += (int) $changesPhoto;
                $counts['access'] += (int) $grants;

                if (! $user->roleAssignments()->current()->exists()) {
                    $counts['noPosition']++;
                    $this->warn("{$username}: no tiene un puesto vigente.");
                }
            } catch (ValidationException $e) {
                $counts['errors']++;
                $this->error("{$username}: ".collect($e->errors())->flatten()->implode(' '));
            } catch (\RuntimeException $e) {
                $counts['errors']++;
                $this->error("{$username}: {$e->getMessage()}.");
            }
        }

        fclose($handle);

        $verb = $dry ? 'Haría' : 'Hecho';
        $this->line(sprintf('%s: %d filas, %d con error.', $dry ? 'Simulación' : 'Listo', $counts['rows'], $counts['errors']));
        $this->line(sprintf('  %s: %d nombres corregidos, %d fotos, %d cuentas activadas con la contraseña por defecto.', $verb, $counts['names'], $counts['photos'], $counts['access']));
        $this->line(sprintf('  Sin puesto vigente: %d.', $counts['noPosition']));

        return self::SUCCESS;
    }

    /**
     * Si la contraseña por defecto habría que ponerla (la misma regla de GrantDefaultAccess).
     */
    private function needsDefault(User $user, DefaultPassword $defaultPassword): bool
    {
        if ($user->password === null) {
            return true;
        }

        return $user->password_origin?->value !== 'user'
            && ! ($user->password_origin?->value === 'default' && Hash::check($defaultPassword->value(), $user->password));
    }

    /**
     * Si la foto del archivo es la del original vigente (huella sha256 del contenido).
     */
    private function samePhoto(User $user, string $path): bool
    {
        if ($user->photo_path === null) {
            return false;
        }

        $disk = Storage::disk('staff_photos');
        $base = substr($user->photo_path, 0, -4).'-original.';
        $hash = hash_file('sha256', $path);

        foreach ($disk->files(dirname($user->photo_path)) as $candidate) {
            if (str_starts_with($candidate, $base)) {
                return hash('sha256', (string) $disk->get($candidate)) === $hash;
            }
        }

        return false;
    }
}
