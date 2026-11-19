<?php

namespace App\Modules\Solver\Console;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Rules\Cohorts;
use App\Modules\Solver\Queries\BuildSolverInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Genera un fixture compartido del motor desde la base de la conexión actual (tarea 6.11; docs/arquitectura/solver.md,
 * «Fixtures compartidos y pruebas»): arma la entrada de la carga de un departamento (o de una carrera, con `--career`)
 * con BuildSolverInput y la escribe en `tests/Fixtures/solver/v1/<name>/input.json`, con todos los identificadores
 * cambiados por fichas cortas y estables (`g001`, `t001`, `r001`…) para que el fixture no deje salir llaves de la base.
 * El contrato no lleva nombres ni datos personales; las claves de materia y de campus son del catálogo.
 *
 *     docker compose exec -e DB_DATABASE=nexus_demo_a2 app php artisan solver:export-fixture 20271 PRES CEA department-multicampus
 */
final class ExportSolverFixture extends Command
{
    protected $signature = 'solver:export-fixture
        {term : Clave del periodo, por ejemplo 20271}
        {modality : Clave de la modalidad, por ejemplo PRES}
        {department_code : Clave del departamento dueño de la carga}
        {name : Nombre de la carpeta del fixture en tests/Fixtures/solver/v1}
        {--career= : Clave de una carrera: exporta solo sus grupos como movibles, en lugar de la carga del departamento}';

    protected $description = 'Exporta la entrada del motor de una carga como fixture anonimizado de tests/Fixtures/solver/v1';

    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** @var array<string, array<string, string>> ficha por categoría y por llave original */
    private array $tokens = [];

    public function handle(BuildSolverInput $builder): int
    {
        $term = Term::where('code', $this->argument('term'))->first();
        $modality = Modality::where('code', $this->argument('modality'))->first();
        $department = Department::where('code', $this->argument('department_code'))->first();

        if ($term === null || $modality === null || $department === null) {
            $this->error('No se encontró el periodo, la modalidad o el departamento indicados.');

            return self::FAILURE;
        }

        $name = (string) $this->argument('name');

        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
            $this->error('El nombre del fixture solo lleva minúsculas, dígitos y guiones.');

            return self::INVALID;
        }

        if ($this->option('career') !== null) {
            $career = Career::where('code', $this->option('career'))->first();

            if ($career === null) {
                $this->error('No se encontró la carrera indicada.');

                return self::FAILURE;
            }

            $build = $builder->forCareer($term, $modality, $career, 'fixture-'.$name);
        } else {
            $build = $builder->forDepartment($term, $modality, $department, 'fixture-'.$name);
        }

        $this->tokens = [];
        $data = $this->anonymize($build->input->toArray());
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (preg_match('/'.self::UUID.'/i', $json) === 1) {
            throw new RuntimeException('Quedó una llave de la base en el fixture; revisa anonymize().');
        }

        $directory = base_path('tests/Fixtures/solver/v1/'.$name);
        File::ensureDirectoryExists($directory);
        File::put($directory.'/input.json', $json."\n");

        $this->info(sprintf(
            '%s: %d grupos, %d docentes, %d salones, %d campus (%s KB).',
            $directory.'/input.json',
            count($data['groups']),
            count($data['teachers']),
            count($data['rooms']),
            count($data['campuses']),
            number_format(strlen($json) / 1024, 0),
        ));

        foreach ($build->warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }

    /**
     * Cambia cada llave de la entrada por su ficha, con las referencias cruzadas consistentes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function anonymize(array $data): array
    {
        $data['run_id'] = 'fixture-'.$this->argument('name');
        $data['scope']['id'] = $this->token($data['scope']['kind'] === 'career' ? 'career' : 'department', $data['scope']['id']);

        $data['groups'] = array_map(function (array $group): array {
            $group['id'] = $this->token('group', $group['id']);
            $group['department_id'] = $this->token('department', $group['department_id']);
            $group['owner_career_id'] = $this->token('career', $group['owner_career_id']);
            $group['study_plan_id'] = $this->token('plan', $group['study_plan_id']);

            if (isset($group['teacher_id'])) {
                $group['teacher_id'] = $this->token('teacher', $group['teacher_id']);
            }

            $group['cohorts'] = array_map($this->cohortKey(...), $group['cohorts'] ?? []);
            $group['candidate_room_ids'] = array_map(fn (string $id): string => $this->token('room', $id), $group['candidate_room_ids'] ?? []);

            foreach (['fixed_sessions', 'current_sessions'] as $field) {
                $group[$field] = array_map(function (array $session): array {
                    if (isset($session['room_id'])) {
                        $session['room_id'] = $this->token('room', $session['room_id']);
                    }

                    return $session;
                }, $group[$field] ?? []);
            }

            return $group;
        }, $data['groups']);

        $data['teachers'] = array_map(function (array $teacher): array {
            $teacher['id'] = $this->token('teacher', $teacher['id']);

            return $teacher;
        }, $data['teachers']);

        $data['rooms'] = array_map(function (array $room): array {
            $room['id'] = $this->token('room', $room['id']);
            $room['building_id'] = $this->token('building', $room['building_id']);

            if (isset($room['owner_department_id'])) {
                $room['owner_department_id'] = $this->token('department', $room['owner_department_id']);
            }

            return $room;
        }, $data['rooms']);

        $data['agreements'] = array_map(function (array $agreement): array {
            $agreement['room_id'] = $this->token('room', $agreement['room_id']);
            $agreement['to_department_id'] = $this->token('department', $agreement['to_department_id']);

            if (isset($agreement['group_id'])) {
                $agreement['group_id'] = $this->token('group', $agreement['group_id']);
            }

            return $agreement;
        }, $data['agreements']);

        $data['subject_room_defaults'] = array_map(function (array $default): array {
            $default['room_id'] = $this->token('room', $default['room_id']);

            return $default;
        }, $data['subject_room_defaults']);

        return $data;
    }

    /**
     * `plan:<plan>:<semestre>:<campus>`, `combination:<plan>:<semestre>:<campus>` (con la misma ficha de plan que
     * `study_plan_id`) y `package:<paquete>:<campus>:<número>`: solo cambian las llaves.
     */
    private function cohortKey(string $key): string
    {
        $kind = str_starts_with($key, 'plan:') || Cohorts::isCombination($key) ? 'plan' : 'package';

        return preg_replace_callback('/'.self::UUID.'/i', fn (array $match): string => $this->token($kind, $match[0]), $key) ?? $key;
    }

    private function token(string $category, string $id): string
    {
        $prefix = ['group' => 'g', 'teacher' => 't', 'room' => 'r', 'building' => 'b', 'department' => 'd', 'career' => 'c', 'plan' => 'p', 'package' => 'k'][$category];

        return $this->tokens[$category][$id] ??= sprintf('%s%03d', $prefix, count($this->tokens[$category] ?? []) + 1);
    }
}
