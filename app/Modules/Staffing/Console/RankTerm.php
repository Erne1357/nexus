<?php

namespace App\Modules\Staffing\Console;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\RankGroups;
use Illuminate\Console\Command;

/**
 * Calcula en el momento, sin la cola, el ranking docente de un periodo (RankGroups): cada departamento con grupos en
 * cada campus, o solo el campus y el departamento indicados. Lo usan los seeders y los operadores.
 */
final class RankTerm extends Command
{
    protected $signature = 'staffing:rank
        {term : Código SII del periodo (p. ej. 20271)}
        {--campus= : Código del campus}
        {--department= : Código del departamento}';

    protected $description = 'Calcula el ranking docente de los grupos de un periodo';

    public function handle(RankGroups $rank): int
    {
        $term = Term::query()->where('code', $this->argument('term'))->first();

        if ($term === null) {
            return $this->refuse("No existe el periodo {$this->argument('term')}.");
        }

        $campus = $this->option('campus');
        $campusId = null;

        if ($campus !== null && ($campusId = Campus::query()->where('code', $campus)->value('id')) === null) {
            return $this->refuse("No existe el campus {$campus}.");
        }

        $department = $this->option('department');
        $departmentId = null;

        if ($department !== null && ($departmentId = Department::query()->where('code', $department)->value('id')) === null) {
            return $this->refuse("No existe el departamento {$department}.");
        }

        $scopes = Group::query()
            ->where('term_id', $term->id)
            ->when($campusId, fn ($query) => $query->where('campus_id', $campusId))
            ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
            ->distinct()
            ->get(['campus_id', 'department_id']);
        $campuses = Campus::query()->whereIn('id', $scopes->pluck('campus_id')->unique()->all())->get()->keyBy('id');
        $departments = Department::query()->whereIn('id', $scopes->pluck('department_id')->unique()->all())->get()->keyBy('id');
        $total = 0;

        foreach ($scopes as $scope) {
            if ($campuses->has($scope->campus_id) && $departments->has($scope->department_id)) {
                $total += $rank->handle($term, $campuses[$scope->campus_id], $departments[$scope->department_id]);
            }
        }

        $this->components->info("Ranking docente calculado para {$total} ".($total === 1 ? 'grupo' : 'grupos').'.');

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
