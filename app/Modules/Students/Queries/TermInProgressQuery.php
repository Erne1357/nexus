<?php

namespace App\Modules\Students\Queries;

use App\Modules\Students\Models\KardexRecord;
use Illuminate\Support\Facades\Cache;

/**
 * El periodo en curso de cada campus para la retícula del alumno: el más reciente del kardex de sus alumnos, sin
 * contar verano, que solo cursan algunos (reglas-de-negocio.md, "Retícula del alumno"). El periodo de captura
 * (`terms.is_current`) no sirve: es el que se planea, no el que se cursa. Se calcula una vez por importación,
 * al recalcular el avance, para que abrir una ficha no recorra todo el kardex.
 */
final class TermInProgressQuery
{
    private const string CACHE_KEY = 'students.terms-in-progress';

    public function forCampus(string $campusId): ?string
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->compute())[$campusId] ?? null;
    }

    /**
     * Vuelve a calcularlo para todos los campus; lo llama RefreshStudentProgress al terminar.
     */
    public function refresh(): void
    {
        Cache::forever(self::CACHE_KEY, $this->compute());
    }

    /**
     * @return array<string, string> id de campus → código del periodo
     */
    private function compute(): array
    {
        return KardexRecord::query()
            ->join('students', 'students.id', '=', 'kardex_records.student_id')
            ->whereNull('students.deleted_at')
            ->where('kardex_records.term_code', 'not like', '____2')
            ->groupBy('students.campus_id')
            ->toBase()
            ->selectRaw('students.campus_id, max(kardex_records.term_code) as term_code')
            ->pluck('term_code', 'campus_id')
            ->all();
    }
}
