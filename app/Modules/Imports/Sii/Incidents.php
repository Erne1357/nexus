<?php

namespace App\Modules\Imports\Sii;

use RuntimeException;

/**
 * Las incidencias de una corrida, para `_incidencias.csv`: `paso,archivo,fila,campo,nivel,mensaje`. La fila es
 * el número de fila del lote (o la referencia que dio el paso); nunca la fila cruda. El mensaje es el de la
 * plantilla, que puede traer un valor: el archivo vive en storage, fuera de git, junto a la exportación. El nivel es
 * `error`, `warning` o `info` (el resumen de la verificación, que no cuenta como incidencia).
 */
final class Incidents
{
    public const array HEADER = ['paso', 'archivo', 'fila', 'campo', 'nivel', 'mensaje'];

    /** @var list<array{string, string, string, string, string, string}> */
    private array $rows = [];

    public function add(string $step, string $file, string|int $row, string $field, string $level, string $message): void
    {
        $this->rows[] = [$step, $file, (string) $row, $field, $level, $message];
    }

    /**
     * Cuántas incidencias hay, sin las de nivel `info`.
     */
    public function count(): int
    {
        return count(array_filter($this->rows, fn (array $row): bool => $row[4] !== 'info'));
    }

    public function write(string $path): void
    {
        $stream = fopen($path, 'w');

        if ($stream === false) {
            throw new RuntimeException('No se pudo escribir '.basename($path).'.');
        }

        fputcsv($stream, self::HEADER, escape: '');

        foreach ($this->rows as $row) {
            fputcsv($stream, $row, escape: '');
        }

        fclose($stream);
    }
}
