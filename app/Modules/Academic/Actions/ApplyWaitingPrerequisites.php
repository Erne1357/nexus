<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Enums\WaitingPrerequisiteOutcome;
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
 * en su misma transacción y con el plan ya bloqueado para escribir; no la llama una pantalla.
 *
 * Bloquea el plan para escribir, como AddPrerequisite (dos sesiones no cierran un ciclo entre las dos), las filas de la
 * retícula de esas materias para compartir y los requisitos que esperan. Recorre primero los que respetan el orden de
 * los semestres que ya tienen sus materias en la retícula (SeriationRules::goesBackwards()) y luego por orden de alta,
 * como el paso de la migración, así en un ciclo entra el que pide una materia de un semestre anterior (supuesto por
 * confirmar). De cada uno con sus dos materias en la retícula (sin quitar, y la materia sin borrar del catálogo):
 *
 * - si la seriación ya tiene ese requisito (alguien lo capturó en el editor), se queda con su tipo hasta la siguiente
 *   corrida del SII, que lo pisa con el del SII; el que esperaba queda aplicado, sin nota (supuesto por confirmar);
 * - si se quitó de la seriación en Nexus (su fila está borrada), no se restaura: el que esperaba queda aplicado con la
 *   nota «No entró: se quitó de la seriación en Nexus.» (supuesto por confirmar);
 * - si es seriación y cerraría un ciclo con la del plan o con uno que entró antes en esta pasada
 *   (SeriationRules::closesCycle()), no entra: sigue esperando con una nota que lo dice;
 * - si no, entra con su tipo y queda con `applied_at`, sin nota.
 *
 * Cada cambio deja bitácora con quién validó la materia: el alta sobre la seriación y lo demás sobre el que esperaba.
 * Devuelve qué pasó con cada uno que tocó, en ese orden; quien la llama avisa con CurriculumChanged si hace falta.
 */
final class ApplyWaitingPrerequisites
{
    public const string REMOVED_NOTE = 'No entró: se quitó de la seriación en Nexus.';

    public function __construct(private readonly SeriationRules $rules) {}

