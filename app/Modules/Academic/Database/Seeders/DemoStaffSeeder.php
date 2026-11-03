<?php

namespace App\Modules\Academic\Database\Seeders;

use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Usuarios de prueba del demo, con su nombramiento (`role_assignments`) vigente desde hoy:
 *
 * - `subdir`: Subdirección Académica, una para todo el Tec (sin campus), sobre la unidad Subdirección Académica.
 * - `jefe.<depto>`: jefe de cada departamento activo de los campus de `$campuses`, de todo el Tec (sin campus).
 * - `jpd.<depto>.<campus>`: jefe de proyecto docente del departamento en cada campus de `$campuses`.
 * - `coord.<carrera>.<campus>`: coordinador de cada carrera ofrecida en el campus (`alias` de data/careers.php).
 *
 * Los departamentos de un campus son los activos que coordinan una carrera ofrecida en él o imparten una materia de
 * sus planes vigentes. Jefes y jefes de proyecto docente llevan su fila en `department_heads`.
 *
 * Entran con la contraseña `password` sin tener que cambiarla (supuesto S5, por confirmar). Requiere el catálogo
 * (AcademicDemoSeeder), y la unidad Subdirección Académica. A un usuario que ya existe solo
 * se le completan el rol y el nombramiento si le faltan, y un departamento que ya tiene jefe vigente lo conserva.
 * Los puestos exclusivos son uno por unidad (AZ-S7): si la Subdirección Académica o un departamento ya tienen, de
 * otro usuario, un nombramiento de Subdirección o de jefe vigente o por empezar, no se crea `subdir` o
 * `jefe.<depto>`. No corre en producción.
 */
class DemoStaffSeeder extends Seeder
{
    use RefusesProduction;

