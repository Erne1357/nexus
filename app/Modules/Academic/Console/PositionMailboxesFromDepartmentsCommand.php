<?php

namespace App\Modules\Academic\Console;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\SavePositionMailbox;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\InstitutionalMail;
use Illuminate\Console\Command;

/**
 * Comando de una sola vez (CE-02, tarea 1.63): crea el buzón de la jefatura (`jefe_departamento`, unidad = el
 * departamento, que es una `org_units` con el mismo id) de cada departamento activo que ya trae su correo en
 * `departments.email` (lo escribe la sincronización del mapa, UpdateDepartmentContact). Solo correos institucionales
 * (CE-01). Es idempotente: no toca un departamento cuya jefatura ya tiene buzón activo, sea el mismo correo o otro que
 * capturó el admin (lo reporta). Escribe con SavePositionMailbox (bitácora, y libera los correos que esperaban). Con
 * `--dry-run` solo cuenta. Vive en Academic porque lee los departamentos; Core no depende de Academic.
 *
 *     php artisan academic:position-mailboxes-from-departments --dry-run --no-interaction
 *     php artisan academic:position-mailboxes-from-departments --user=<usuario del admin> --no-interaction
 */
final class PositionMailboxesFromDepartmentsCommand extends Command
{
    protected $signature = 'academic:position-mailboxes-from-departments
        {--dry-run : Solo cuenta lo que haría, sin escribir}
        {--user= : Usuario del admin que queda en la bitácora}';

    protected $description = 'Crea los buzones de puesto de las jefaturas desde el correo de cada departamento (CE-02)';

    public function handle(SavePositionMailbox $save): int
    {
        $actor = null;

        if (($username = $this->option('user')) !== null) {
            $actor = User::query()->where('username', $username)->first();

            if ($actor === null) {
                $this->error("No existe el usuario {$username}.");

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $counts = ['crear' => 0, 'ya estaba' => 0, 'omitido' => 0];

        foreach (Department::query()->where('is_active', true)->whereNotNull('email')->orderBy('code')->get() as $department) {
            $email = mb_strtolower(trim((string) $department->email));
            [$result, $kind] = $this->plan($department, $email);

            if ($kind === 'crear' && ! $dryRun) {
                $save->handle(['role' => Role::JefeDepartamento->value, 'org_unit_id' => $department->getKey(), 'email' => $email], null, $actor);
                $result = 'Creado';
            }

            $counts[$kind]++;
            $rows[] = [$department->code, $email === '' ? '—' : $email, $result];
        }

        $this->table(['Departamento', 'Correo', 'Resultado'], $rows);
        $this->info(($dryRun ? 'Sin escribir (--dry-run). ' : '')."Por crear: {$counts['crear']} · Ya tenían ese buzón: {$counts['ya estaba']} · Omitidos: {$counts['omitido']}.");

        return self::SUCCESS;
    }

    /**
     * Qué haría con el departamento: el texto del renglón y su cuenta.
     *
     * @return array{0: string, 1: string}
     */
    private function plan(Department $department, string $email): array
    {
        if ($email === '') {
            return ['Sin correo', 'omitido'];
        }

        if (! InstitutionalMail::isInstitutional($email)) {
            return ['Omitido: no es un correo institucional', 'omitido'];
        }

        if (! OrgUnit::query()->whereKey($department->getKey())->exists()) {
            return ['Omitido: no tiene su unidad en el Organigrama', 'omitido'];
        }

        $current = PositionMailbox::query()->active()
            ->where('role', Role::JefeDepartamento)
            ->where('org_unit_id', $department->getKey())
            ->whereNull('career_id')->whereNull('campus_id')->whereNull('modality_id')
            ->value('email');

        return match (true) {
            $current === $email => ['Ya tenía ese buzón', 'ya estaba'],
            $current !== null => ["Omitido: su jefatura ya tiene el buzón {$current}", 'omitido'],
            default => ['Por crear', 'crear'],
        };
    }
}
