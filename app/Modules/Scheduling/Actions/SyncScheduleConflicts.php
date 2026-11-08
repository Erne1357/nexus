<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Events\ScheduleConflictsSynced;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula los choques de la carga de un campus en un periodo (`schedule_conflicts`, tarea 3.2): valida la carga
 * completa y deja la tabla igual a lo que encontró. Un choque que sigue se conserva (con su detalle al día), uno nuevo
 * se agrega y uno que ya no aparece queda resuelto (`resolved_at`), así el tablero distingue lo vigente de lo
 * corregido. Solo guarda los choques de los grupos del campus: el otro lado de un empalme de docente entre campus lo
 * guarda el recálculo de su propio campus.
 *
 * Es una tabla derivada, como `student_progress`: no deja bitácora (la bitácora es de las escrituras de las personas,
 * que ya la dejan). Corre en una transacción, con la carga bloqueada por un candado de transacción por periodo y
 * campus para que dos recálculos no se encimen, y avisa con ScheduleConflictsSynced después del commit.
 */
final class SyncScheduleConflicts
{
    public function __construct(private readonly ScheduleValidator $validator) {}

    /**
     * @return array{opened: int, kept: int, resolved: int}
     */
    public function handle(Term $term, Campus $campus): array
    {
        return DB::transaction(function () use ($term, $campus): array {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["schedule_conflicts:{$term->id}:{$campus->id}"]);

            $this->validator->forget();
            $violations = $this->validator->validateLoad($term, $campus)->violations();
            $groups = Group::query()->where('term_id', $term->id)->where('campus_id', $campus->id)
                ->get(['id', 'department_id', 'owner_career_id'])->keyBy('id');

            $found = [];

            foreach ($violations as $violation) {
                if ($violation->groupId === null || ! $groups->has($violation->groupId)) {
                    continue;
                }

                $found[$violation->key()] = $violation;
            }

            $open = ScheduleConflict::query()->open()->where('term_id', $term->id)->where('campus_id', $campus->id)
                ->lockForUpdate()->get();

            $kept = 0;
            $resolved = 0;

            foreach ($open as $conflict) {
                $key = self::keyOf($conflict);

                if (isset($found[$key])) {
                    $conflict->update(['related' => $found[$key]->related, 'severity' => $found[$key]->severity]);
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
                    'campus_id' => $campus->id,
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

            ScheduleConflictsSynced::dispatch($term->id, $campus->id);

            return ['opened' => count($found), 'kept' => $kept, 'resolved' => $resolved];
        });
    }

    /**
     * La misma llave que Violation::key(), para reconocer un choque guardado.
     */
    private static function keyOf(ScheduleConflict $conflict): string
    {
        return (new Violation($conflict->rule, $conflict->severity, $conflict->message, $conflict->subject_type, $conflict->subject_id, $conflict->group_id, $conflict->related))->key();
    }
}