    /**
     * @param  list<string>  $campuses  claves de los campus
     *
     * @throws RuntimeException en producción o si falta alguno de los campus
     */
    public function run(array $campuses = ['C2']): void
    {
        $this->refuseProduction();

        $aliases = array_map(fn (array $career): string => $career['alias'], AcademicDemoSeeder::data('careers'));
        $headed = [];

        $academic = app(OrgTree::class)->idOf(OrgTree::ACADEMIC)
            ?? throw new RuntimeException('Falta la unidad '.OrgTree::ACADEMIC.': corre antes las migraciones.');

        if (! $this->seatTaken(Role::Subdireccion, $academic, 'subdir')) {
            $this->user('subdir', 'Subdirección Académica', Role::Subdireccion, ['org_unit_id' => $academic]);
        }

        foreach ($campuses as $code) {
            $campus = Campus::query()->where('code', $code)->first()
                ?? throw new RuntimeException("Falta el campus {$code}: corre antes CampusSeeder.");
            $suffix = Str::lower($code);
            $careers = $this->careersOfferedIn($campus);

            foreach ($this->departmentsOf($careers) as $department) {
                $slug = Str::lower($department->code);

                if (! isset($headed[$department->id])) {
                    if (! $this->seatTaken(Role::JefeDepartamento, $department->id, "jefe.{$slug}")) {
                        $head = $this->user("jefe.{$slug}", "Jefatura de {$department->name}", Role::JefeDepartamento, ['org_unit_id' => $department->id]);
                        $this->head($head, $department, null, DepartmentHeadKind::Jefe);
                    }

                    $headed[$department->id] = true;
                }

                $projectLead = $this->user("jpd.{$slug}.{$suffix}", "Jefatura de proyecto docente de {$department->name} · {$campus->name}", Role::JefeProyectoDocente, ['campus_id' => $campus->id, 'org_unit_id' => $department->id]);
                $this->head($projectLead, $department, $campus, DepartmentHeadKind::JefeProyectoDocente);
            }

            foreach ($careers as $career) {
                $alias = $aliases[$career->code] ?? Str::lower($career->code);

                $this->user("coord.{$alias}.{$suffix}", "Coordinación de {$career->name} · {$campus->name}", Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => $career->id]);
            }
        }
    }

    /**
     * Las carreras activas que se ofrecen en el campus.
     *
     * @return Collection<int, Career>
     */
    private function careersOfferedIn(Campus $campus): Collection
    {
        $offered = CareerCampus::query()->where('campus_id', $campus->id)->where('is_active', true)->select('career_id');

        return Career::query()->where('is_active', true)->whereIn('id', $offered)->orderBy('code')->get();
    }

    /**
     * Los departamentos activos que coordinan alguna de las carreras o imparten una materia de sus planes vigentes.
     *
     * @param  Collection<int, Career>  $careers
     * @return Collection<int, Department>
     */
    private function departmentsOf(Collection $careers): Collection
    {
        $careerIds = $careers->modelKeys();

        return Department::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereHas('careers', fn (Builder $query) => $query->whereKey($careerIds))
                ->orWhereHas('subjects.studyPlans', fn (Builder $query) => $query->where('study_plans.is_active', true)->whereIn('career_id', $careerIds)))
            ->orderBy('code')
            ->get();
    }

    /**
     * El usuario con ese username, nuevo si no existe, con un nombramiento vigente sobre exactamente
     * `$scope`: si una corrida anterior lo dejó sin nombramiento, se lo da (desde hoy) sin duplicar los que
     * ya tiene. En un puesto exclusivo basta uno vigente en la misma unidad, con el campus que sea.
     *
     * @param  array<string, string>  $scope
     */
    private function user(string $username, string $name, Role $role, array $scope): User
    {
        $scope = ['campus_id' => null, 'org_unit_id' => null, 'career_id' => null, ...$scope];
        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            return User::factory()
                ->withRole($role, [...$scope, 'valid_from' => today()->toDateString()])
                ->create(['username' => $username, 'name' => $name, 'email' => null, 'email_verified_at' => null]);
        }

        $held = $user->roleAssignments()
            ->current()
            ->where('role', $role)
            ->where($role->isExclusive() ? ['org_unit_id' => $scope['org_unit_id']] : $scope)
            ->exists();

        if (! $held) {
            $user->roleAssignments()->create(['role' => $role, ...$scope, 'valid_from' => today()->toDateString()]);
        }

        return $user;
    }

    /**
     * Si el puesto exclusivo de la unidad ya está ocupado o apartado: un nombramiento vigente o por empezar de otro
     * usuario, o uno por empezar de `$username`, con el que chocaría el de hoy.
     */
    private function seatTaken(Role $role, string $unit, string $username): bool
    {
        $today = today()->toDateString();

        return RoleAssignment::query()
            ->where('role', $role)
            ->where('org_unit_id', $unit)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->where(fn (Builder $query) => $query
                ->whereRelation('user', 'username', '<>', $username)
                ->orWhere('valid_from', '>', $today))
            ->exists();
    }

    /**
     * Su fila en `department_heads` desde hoy, salvo que ya la tenga o que el departamento ya tenga jefe vigente. El
     * jefe no lleva campus; el jefe de proyecto docente, el suyo.
     */
    private function head(User $user, Department $department, ?Campus $campus, DepartmentHeadKind $kind): void
    {
        $heads = DepartmentHead::query()
            ->where(['department_id' => $department->id, 'kind' => $kind])
            ->when($campus !== null, fn (Builder $query) => $query->where('campus_id', $campus->id))
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', today()->toDateString()));

        $taken = $kind === DepartmentHeadKind::Jefe
            ? $heads->exists()
            : $heads->where('user_id', $user->id)->exists();

        if (! $taken) {
            DepartmentHead::query()->create([
                'department_id' => $department->id,
                'campus_id' => $campus?->id,
                'user_id' => $user->id,
                'kind' => $kind,
                'valid_from' => today()->toDateString(),
            ]);
        }
    }
}
