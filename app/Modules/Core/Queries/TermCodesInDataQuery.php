<?php

namespace App\Modules\Core\Queries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los códigos de periodo que aparecen en los datos (TP-01): las columnas de periodo de las tablas de Nexus y del
 * esquema crudo `sii_archivo`, por nombre de tabla (Core no depende de los módulos de su derecha). Una tabla o un
 * esquema que no existen se saltan.
 */
final class TermCodesInDataQuery
{
    /** El primer año de un código real: el SII empezó en 1973; antes es basura de captura. */
    public const int FIRST_YEAR = 1973;

    /**
     * @var array<string, list<string>> tabla => columnas con el código del periodo
     */
    private const array SOURCES = [
        'kardex_records' => ['term_code'],
        'historical_groups' => ['term_code'],
        'teacher_subject_history' => ['term_code'],
        'teacher_evaluations' => ['term_code'],
        'sii_archivo.historia_alumno' => ['periodo', 'periodo_acredita_materia'],
        'sii_archivo.grupos' => ['periodo'],
        'sii_archivo.horarios' => ['periodo'],
        'sii_archivo.seleccion_materias' => ['periodo'],
        'sii_archivo.alumnos_todos' => ['periodo_ingreso_it'],
        'sii_archivo.especialidades' => ['periodo_inicio', 'periodo_termino'],
    ];

    /**
     * @return array{valid: list<string>, junk: int} los códigos válidos distintos, ordenados, y cuántos valores
     *                                               distintos no lo son (vacíos, «0», 99999, años anteriores a 1973...)
     */
    public function get(): array
    {
        $values = [];

        foreach (self::SOURCES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                foreach (DB::table($table)->whereNotNull($column)->distinct()->pluck($column) as $value) {
                    $values[trim((string) $value)] = true;
                }
            }
        }

        $valid = [];
        $junk = 0;

        foreach (array_keys($values) as $value) {
            if ($value === '') {
                $junk++;
            } elseif ($this->isValid((string) $value)) {
                $valid[] = (string) $value;
            } else {
                $junk++;
            }
        }

        sort($valid);

        return ['valid' => $valid, 'junk' => $junk];
    }

    private function isValid(string $code): bool
    {
        return preg_match('/^[0-9]{4}[123]$/', $code) === 1 && (int) substr($code, 0, 4) >= self::FIRST_YEAR;
    }
}
