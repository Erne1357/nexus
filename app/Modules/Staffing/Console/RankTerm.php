<?php

namespace App\Modules\Staffing\Console;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\RankGroups;
use Illuminate\Console\Command;

/**
 * Calcula en el momento, sin la cola, el ranking docente de un periodo (RankGroups): cada departamento con grupos en
 * cada modalidad, con los grupos de todos sus campus (M1), o solo la modalidad y el departamento indicados. Lo usan los
 * seeders y los operadores.
 */
final class RankTerm extends Command
{
    protected $signature = 'staffing:rank
        {term : Código SII del periodo (p. ej. 20271)}
        {--modality= : Clave de la modalidad (p. ej. PRES)}
        {--department= : Código del departamento}';

    protected $description = 'Calcula el ranking docente de los grupos de un periodo';

    public function handle(RankGroups $rank): int
    {
        $term = Term::query()->where('code', $this->argument('term'))->first();

        if ($term === null) {
            return $this->refuse("No existe el periodo {$this->argument('term')}.");
        }

        $modality = $this->option('modality');
        $modalityId = null;

        if ($modality !== null && ($modalityId = Modality::query()->where('code', $modality)->value('id')) === null) {
            return $this->refuse("No existe la modalidad {$modality}.");
        }

        $department = $this->option('department');
        $departmentId = null;

        if ($department !== null && ($departmentId = Department::query()->where('code', $department)->value('id')) === null) {
            return $this->refuse("No existe el departamento {$department}.");
        }

        $scopes = Group::query()
            ->where('term_id', $term->id)
            ->when($modalityId, fn ($query) => $query->where('modality_id', $modalityId))
            ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
            ->distinct()
            ->get(['modality_id', 'department_id']);
        $modalities = Modality::query()->whereIn('id', $scopes->pluck('modality_id')->unique()->all())->get()->keyBy('id');
        $departments = Department::query()->whereIn('id', $scopes->pluck('department_id')->unique()->all())->get()->keyBy('id');
        $total = 0;

        foreach ($scopes as $scope) {
            if ($modalities->has($scope->modality_id) && $departments->has($scope->department_id)) {
                $total += $rank->handle($term, $modalities[$scope->modality_id], $departments[$scope->department_id]);
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
