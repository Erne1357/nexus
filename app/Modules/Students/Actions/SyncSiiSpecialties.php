<?php

namespace App\Modules\Students\Actions;

use App\Modules\Academic\Models\Specialty;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Guarda en `students.specialty_id` la especialidad que el SII le asignó a cada alumno ya importado (1.69). La
 * especialidad se busca por su clave dentro del plan del alumno; una que no existe en ese plan deja al alumno como
 * estaba y se cuenta (por plan y clave, sin números de control). Un número de control que no es de ningún alumno de
 * Nexus también se cuenta. Idempotente. Con `$dryRun` solo cuenta. Deja una sola entrada de bitácora con los totales
 * (son decenas de miles de alumnos). No recalcula el avance: lo hace el job de la importación o el siguiente
 * recálculo.
 */
final class SyncSiiSpecialties
{
    private const int CHUNK = 1000;

    /**
     * @param  iterable<array{control: string, code: string}>  $assigned  número de control y clave de la especialidad del SII
     * @return array{set: int, unchanged: int, unmatched: array<string, int>, unknownStudents: int}
     */
    public function handle(iterable $assigned, bool $dryRun = false): array
    {
        $specialtyOf = Specialty::withTrashed()->get(['id', 'study_plan_id', 'code'])
            ->mapWithKeys(fn (Specialty $specialty): array => [$specialty->study_plan_id.'|'.$specialty->code => $specialty->id])->all();
        $planCodes = DB::table('study_plans')->pluck('code', 'id')->all();

        $result = ['set' => 0, 'unchanged' => 0, 'unmatched' => [], 'unknownStudents' => 0];

        foreach (array_chunk(is_array($assigned) ? $assigned : iterator_to_array($assigned, false), self::CHUNK) as $chunk) {
            $codes = array_column($chunk, 'code', 'control');
            $students = Student::query()->whereIn('control_number', array_keys($codes))->get(['id', 'control_number', 'study_plan_id', 'specialty_id'])->keyBy('control_number');
            $result['unknownStudents'] += count($codes) - $students->count();

            foreach ($students as $control => $student) {
                $code = trim((string) $codes[$control]);
                $specialty = $specialtyOf[$student->study_plan_id.'|'.$code] ?? null;

                if ($specialty === null) {
                    $label = ($planCodes[$student->study_plan_id] ?? '?').'/'.$code;
                    $result['unmatched'][$label] = ($result['unmatched'][$label] ?? 0) + 1;

                    continue;
                }

                if ($student->specialty_id === $specialty) {
                    $result['unchanged']++;

                    continue;
                }

                $result['set']++;

                if (! $dryRun) {
                    Student::query()->whereKey($student->getKey())->update(['specialty_id' => $specialty]);
                }
            }
        }

        ksort($result['unmatched']);

        if (! $dryRun && $result['set'] > 0) {
            activity()
                ->event(ActivityEvent::Updated)
                ->withProperties(['set' => $result['set'], 'unchanged' => $result['unchanged'], 'unmatched' => array_sum($result['unmatched']), 'unknown_students' => $result['unknownStudents']])
                ->log("Sincronizó la especialidad del SII de {$result['set']} alumno(s)");
        }

        return $result;
    }
}
