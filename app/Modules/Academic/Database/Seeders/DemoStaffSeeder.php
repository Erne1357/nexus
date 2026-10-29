<?php

namespace App\Modules\Academic\Database\Seeders;

use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Usuarios de prueba del demo, con su rol y su alcance vigentes desde hoy, en cada campus de `$campuses`:
 *
 * - `subdir.<campus>`: Subdirección Académica del campus.
 * - `jefe.<depto>.<campus>` y `jpd.<depto>.<campus>`: jefe y jefe de proyecto docente de cada departamento activo
 *   que coordina una carrera ofrecida en el campus o imparte una materia de sus planes vigentes, con su fila en
 *   `department_heads`.
 * - `coord.<carrera>.<campus>`: coordinador de cada carrera ofrecida en el campus (`alias` de data/careers.php).
 *
 * Entran con la contraseña `password` sin tener que cambiarla (supuesto S5, por confirmar). Requiere el catálogo
 * (AcademicDemoSeeder) y los roles (RoleSeeder). A un usuario que ya existe solo se le completan el rol y el
 * alcance si le faltan, y un departamento que ya tiene jefe vigente lo conserva. No corre en producción.
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

        foreach ($campuses as $code) {
            $campus = Campus::query()->where('code', $code)->first()
                ?? throw new RuntimeException("Falta el campus {$code}: corre antes CampusSeeder.");
            $suffix = Str::lower($code);
            $careers = $this->careersOfferedIn($campus);

            $this->user("subdir.{$suffix}", "Subdirección Académica · {$campus->name}", Role::Subdireccion, ['campus_id' => $campus->id]);

            foreach ($this->departmentsOf($careers) as $department) {
                $scope = ['campus_id' => $campus->id, 'department_id' => $department->id];
                $slug = Str::lower($department->code);

                $head = $this->user("jefe.{$slug}.{$suffix}", "Jefatura de {$department->name} · {$campus->name}", Role::JefeDepartamento, $scope);
                $this->head($head, $department, $campus, DepartmentHeadKind::Jefe);

                $projectLead = $this->user("jpd.{$slug}.{$suffix}", "Jefatura de proyecto docente de {$department->name} · {$campus->name}", Role::JefeProyectoDocente, $scope);
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
     * El usuario con ese username, nuevo si no existe, con el rol y un alcance vigente sobre exactamente `$scope`:
     * si una corrida anterior lo dejó sin rol o sin alcance, se los da (desde hoy) sin duplicar los que ya tiene.
     *
     * @param  array<string, string>  $scope
     */
    private function user(string $username, string $name, Role $role, array $scope): User
    {
        $scope = ['campus_id' => null, 'department_id' => null, 'career_id' => null, ...$scope];
        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            return User::factory()
                ->withRole($role, [...$scope, 'valid_from' => today()->toDateString()])
                ->create(['username' => $username, 'name' => $name, 'email' => null, 'email_verified_at' => null]);
        }

        $user->assignRole(SpatieRole::findOrCreate($role->value));

        if (! $user->roleScopes()->current()->where(['role' => $role, ...$scope])->exists()) {
            $user->roleScopes()->create(['role' => $role, ...$scope, 'valid_from' => today()->toDateString()]);
        }

        return $user;
    }

    /**
     * Su fila en `department_heads` desde hoy, salvo que ya la tenga o que el departamento ya tenga jefe vigente.
     */
    private function head(User $user, Department $department, Campus $campus, DepartmentHeadKind $kind): void
    {
        $heads = DepartmentHead::query()
            ->where(['department_id' => $department->id, 'campus_id' => $campus->id, 'kind' => $kind])
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', today()->toDateString()));

        $taken = $kind === DepartmentHeadKind::Jefe
            ? $heads->exists()
            : $heads->where('user_id', $user->id)->exists();

        if (! $taken) {
            DepartmentHead::query()->create([
                'department_id' => $department->id,
                'campus_id' => $campus->id,
                'user_id' => $user->id,
                'kind' => $kind,
                'valid_from' => today()->toDateString(),
            ]);
        }
    }
}
