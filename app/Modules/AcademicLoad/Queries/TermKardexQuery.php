<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * El kárdex contable de un periodo para la evaluación de la proyección (tarea 5.9b; DM-28): las filas aprobadas,
 * reprobadas o cursando (DM-02, la baja no cuenta) de alumnos sin borrar, por la modalidad y el campus de hoy del
 * alumno, igual que TermOccupancyQuery.
 */
final class TermKardexQuery
{
    private const array COUNTED = [KardexResult::Aprobada, KardexResult::Reprobada, KardexResult::Cursando];

    /**
     * Si hay con qué comparar: alguna fila contable del periodo en la modalidad (y el campus).
     */
    public function exists(string $termCode, Modality $modality, ?Campus $campus = null): bool
    {
        return $this->counted($termCode, $modality, $campus)->exists();
    }

    /**
     * Las modalidades activas y sin borrar con kárdex contable del periodo, por clave.
     *
     * @return Collection<int, Modality>
     */
    public function activeModalities(string $termCode): Collection
    {
        return Modality::query()
            ->where('is_active', true)
            ->whereIn('id', $this->counted($termCode)->select('students.modality_id'))
            ->orderBy('code')
            ->get();
    }

    /**
     * Los códigos de los periodos ordinarios (no verano) con kárdex contable en la modalidad, del más reciente al más
     * antiguo.
     *
     * @return list<string>
     */
    public function termCodes(Modality $modality): array
    {
        return $this->counted(null, $modality)
            ->toBase()
            ->distinct()
            ->pluck('kardex_records.term_code')
            ->filter(fn (string $code): bool => preg_match('/^\d{4}[13]$/', $code) === 1)
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * Cuántos alumnos distintos tienen kárdex contable del periodo, por su carrera y su campus de hoy.
     *
     * @return list<array{career_id: string, campus_id: string, students: int}>
     */
    public function studentsByCareerAndCampus(string $termCode, Modality $modality, ?Campus $campus = null): array
    {
        return $this->counted($termCode, $modality, $campus)
            ->groupBy('students.career_id', 'students.campus_id')
            ->toBase()
            ->selectRaw('students.career_id, students.campus_id, count(distinct kardex_records.student_id) as students')
            ->get()
            ->map(fn (object $row): array => ['career_id' => $row->career_id, 'campus_id' => $row->campus_id, 'students' => (int) $row->students])
            ->all();
    }

    /**
     * @return Builder<KardexRecord>
     */
    private function counted(?string $termCode, ?Modality $modality = null, ?Campus $campus = null): Builder
    {
        return KardexRecord::query()
            ->join('students', 'students.id', '=', 'kardex_records.student_id')
            ->whereNull('students.deleted_at')
            ->when($termCode !== null, fn (Builder $query) => $query->where('kardex_records.term_code', $termCode))
            ->whereIn('kardex_records.result', self::COUNTED)
            ->when($modality !== null, fn (Builder $query) => $query->where('students.modality_id', $modality->getKey()))
            ->when($campus !== null, fn (Builder $query) => $query->where('students.campus_id', $campus->getKey()));
    }
}
