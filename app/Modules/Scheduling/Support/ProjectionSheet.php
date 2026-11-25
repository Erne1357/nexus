<?php

namespace App\Modules\Scheduling\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Lee la hoja «formato proyecto docente» de la proyección de un departamento (ImportLoadProjection): el encabezado es la
 * fila que empieza con «PLAN DE ESTUDIOS» y las columnas, por posición: plan, semestre, materia, clave de materia,
 * créditos, clave del grupo, alumnos y hora. El plan solo viene en la primera fila de cada bloque y vale para las que
 * siguen. La hora es una fórmula: se lee su valor guardado; si el archivo no lo trae (una fórmula sin calcular) queda
 * `null` y quien importa usa la letra del grupo.
 *
 * Devuelve filas con su número en el archivo; las filas de bloque sin datos (todo vacío) no salen.
 */
final class ProjectionSheet
{
    /**
     * @return list<array{row: int, relation: bool, fixed: bool, room: ?string, plan: string, semester: mixed, subject: string, code: string, credits: mixed, group: string, students: mixed, hour: ?string, hourIsError: bool}>
     *
     * @throws RuntimeException
     */
    public function rows(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("No se encontró el archivo {$path}.");
        }

        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $rows = [];
        $plan = '';
        $started = false;

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $cells = $row->cells;

                    if (! $started) {
                        $started = mb_strtoupper(trim($this->text($cells[0] ?? null))) === 'PLAN DE ESTUDIOS';

                        continue;
                    }

                    $values = array_map(fn (?Cell $cell) => $cell === null ? null : $this->value($cell), array_slice($cells, 0, 8));

                    if (trim(implode('', array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $values))) === '') {
                        continue;
                    }

                    $block = trim($this->text($cells[0] ?? null));
                    $plan = $block !== '' ? $block : $plan;
                    $hourCell = $cells[7] ?? null;

                    $rows[] = [
                        'row' => $number,
                        'relation' => false,
                        'fixed' => true,
                        'room' => null,
                        'plan' => $plan,
                        'semester' => $values[1] ?? null,
                        'subject' => trim($this->text($cells[2] ?? null)),
                        'code' => trim($this->text($cells[3] ?? null)),
                        'credits' => $values[4] ?? null,
                        'group' => mb_strtoupper(trim($this->text($cells[5] ?? null))),
                        'students' => $values[6] ?? null,
                        'hour' => $this->hour($hourCell),
                        'hourIsError' => $hourCell instanceof ErrorCell || (is_string($values[7] ?? null) && str_starts_with(trim($values[7]), '#')),
                    ];
                }

                break;
            }
        } finally {
            $reader->close();
        }

        if (! $started) {
            throw new RuntimeException('El archivo no tiene el encabezado «PLAN DE ESTUDIOS»: no es el formato de proyecto docente.');
        }

        return $rows;
    }

    private function value(Cell $cell): mixed
    {
        return $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();
    }

    private function text(?Cell $cell): string
    {
        $value = $cell === null ? null : $this->value($cell);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * El texto de la hora; vacío si la celda no trae nada, y `null` si es una fórmula sin valor guardado, o guardado en 0 (la hora sale de la letra del grupo).
     */
    private function hour(?Cell $cell): ?string
    {
        if ($cell instanceof FormulaCell && in_array($cell->getComputedValue(), [null, '', 0, 0.0, '0'], true)) {
            return null;
        }

        return $cell === null ? '' : trim($this->text($cell));
    }
}
