<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Faculty\Enums\HistorySource;
use App\Modules\Faculty\Models\TeacherSubjectHistory;

/**
 * Mantiene `teacher_subject_history` al día con la versión de la carga que se publica. Corre dentro de la
 * transacción de quien publica, que también escribe la bitácora; por eso aquí no hay ninguna de las dos.
 *
 * Reglas:
 * - Cada fila de `$taught` queda presente y sin borrar: se inserta con origen `nexus` si no existe esa clave;
 *   si existe borrada, se restaura conservando su origen; si existe activa (también una `import`), no se toca.
 * - Cada clave de `$previous` que ya no está en `$taught` se borra de forma lógica, pero solo si su origen es
 *   `nexus`: el historial importado nunca se toca.
 * - Las claves de otros periodos, otros campus o no mencionadas no se tocan.
 * - Las claves repetidas en `$taught` se insertan una sola vez.
 *
 * Usa un número constante de consultas: una lectura, una inserción, una restauración y un borrado en bloque.
 */
final class SyncPublishedTeaching
{
    /**
     * @param  list<array{teacher_id: string, subject_id: string, group_code: string}>  $taught  lo que dice la versión que se publica
     * @param  list<array{teacher_id: string, subject_id: string, group_code: string}>  $previous  lo que decía la versión anterior de la misma carga (vacío en la primera)
     * @param  ?string  $campusId  el campus de esos grupos; null en una modalidad sin salones (la carga es por modalidad y
     *                             quien publica llama una vez por campus de sus grupos)
     */
    public function handle(string $termCode, ?string $campusId, array $taught, array $previous): void
    {
        if ($taught === [] && $previous === []) {
            return;
        }

        $wanted = [];
        foreach ($taught as $row) {
            $wanted[$this->key($row)] = $row;
        }

        $removed = [];
        foreach ($previous as $row) {
            $key = $this->key($row);

            if (! isset($wanted[$key])) {
                $removed[$key] = $row;
            }
        }

        $teacherIds = array_values(array_unique(array_column([...$wanted, ...$removed], 'teacher_id')));

        $existing = TeacherSubjectHistory::withTrashed()
            ->where('term_code', $termCode)
            ->where('campus_id', $campusId)
            ->whereIn('teacher_id', $teacherIds)
            ->get()
            ->keyBy(fn (TeacherSubjectHistory $history): string => $this->key($history->only(['teacher_id', 'subject_id', 'group_code'])));

        $now = now();
        $toInsert = [];
        $toRestore = [];

        foreach ($wanted as $key => $row) {
            $found = $existing->get($key);

            if ($found === null) {
                $toInsert[] = [
                    'teacher_id' => $row['teacher_id'],
                    'subject_id' => $row['subject_id'],
                    'term_code' => $termCode,
                    'campus_id' => $campusId,
                    'group_code' => $row['group_code'],
                    'source' => HistorySource::Nexus->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            } elseif ($found->trashed()) {
                $toRestore[] = $found->getKey();
            }
        }

        $toDelete = [];
        foreach ($removed as $key => $row) {
            $found = $existing->get($key);

            if ($found !== null && ! $found->trashed() && $found->source === HistorySource::Nexus) {
                $toDelete[] = $found->getKey();
            }
        }

        if ($toInsert !== []) {
            TeacherSubjectHistory::query()->insert($toInsert);
        }

        if ($toRestore !== []) {
            TeacherSubjectHistory::withTrashed()->whereIn('id', $toRestore)->update(['deleted_at' => null, 'updated_at' => $now]);
        }

        if ($toDelete !== []) {
            TeacherSubjectHistory::query()->whereIn('id', $toDelete)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function key(array $row): string
    {
        return $row['teacher_id'].'|'.$row['subject_id'].'|'.$row['group_code'];
    }
}
