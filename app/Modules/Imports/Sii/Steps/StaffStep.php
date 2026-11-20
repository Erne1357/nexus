<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Core\Actions\SyncStaffMember;
use App\Modules\Core\Enums\StaffType;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Sii\SiiBatchReport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\TeacherRoster;
use Illuminate\Support\Facades\Validator;

/**
 * La plantilla del SII con su tipo de personal (R-V2-3, confirmado el 19 de noviembre de 2026; que el tipo salga de
 * `docentes.nombramiento` D o A es supuesto por confirmar). Son personas cada RFC de `docentes2` (la plantilla vigente)
 * y cada docente activo de `docentes` (`active_teacher_rfcs`, que deja el paso `docentes`); de cada una SyncStaffMember
 * crea el usuario si falta (sin acceso ni puesto, con el nombre armado como el de los docentes y el correo del SII si
 * nadie más lo tiene) y le pone el tipo: D es docente, A es administrativo (también si da clases) y cualquier otro
 * valor, o que no esté en `docentes`, lo deja sin tipo con un aviso que solo cuenta.
 *
 * Nunca lee `acceso`: una cuenta de área del SII no es una persona (D10). Nunca cambia el nombre, el correo ni el
 * acceso de un usuario que ya existe, ni un docente que ya tiene su usuario lo pierde: SyncTeacherAccess liga después
 * al usuario sin puesto de ese RFC. No pasa por el motor de importación: escribe por su cuenta (SiiStep) y entrega un
 * reporte (plantilla `staff`; filas = personas, válidas = las que se pudieron poner al día, creadas, cambiadas y sin
 * cambio en las notas). En un ensayo no escribe nada y reporta lo que haría. Lo que reporta son solo cuentas: nunca un
 * RFC ni un nombre.
 */
final class StaffStep implements SiiStep
{
    /** @var list<string> las únicas columnas de `docentes` que se leen */
    public const array COLUMNS = ['rfc', 'nombramiento', 'nombre_empleado', 'apellidos_empleado', 'correo_electronico'];

    /** @var list<string> las únicas columnas de `docentes2` que se leen */
    public const array COLUMNS2 = ['rfc', 'apellidop', 'apellidom', 'nombre_empleado'];

    public const string TEMPLATE = 'staff';

    public function name(): string
    {
        return 'personal';
    }

    /**
     * @return iterable<SiiBatchReport>
     */
    public function batches(SiiMigration $migration): iterable
    {
        /** @var list<string> $activeTeachers */
        $activeTeachers = $migration->remember('active_teacher_rfcs', fn () => throw new SiiStepOutOfOrder($this->name(), 'active_teacher_rfcs', ['docentes']));

        $export = $migration->export();
        $staff = $this->read($export->reader(SiiFile::Docentes)->rows(self::COLUMNS));
        $staff2 = $this->read($export->reader(SiiFile::Docentes2)->rows(self::COLUMNS2));

        $rfcs = array_unique([...array_keys($staff2), ...array_map(TeacherRoster::normalizeRfc(...), $activeTeachers)]);
        sort($rfcs);

        $sync = app(SyncStaffMember::class);
        $count = ['created' => 0, 'changed' => 0, 'unchanged' => 0];
        $untyped = $nameless = 0;

        foreach ($rfcs as $rfc) {
            $d = $staff[$rfc] ?? null;
            $d2 = $staff2[$rfc] ?? null;
            $type = self::typeOf($d);
            $existing = User::query()->where('username', $rfc)->first();
            $untyped += $type === null ? 1 : 0;

            $name = $existing?->name ?? TeacherRoster::personName($d, $d2);

            if ($name === '') {
                $nameless++;

                continue;
            }

            $outcome = $existing === null ? 'created' : ($type !== null && $existing->staff_type !== $type ? 'changed' : 'unchanged');

            if (! $migration->dryRun()) {
                $sync->handle($rfc, $name, $this->email($d), $type);
            }

            $count[$outcome]++;
        }

        ['created' => $created, 'changed' => $changed, 'unchanged' => $unchanged] = $count;
        $people = count($rfcs);

        yield new SiiBatchReport(
            self::TEMPLATE,
            $export->fileName(SiiFile::Docentes2),
            $people,
            $people - $nameless,
            $untyped + $nameless,
            0,
            $created,
            $changed,
            $this->incidents($untyped, $nameless),
            [($migration->dryRun() ? 'Crearía' : 'Creó')." {$created} usuario(s) sin acceso, ".($migration->dryRun() ? 'pondría' : 'puso')." el tipo de personal a {$changed} que ya existían y {$unchanged} ya estaban al día."],
        );
    }

    /**
     * El primer renglón de cada RFC.
     *
     * @param  iterable<int, array<string, string>>  $rows
     * @return array<string, array<string, string>>
     */
    private function read(iterable $rows): array
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

    /**
     * @param  array<string, string>|null  $d
     */
    public static function typeOf(?array $d): ?StaffType
    {
        return match (strtoupper(trim($d['nombramiento'] ?? ''))) {
            'D' => StaffType::Docente,
            'A' => StaffType::Administrativo,
            default => null,
        };
    }

    /**
     * @param  array<string, string>|null  $d
     */
    private function email(?array $d): ?string
    {
        $email = strtolower(trim($d['correo_electronico'] ?? ''));

        return $email === '' || Validator::make(['email' => $email], ['email' => 'email'])->fails() ? null : $email;
    }

    /**
     * @return list<array{level: 'error'|'warning'|'info', ref: string, message: string}>
     */
    private function incidents(int $untyped, int $nameless): array
    {
        $out = [];

        if ($untyped > 0) {
            $out[] = ['level' => 'warning', 'ref' => 'nombramiento', 'message' => "{$untyped} persona(s) sin tipo de personal: su nombramiento en `docentes` no es D ni A, o no está en ese archivo (supuesto por confirmar)."];
        }

        if ($nameless > 0) {
            $out[] = ['level' => 'warning', 'ref' => 'nombre', 'message' => "{$nameless} persona(s) sin usuario en Nexus y sin nombre en el SII: no se dan de alta."];
        }

        return $out;
    }
}
