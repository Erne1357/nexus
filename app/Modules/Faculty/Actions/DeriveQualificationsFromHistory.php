<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Enums\QualificationSource;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use Illuminate\Support\Facades\DB;

/**
 * D-02: toda materia que un docente impartió en los últimos 6 semestres (periodos del SII que terminan en 1 o 3,
 * contados hacia atrás desde el periodo dado, inclusive) es «Apto» en su experiencia académica, con `source = history`. «Preferente»
 * sigue siendo manual: nunca toca una fila `manual`, esté viva o quitada. Las derivadas que salieron de la ventana se
 * quitan (borrado lógico) y una derivada quitada que vuelve a entrar se restaura. Idempotente. Materias inactivas o
 * borradas no se derivan. Una sola entrada de bitácora con los conteos.
 */
final class DeriveQualificationsFromHistory
{
    public const WINDOW_SEMESTERS = 6;

    public const NOTE = 'Del historial del SII';

    /**
     * @return array{created: int, removed: int, kept_manual: int}
     */
    public function handle(string $currentTermCode, ?User $actor = null): array
    {
        $terms = $this->window($currentTermCode);

        return DB::transaction(function () use ($terms, $currentTermCode, $actor): array {
            $wanted = [];
            TeacherSubjectHistory::query()
                ->select('teacher_subject_history.teacher_id', 'teacher_subject_history.subject_id')
                ->join('subjects', 'subjects.id', '=', 'teacher_subject_history.subject_id')
                ->whereIn('teacher_subject_history.term_code', $terms)
                ->where('subjects.is_active', true)
                ->whereNull('subjects.deleted_at')
                ->distinct()
                ->get()
                ->each(function ($row) use (&$wanted): void {
                    $wanted[$row->teacher_id.'|'.$row->subject_id] = [$row->teacher_id, $row->subject_id];
                });

            $existing = TeacherSubjectQualification::withTrashed()->get()->keyBy(fn ($q): string => $q->teacher_id.'|'.$q->subject_id);

            $created = $removed = $keptManual = 0;
            $now = now();
            $inserts = [];
            $restore = [];

            foreach ($wanted as $key => [$teacherId, $subjectId]) {
                $row = $existing->get($key);

                if ($row === null) {
                    $inserts[] = [
                        'teacher_id' => $teacherId,
                        'subject_id' => $subjectId,
                        'level' => QualificationLevel::Apto->value,
                        'source' => QualificationSource::History->value,
                        'qualified_by' => $actor?->getKey(),
                        'notes' => self::NOTE,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                } elseif ($row->source === QualificationSource::Manual) {
                    if (! $row->trashed()) {
                        $keptManual++;
                    }
                } elseif ($row->trashed()) {
                    $restore[] = $row->getKey();
                }
            }

            foreach (array_chunk($inserts, 500) as $chunk) {
                TeacherSubjectQualification::query()->insert($chunk);
            }

            if ($restore !== []) {
                TeacherSubjectQualification::onlyTrashed()->whereKey($restore)->update([
                    'deleted_at' => null,
                    'level' => QualificationLevel::Apto->value,
                    'notes' => self::NOTE,
                    'updated_at' => $now,
                ]);
            }

            $created = count($inserts) + count($restore);

            $stale = $existing
                ->filter(fn ($q): bool => ! $q->trashed() && $q->source === QualificationSource::History && ! isset($wanted[$q->teacher_id.'|'.$q->subject_id]))
                ->pluck('id')
                ->all();

            foreach (array_chunk($stale, 500) as $chunk) {
                $removed += TeacherSubjectQualification::query()->whereKey($chunk)->update(['deleted_at' => $now, 'updated_at' => $now]);
            }

            $counts = ['created' => $created, 'removed' => $removed, 'kept_manual' => $keptManual];

            activity()
                ->causedBy($actor)
                ->withProperties($counts + ['term' => $currentTermCode, 'window' => $terms])
                ->log("D-02: experiencia académica derivada del historial al periodo {$currentTermCode}");

            return $counts;
        });
    }

    /**
     * Los 6 semestres del calendario (periodos 1 y 3) hacia atrás desde el dado, inclusive.
     *
     * @return list<string>
     */
    private function window(string $currentTermCode): array
    {
        $year = (int) substr($currentTermCode, 0, 4);
        $kind = (int) substr($currentTermCode, -1);
        $terms = [];

        // El 2 (verano) cuenta como parte del año: se parte del 1 o 3 que lo precede.
        if ($kind === 2) {
            $kind = 1;
        }

        while (count($terms) < self::WINDOW_SEMESTERS) {
            $terms[] = $year.$kind;
            [$year, $kind] = $kind === 3 ? [$year, 1] : [$year - 1, 3];
        }

        return $terms;
    }
}
