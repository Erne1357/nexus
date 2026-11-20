<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Actions\SyncStaffMember;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\SyncTeacherAccess;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Imports\Sii\SiiBatchReport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Imports\Sii\StaffNames;
use App\Modules\Imports\Sii\TeacherRoster;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Validation\ValidationException;

/**
 * Los titulares del organigrama oficial (R-V2-6, confirmado el 19 de noviembre de 2026; que la División de Estudios
 * de Posgrado e Investigación se nombre sobre el departamento 110900 es supuesto por confirmar: el archivo trae la
 * unidad, aquí no hay nada fijo). Lee `titulares.csv` (`unidad`, `nombre`, `desde`; opcional: sin él avisa una vez y
 * no hace nada), busca el RFC de cada nombre entre el personal del SII (StaffNames: exactamente una persona o no se
 * nombra) y lo nombra con AppointRole en la unidad: Dirección y subdirecciones que no son la Académica, directivo;
 * `SUB-ACADEMICA`, subdirección; cualquier otra unidad, jefe de departamento.
 *
 * Antes de nombrar liga al docente con su usuario (SyncTeacherAccess, solo si ese RFC ya es docente sin usuario):
 * un usuario con puesto distinto de docente ya no se puede ligar y el docente perdería su acceso. Si la persona no
 * tiene usuario lo crea SyncStaffMember sin acceso, con el tipo de personal de `StaffStep`.
 *
 * Es idempotente: si el titular ya ocupa el puesto no escribe nada; si lo ocupa otra persona avisa y lo deja; una
 * unidad desconocida o inactiva, una fecha inválida, un nombre que no está o que es ambiguo, o un nombramiento que las
 * reglas de AppointRole rechazan, avisan y no cambian nada. En un ensayo no escribe y reporta lo que haría. Las
 * incidencias nombran el renglón («titulares.csv:<línea> (unidad <código>)»), nunca a la persona ni su RFC.
 */
final class AppointmentsStep implements SiiStep
{
    public const string TEMPLATE = 'appointments';

    public function name(): string
    {
        return 'nombramientos';
    }

    /**
     * @return iterable<SiiBatchReport>
     */
    public function batches(SiiMigration $migration): iterable
    {
        $export = $migration->export();

        if (! $export->has(SiiFile::Titulares)) {
            yield new SiiBatchReport(self::TEMPLATE, SiiFile::Titulares->value.'.csv', 0, 0, 1, 0, 0, 0, [
                ['level' => 'warning', 'ref' => 'titulares.csv', 'message' => 'La exportación no trae titulares.csv: no se nombra a nadie.'],
            ]);

            return;
        }

        $file = $export->fileName(SiiFile::Titulares);
        $staff = $this->byRfc($export->reader(SiiFile::Docentes)->rows(StaffStep::COLUMNS));
        $staff2 = $this->byRfc($export->reader(SiiFile::Docentes2)->rows(StaffStep::COLUMNS2));
        $names = new StaffNames($staff, $staff2);

        $rows = $appointed = $unchanged = 0;
        $incidents = [];

        foreach ($export->reader(SiiFile::Titulares)->rows() as $line => $row) {
            $rows++;
            $ref = "{$file}:{$line} (unidad {$row['unidad']})";
            $warn = function (string $message) use (&$incidents, $ref): void {
                $incidents[] = ['level' => 'warning', 'ref' => $ref, 'message' => $message];
            };

            $unit = OrgUnit::query()->where('code', $row['unidad'])->where('is_active', true)->first();
            if ($unit === null) {
                $warn('La unidad no existe en Nexus o está inactiva: no se nombra.');

                continue;
            }

            $from = $this->date($row['desde']);
            if ($from === null) {
                $warn('La fecha «desde» no es una fecha válida (AAAA-MM-DD): se omite el renglón.');

                continue;
            }

            $rfc = $names->rfcFor($row['nombre']);
            if ($rfc === null) {
                $warn('El nombre no está en el personal del SII o coincide con más de una persona: no se nombra.');

                continue;
            }

            $role = $this->roleFor($unit);
            $holder = RoleAssignment::query()->current()->where('role', $role)->where('org_unit_id', $unit->getKey())->with('user:id,username')->first();

            if ($holder !== null) {
                if ($holder->user->username === $rfc) {
                    $unchanged++;
                } else {
                    $warn('El puesto ya lo ocupa otra persona: no se cambia.');
                }

                continue;
            }

            if ($migration->dryRun()) {
                $appointed++;

                continue;
            }

            try {
                $user = $this->userFor($rfc, $staff[$rfc] ?? null, $staff2[$rfc] ?? null);
                app(AppointRole::class)->handle($user, $role, null, $unit, null, $from);
                $appointed++;
            } catch (ValidationException) {
                $warn('Las reglas de nombramiento lo rechazan (usuario inactivo o fechas que chocan): no se nombra.');
            }
        }

        $dry = $migration->dryRun();
        $warnings = count($incidents);

        yield new SiiBatchReport(
            self::TEMPLATE,
            $file,
            $rows,
            $appointed + $unchanged,
            $warnings,
            0,
            $appointed,
            0,
            $incidents,
            [($dry ? 'Nombraría' : 'Nombró')." a {$appointed} titular(es); {$unchanged} ya ocupaban su puesto y {$warnings} renglón(es) con aviso."],
        );
    }

    private function roleFor(OrgUnit $unit): Role
    {
        return match (true) {
            $unit->code === 'SUB-ACADEMICA' => Role::Subdireccion,
            $unit->kind === OrgUnitKind::Direccion, $unit->kind === OrgUnitKind::Subdireccion => Role::Directivo,
            default => Role::JefeDepartamento,
        };
    }

    private function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (InvalidFormatException) {
            return null;
        }

        return $date->toDateString() === $value ? $date : null;
    }

    /**
     * El usuario del RFC. Si ya es docente sin usuario se liga antes de nombrarlo (SyncTeacherAccess lo hace solo con
     * un usuario sin más puesto que docente); si sigue sin usuario, se crea sin acceso.
     *
     * @param  array<string, string>|null  $d
     * @param  array<string, string>|null  $d2
     */
    private function userFor(string $rfc, ?array $d, ?array $d2): User
    {
        $teacher = Teacher::query()->where('rfc', $rfc)->whereNull('user_id')->first();
        if ($teacher !== null) {
            app(SyncTeacherAccess::class)->handle($teacher);
        }

        return User::query()->where('username', $rfc)->first()
            ?? app(SyncStaffMember::class)->handle($rfc, TeacherRoster::personName($d, $d2), null, StaffStep::typeOf($d));
    }

    /**
     * El primer renglón de cada RFC.
     *
     * @param  iterable<int, array<string, string>>  $rows
     * @return array<string, array<string, string>>
     */
    private function byRfc(iterable $rows): array
    {
        $byRfc = [];

        foreach ($rows as $row) {
            $rfc = TeacherRoster::normalizeRfc($row['rfc']);
            if ($rfc !== '') {
                $byRfc[$rfc] ??= $row;
            }
        }

        return $byRfc;
    }
}
