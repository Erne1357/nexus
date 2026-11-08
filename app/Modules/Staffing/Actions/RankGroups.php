<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\ValidationResult;
use App\Modules\Staffing\Events\RankingsComputed;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Queries\RankingInput;
use App\Modules\Staffing\Queries\RankingInputsQuery;
use App\Modules\Staffing\Rules\RankedCandidate;
use App\Modules\Staffing\Rules\RankingScorer;
use App\Modules\Staffing\Rules\RankingWeights;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula el ranking docente de los grupos de un departamento en un periodo y campus (reglas-de-negocio.md,
 * «Ranking docente (desempate)»; tarea 3.8) y devuelve cuántos grupos rankeó (los no cancelados).
 *
 * Los insumos de Faculty salen de RankingInputsQuery en lotes. Las marcas las da el validador de Scheduling (scoped;
 * se olvida su foto al empezar para leer la de ahora), con checkTeachers() una vez por grupo: HC-01 marca «choque», HC-08 «sin horas»
 * y HC-09 «no disponible», según las reglas que el validador tenga. Las horas restantes de cada candidato, sin y con
 * el grupo («quedan 12 → 7»), salen de teacherHours(), con la misma cuenta de HC-08 (TeacherHourLimit). Ordena con
 * RankingScorer y los pesos de `staffing.weights`.
 *
 * En una sola transacción borra las filas de `staffing_rankings` de todos los grupos del departamento en ese periodo
 * y campus (también las de los cancelados, que se quedan sin ranking) y escribe las nuevas, así que quien lee ve el
 * ranking anterior completo o el nuevo. Es una tabla derivada (decisión de modelado 5): no deja bitácora. Después del
 * commit avisa con RankingsComputed. La corre el job ComputeRankings y el comando `staffing:rank`.
 */
final class RankGroups
{
    private const int INSERT_CHUNK = 1000;

    public function __construct(
        private readonly RankingInputsQuery $inputs,
        private readonly Container $container,
    ) {}

    public function handle(Term $term, Campus $campus, Department $department): int
    {
        $groups = Group::query()->active()
            ->where('term_id', $term->id)
            ->where('campus_id', $campus->id)
            ->where('department_id', $department->id)
            ->orderBy('id')
            ->get();

        $rows = $groups->isEmpty() ? [] : $this->rows($term, $groups);

        DB::transaction(function () use ($term, $campus, $department, $rows): void {
            StaffingRanking::query()
                ->where('term_id', $term->id)
                ->where('campus_id', $campus->id)
                ->whereIn('group_id', Group::query()
                    ->where('term_id', $term->id)
                    ->where('campus_id', $campus->id)
                    ->where('department_id', $department->id)
                    ->select('id'))
                ->delete();

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                StaffingRanking::query()->insert($chunk);
            }
        });

        RankingsComputed::dispatch($term->id, $campus->id, $department->id);

        return $groups->count();
    }

    /**
     * @param  Collection<int, Group>  $groups
     * @return list<array<string, mixed>>
     */
    private function rows(Term $term, Collection $groups): array
    {
        $inputs = $this->inputs->forGroups($term, $groups);
        $candidateIds = collect($inputs)->flatten()->map(fn (RankingInput $input): string => $input->teacherId)->unique()->values();

        if ($candidateIds->isEmpty()) {
            return [];
        }

        $teachers = Teacher::query()->whereIn('id', $candidateIds->all())->get(['id'])->keyBy('id');
        $validator = $this->container->make(ScheduleValidator::class);
        // Es scoped: dentro de una petición o un job puede traer una foto leída antes de una escritura.
        $validator->forget();
        $scorer = new RankingScorer(RankingWeights::fromSettings());
        $now = now();
        $warmed = false;
        $rows = [];

        foreach ($groups as $group) {
            $groupInputs = $inputs[$group->id] ?? [];

            if ($groupInputs === []) {
                continue;
            }

            /*
             * La primera pregunta lleva a todos los candidatos del departamento: el validador lee de una vez los
             * docentes que su foto no tiene, y los grupos siguientes ya no tocan la base.
             */
            $asked = $warmed
                ? $teachers->only(array_map(fn (RankingInput $input): string => $input->teacherId, $groupInputs))->values()
                : $teachers->values();
            $warmed = true;
            $results = $validator->checkTeachers($group, $asked);
            $hours = $validator->teacherHours($group, $asked);
            $fromOther = [];
            $candidates = [];

            foreach ($groupInputs as $input) {
                $result = $results[$input->teacherId] ?? new ValidationResult;
                $fromOther[$input->teacherId] = $input->fromOtherDepartment;
                $candidates[] = $input->toCandidate(
                    noHours: $result->forRule('HC-08') !== [],
                    clash: $result->forRule('HC-01') !== [],
                    unavailable: $result->forRule('HC-09') !== [],
                    remainingHours: self::remaining($hours[$input->teacherId] ?? null, 'before'),
                );
            }

            foreach ($scorer->rank($candidates) as $ranked) {
                $teacherId = $ranked->candidate->teacherId;
                $rows[] = self::row($group, $ranked, $fromOther[$teacherId], self::remaining($hours[$teacherId] ?? null, 'after'), $now);
            }
        }

        return $rows;
    }

    /**
     * @param  array{before: array<string, mixed>, after: array<string, mixed>}|null  $hours
     */
    private static function remaining(?array $hours, string $when): ?int
    {
        $remaining = $hours[$when]['remaining_hours'] ?? null;

        return is_numeric($remaining) ? (int) $remaining : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Group $group, RankedCandidate $ranked, bool $fromOtherDepartment, ?int $remainingAfter, CarbonInterface $now): array
    {
        return [
            'term_id' => $group->term_id,
            'campus_id' => $group->campus_id,
            'group_id' => $group->id,
            'teacher_id' => $ranked->candidate->teacherId,
            'rank' => $ranked->rank,
            'score' => $ranked->score,
            'breakdown' => json_encode($ranked->breakdown() + [
                'remaining_after_hours' => $remainingAfter,
                'from_other_department' => $fromOtherDepartment,
            ]),
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