    /**
     * @return list<WaitingPrerequisiteResult>
     */
    public function handle(StudyPlan $plan, User $user): array
    {
        return DB::transaction(function () use ($plan, $user): array {
            $plan = $this->rules->lockPlan($plan->getKey());
            $waiting = StudyPlanPrerequisitePending::query()
                ->where('study_plan_id', $plan->getKey())
                ->whereNull('applied_at')
                ->with(['subject', 'prerequisite'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($waiting->isEmpty()) {
                return [];
            }

            $semesters = $this->semesters($plan, array_unique([...$waiting->pluck('subject_id'), ...$waiting->pluck('prerequisite_subject_id')]));
            $ready = $waiting
                ->filter(fn (StudyPlanPrerequisitePending $row): bool => isset($semesters[$row->subject_id], $semesters[$row->prerequisite_subject_id]))
                ->sortBy(fn (StudyPlanPrerequisitePending $row): string => (SeriationRules::goesBackwards($row->kind, $semesters[$row->subject_id], $semesters[$row->prerequisite_subject_id]) ? '1' : '0').$row->id);
            $edges = SeriationRules::edgesFor($plan->getKey());
            $results = [];

            foreach ($ready as $row) {
                $existing = SubjectPrerequisite::withTrashed()
                    ->where('study_plan_id', $plan->getKey())
                    ->where('subject_id', $row->subject_id)
                    ->where('prerequisite_subject_id', $row->prerequisite_subject_id)
                    ->lockForUpdate()
                    ->first();

                $results[] = match (true) {
                    $existing !== null && ! $existing->trashed() => $this->alreadyThere($row, $existing, $plan, $user),
                    $existing !== null => $this->removed($row, $plan, $user),
                    $row->kind === PrerequisiteKind::Seriacion && SeriationRules::closesCycle($edges, $row->subject_id, $row->prerequisite_subject_id) => $this->block($row, $plan, $user),
                    default => $this->apply($row, $plan, $user),
                };

                if (end($results)->outcome === WaitingPrerequisiteOutcome::Entered && $row->kind === PrerequisiteKind::Seriacion) {
                    $edges[$row->subject_id][$row->prerequisite_subject_id] = true;
                }
            }

            return $results;
        });
    }

    /**
     * El semestre en la retícula del plan de las materias de `$subjectIds` que están ahí, sin quitar y sin borrar del
     * catálogo, bloqueadas para compartir como en SeriationRules::lockPlacements().
     *
     * @param  list<string>  $subjectIds
     * @return array<string, int>
     */
    private function semesters(StudyPlan $plan, array $subjectIds): array
    {
        return StudyPlanSubject::query()
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->whereNull('subjects.deleted_at')
            ->where('study_plan_subjects.study_plan_id', $plan->getKey())
            ->whereIn('study_plan_subjects.subject_id', $subjectIds)
            ->lock('for share of study_plan_subjects')
            ->pluck('study_plan_subjects.semester', 'study_plan_subjects.subject_id')
            ->map(fn (mixed $semester): int => (int) $semester)
            ->all();
    }

    private function apply(StudyPlanPrerequisitePending $row, StudyPlan $plan, User $user): WaitingPrerequisiteResult
    {
        $prerequisite = SubjectPrerequisite::create([
            'study_plan_id' => $plan->getKey(),
            'subject_id' => $row->subject_id,
            'prerequisite_subject_id' => $row->prerequisite_subject_id,
            'kind' => $row->kind,
        ]);

        $row->forceFill(['applied_at' => now(), 'note' => null])->save();

        activity()
            ->performedOn($prerequisite)
            ->causedBy($user)
            ->event(ActivityEvent::Created)
            ->withChanges(['attributes' => ['subject_id' => $prerequisite->subject_id, 'prerequisite_subject_id' => $prerequisite->prerequisite_subject_id, 'kind' => $row->kind->value]])
            ->withProperties(['waiting_prerequisite_id' => $row->getKey()])
            ->log("Agregó a {$row->subject->code} el requisito {$row->prerequisite->code} ({$this->label($row->kind)}) en el plan {$plan->code}, que esperaba de la migración del SII");

        return new WaitingPrerequisiteResult($row, WaitingPrerequisiteOutcome::Entered, $row->kind);
    }

    /**
     * La seriación ya tiene el requisito: se queda con su tipo hasta la siguiente corrida del SII, y el que esperaba
     * queda aplicado y sin nota.
     */
    private function alreadyThere(StudyPlanPrerequisitePending $row, SubjectPrerequisite $existing, StudyPlan $plan, User $user): WaitingPrerequisiteResult
    {
        $this->settle($row, $user, null, "El requisito {$row->prerequisite->code} de {$row->subject->code} ya estaba en la seriación del plan {$plan->code} como {$this->label($existing->kind)}: se queda así");

        return new WaitingPrerequisiteResult($row, WaitingPrerequisiteOutcome::AlreadyThere, $existing->kind);
    }

    /**
     * Se quitó de la seriación en Nexus: no se restaura, y el que esperaba queda aplicado con la nota que lo dice.
     */
    private function removed(StudyPlanPrerequisitePending $row, StudyPlan $plan, User $user): WaitingPrerequisiteResult
    {
        $this->settle($row, $user, self::REMOVED_NOTE, "El requisito {$row->prerequisite->code} de {$row->subject->code} no entró a la seriación del plan {$plan->code}: se quitó en Nexus");

        return new WaitingPrerequisiteResult($row, WaitingPrerequisiteOutcome::RemovedInNexus, $row->kind);
    }

    /**
     * Deja el requisito esperando con la nota del ciclo; la bitácora, solo si la nota cambió.
     */
    private function block(StudyPlanPrerequisitePending $row, StudyPlan $plan, User $user): WaitingPrerequisiteResult
    {
        $old = $row->note;
        $note = "Cierra un ciclo de seriación: {$row->prerequisite->code} ya pide, en cadena, a {$row->subject->code}. Sigue esperando.";

        if ($old !== $note) {
            $row->forceFill(['note' => $note])->save();

            activity()
                ->performedOn($row)
                ->causedBy($user)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['note' => $note], 'old' => ['note' => $old]])
                ->log("El requisito {$row->prerequisite->code} de {$row->subject->code} no entró a la seriación del plan {$plan->code}: cerraría un ciclo");
        }

        return new WaitingPrerequisiteResult($row, WaitingPrerequisiteOutcome::Cycle, $row->kind);
    }

    /**
     * Marca aplicado el que esperaba, con `$note`, y lo registra en la bitácora.
     */
    private function settle(StudyPlanPrerequisitePending $row, User $user, ?string $note, string $description): void
    {
        $old = ['applied_at' => null, 'note' => $row->note];
        $now = now();
        $row->forceFill(['applied_at' => $now, 'note' => $note])->save();

        activity()
            ->performedOn($row)
            ->causedBy($user)
            ->event(ActivityEvent::Updated)
            ->withChanges(['attributes' => ['applied_at' => $now->toIso8601String(), 'note' => $note], 'old' => $old])
            ->log($description);
    }

    private function label(PrerequisiteKind $kind): string
    {
        return mb_strtolower($kind->label());
    }
}
