<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Events\ScheduleConflictsSynced;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula los choques de la carga de una modalidad en un periodo (`schedule_conflicts`, tarea 3.2; M1: con los grupos
 * de todos sus campus): valida la carga completa una vez y deja la tabla igual a lo que encontró. Un choque que sigue
 * se conserva (con su detalle al día), uno nuevo se agrega y uno que ya no aparece queda resuelto (`resolved_at`), así
 * el tablero distingue lo vigente de lo corregido. Solo guarda los choques de los grupos de la modalidad, cada uno con
 * el campus de ahora de su grupo (un filtro del tablero, no la carga; uno que sigue lo toma si el grupo cambió de campus): el otro lado de un empalme de docente con otra modalidad
 * lo guarda el recálculo de esa modalidad.
 *
 * Es una tabla derivada, como `student_progress`: no deja bitácora (la bitácora es de las escrituras de las personas,
 * que ya la dejan). Corre en una transacción, con la carga bloqueada por un candado de transacción por periodo y
 * modalidad para que dos recálculos no se encimen, y avisa con ScheduleConflictsSynced después del commit.
 */
final class SyncScheduleConflicts
{
    public function __construct(private readonly ScheduleValidator $validator) {}

    /**
     * @return array{opened: int, kept: int, resolved: int}
     */
    public function handle(Term $term, Modality $modality): array
    {
        return DB::transaction(function () use ($term, $modality): array {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["schedule_conflicts:{$term->id}:{$modality->id}"]);

            $this->validator->forget();
            $violations = $this->validator->validateLoad($term, $modality)->violations();
            $groups = Group::query()->where('term_id', $term->id)->where('modality_id', $modality->id)
                ->get(['id', 'campus_id', 'department_id', 'owner_career_id'])->keyBy('id');

            $found = [];

            foreach ($violations as $violation) {
                if ($violation->groupId === null || ! $groups->has($violation->groupId)) {
                    continue;
                }

                $found[$violation->key()] = $violation;
            }

            $open = ScheduleConflict::query()->open()->where('term_id', $term->id)->where('modality_id', $modality->id)
                ->lockForUpdate()->get();

            $kept = 0;
            $resolved = 0;

            foreach ($open as $conflict) {
                $key = self::keyOf($conflict);

                if (isset($found[$key])) {
                    $conflict->update([
                        'related' => $found[$key]->related,
                        'severity' => $found[$key]->severity,
                        'campus_id' => $groups[$conflict->group_id]->campus_id ?? $conflict->campus_id,
                    ]);
                    unset($found[$key]);
                    $kept++;

                    continue;
                }

                $conflict->update(['resolved_at' => now()]);
                $resolved++;
            }

            foreach ($found as $violation) {
                $group = $groups[$violation->groupId];

                ScheduleConflict::query()->create([
                    'term_id' => $term->id,
                    'campus_id' => $group->campus_id,
                    'modality_id' => $modality->id,
                    'department_id' => $group->department_id,
                    'career_id' => $group->owner_career_id,
                    'group_id' => $group->id,
                    'rule' => $violation->rule,
                    'severity' => $violation->severity,
                    'subject_type' => $violation->subjectType,
                    'subject_id' => $violation->subjectId,
                    'related' => $violation->related,
                    'message' => $violation->message,
                ]);
            }

            Cache::forever(self::stampKey($term->id, $modality->id), now()->toIso8601String());
            ScheduleConflictsSynced::dispatch($term->id, $modality->id);

            return ['opened' => count($found), 'kept' => $kept, 'resolved' => $resolved];
        });
    }

    /**
     * Dónde queda la hora del último recálculo de la carga de una modalidad: un recálculo que no cambia ningún choque no
     * toca `schedule_conflicts`, y uno de una modalidad sin choques no tiene filas.
     */
    public static function stampKey(string $termId, string $modalityId): string
    {
        return "scheduling:conflicts-synced:{$termId}:modality:{$modalityId}";
    }

    /**
     * La misma llave que Violation::key(), para reconocer un choque guardado.
     */
    private static function keyOf(ScheduleConflict $conflict): string
    {
        return (new Violation($conflict->rule, $conflict->severity, $conflict->message, $conflict->subject_type, $conflict->subject_id, $conflict->group_id, $conflict->related))->key();
    }
}
