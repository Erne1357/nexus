<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanPrerequisitePending;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Academic\Rules\SeriationRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pasa a la seriación del plan (`subject_prerequisites`) los requisitos del SII que esperaban y cuyas dos materias ya
 * están en la retícula (decisión del usuario del 10 de noviembre de 2026, confirmada: entran solos cuando Servicios
 * Escolares le asigna semestre a la materia, y a la otra si también esperaba). La llama ResolvePendingPlacement al final,
 * en su misma transacción; no la llama una pantalla.
 *
 * Bloquea el plan para escribir, como AddPrerequisite (dos sesiones no cierran un ciclo entre las dos), las filas de la
 * retícula de esas materias para compartir y los requisitos que esperan. Los recorre en el orden en que se guardaron y,
 * de cada uno con sus dos materias en la retícula (sin quitar, y la materia sin borrar del catálogo):
 *
 * - si la seriación ya tiene ese requisito (alguien lo capturó en el editor), se queda como está y el que esperaba
 *   queda aplicado (supuesto por confirmar);
 * - si es seriación y cerraría un ciclo con la del plan o con uno que entró antes en esta pasada
 *   (SeriationRules::closesCycle()), no entra: sigue esperando con una nota que lo dice;
 * - si no, entra con su tipo (restaura la fila si se había quitado, como AddPrerequisite), queda con `applied_at` y
 *   deja bitácora sobre la seriación con quién validó la materia.
 *
 * Devuelve cuántos requisitos nuevos entraron; quien la llama avisa con CurriculumChanged si hace falta.
 */
final class ApplyWaitingPrerequisites
{
    public function __construct(private readonly SeriationRules $rules) {}

    public function handle(StudyPlan $plan, User $user): int
    {
        return DB::transaction(function () use ($plan, $user): int {
            $plan = $this->rules->lockPlan($plan->getKey());
            $waiting = StudyPlanPrerequisitePending::query()
                ->where('study_plan_id', $plan->getKey())
                ->whereNull('applied_at')
                ->with(['subject', 'prerequisite'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($waiting->isEmpty()) {
                return 0;
            }

            $placed = $this->placed($plan, array_unique([...$waiting->pluck('subject_id'), ...$waiting->pluck('prerequisite_subject_id')]));
            $edges = SeriationRules::edgesFor($plan->getKey());
            $applied = 0;

            foreach ($waiting as $row) {
                if (! isset($placed[$row->subject_id], $placed[$row->prerequisite_subject_id])) {
                    continue;
                }

                $existing = SubjectPrerequisite::withTrashed()
                    ->where('study_plan_id', $plan->getKey())
                    ->where('subject_id', $row->subject_id)
                    ->where('prerequisite_subject_id', $row->prerequisite_subject_id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null && ! $existing->trashed()) {
                    $this->alreadyThere($row, $existing, $plan, $user);

                    continue;
                }

                $seriation = $row->kind === PrerequisiteKind::Seriacion;

                if ($seriation && SeriationRules::closesCycle($edges, $row->subject_id, $row->prerequisite_subject_id)) {
                    $this->block($row, $plan, $user);

                    continue;
                }

                $this->apply($row, $existing, $plan, $user);
                $applied++;

                if ($seriation) {
                    $edges[$row->subject_id][$row->prerequisite_subject_id] = true;
                }
            }

            return $applied;
        });
    }

    /**
     * Las materias de `$subjectIds` que están en la retícula del plan, sin quitar y sin borrar del catálogo, bloqueadas
     * para compartir como en SeriationRules::lockPlacements().
     *
     * @param  list<string>  $subjectIds
     * @return array<string, true>
     */
    private function placed(StudyPlan $plan, array $subjectIds): array
    {
        $ids = StudyPlanSubject::query()
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->whereNull('subjects.deleted_at')
            ->where('study_plan_subjects.study_plan_id', $plan->getKey())
            ->whereIn('study_plan_subjects.subject_id', $subjectIds)
            ->lock('for share of study_plan_subjects')
            ->pluck('study_plan_subjects.subject_id')
            ->all();

        return array_fill_keys($ids, true);
    }

    private function apply(StudyPlanPrerequisitePending $row, ?SubjectPrerequisite $existing, StudyPlan $plan, User $user): void
    {
        $label = mb_strtolower($row->kind->label());
        $description = "{$row->subject->code} el requisito {$row->prerequisite->code} ({$label}) en el plan {$plan->code}, que esperaba de la migración del SII";

        if ($existing === null) {
            $prerequisite = SubjectPrerequisite::create([
                'study_plan_id' => $plan->getKey(),
                'subject_id' => $row->subject_id,
                'prerequisite_subject_id' => $row->prerequisite_subject_id,
                'kind' => $row->kind,
            ]);
            $event = ActivityEvent::Created;
            $changes = ['attributes' => ['subject_id' => $prerequisite->subject_id, 'prerequisite_subject_id' => $prerequisite->prerequisite_subject_id, 'kind' => $row->kind->value]];
            $description = "Agregó a {$description}";
        } else {
            $old = $existing->kind;
            $existing->kind = $row->kind;
            $existing->restore();
            $prerequisite = $existing;
            $event = ActivityEvent::Restored;
            $changes = ['attributes' => ['kind' => $row->kind->value], 'old' => ['kind' => $old->value]];
            $description = "Volvió a agregar a {$description}";
        }

        $row->forceFill(['applied_at' => now(), 'note' => null])->save();

        activity()
            ->performedOn($prerequisite)
            ->causedBy($user)
            ->event($event)
            ->withChanges($changes)
            ->withProperties(['waiting_prerequisite_id' => $row->getKey()])
            ->log($description);
    }

    /**
     * La seriación ya tiene el requisito: queda como está, y el que esperaba, aplicado, con su bitácora.
     */
    private function alreadyThere(StudyPlanPrerequisitePending $row, SubjectPrerequisite $existing, StudyPlan $plan, User $user): void
    {
        $now = now();
        $row->forceFill(['applied_at' => $now])->save();

        activity()
            ->performedOn($row)
            ->causedBy($user)
            ->event(ActivityEvent::Updated)
            ->withChanges(['attributes' => ['applied_at' => $now->toIso8601String()], 'old' => ['applied_at' => null]])
            ->log("El requisito {$row->prerequisite->code} de {$row->subject->code} ya estaba en la seriación del plan {$plan->code} como ".mb_strtolower($existing->kind->label()).': se queda así');
    }

    /**
     * Deja el requisito esperando con la nota del ciclo y lo registra en la bitácora.
     */
    private function block(StudyPlanPrerequisitePending $row, StudyPlan $plan, User $user): void
    {
        $old = $row->note;
        $note = "Cierra un ciclo de seriación: {$row->prerequisite->code} ya pide, en cadena, a {$row->subject->code}. Sigue esperando.";

        if ($old === $note) {
            return;
        }

        $row->forceFill(['note' => $note])->save();

        activity()
            ->performedOn($row)
            ->causedBy($user)
            ->event(ActivityEvent::Updated)
            ->withChanges(['attributes' => ['note' => $note], 'old' => ['note' => $old]])
            ->log("El requisito {$row->prerequisite->code} de {$row->subject->code} no entró a la seriación del plan {$plan->code}: cerraría un ciclo");
    }
}
